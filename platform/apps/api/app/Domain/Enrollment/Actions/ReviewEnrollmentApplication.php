<?php

namespace App\Domain\Enrollment\Actions;

use App\Domain\Catalog\Models\Group;
use App\Domain\Catalog\Models\Session;
use App\Domain\Catalog\Models\VisitOffer;
use App\Domain\Catalog\Services\VisitOfferEligibility;
use App\Domain\Enrollment\Enums\EnrollmentApplicationStatus;
use App\Domain\Enrollment\Enums\EnrollmentScope;
use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Models\Child;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\EnrollmentApplication;
use App\Domain\Enrollment\Models\EnrollmentEvent;
use App\Domain\Enrollment\Models\EnrollmentTransition;
use App\Domain\Enrollment\Services\EnrollmentCapacity;
use App\Domain\Enrollment\Services\SessionAllocation;
use App\Domain\Enrollment\Services\SessionApplicability;
use App\Domain\Enrollment\Services\VisitBooking;
use App\Domain\Identity\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ReviewEnrollmentApplication
{
    public function __construct(
        private readonly EnrollmentCapacity $capacity,
        private readonly VisitOfferEligibility $eligibility,
        private readonly SessionAllocation $allocation,
        private readonly SessionApplicability $applicability,
        private readonly VisitBooking $visits,
    ) {}

    public function handle(
        User $actor,
        EnrollmentApplication $application,
        string $decision,
        int $expectedVersion,
        ?string $publicReason,
        ?string $internalNote,
        ?string $eligibilityOverrideReason = null,
    ): EnrollmentApplication {
        return DB::transaction(function () use (
            $actor, $application, $decision, $expectedVersion, $publicReason, $internalNote, $eligibilityOverrideReason,
        ): EnrollmentApplication {
            Child::query()->whereKey($application->child_id)->lockForUpdate()->firstOrFail();
            $group = Group::query()->whereKey($application->group_id)->lockForUpdate()->firstOrFail();
            $offer = $application->visit_offer_id === null ? null : VisitOffer::query()->whereKey($application->visit_offer_id)->lockForUpdate()->firstOrFail();
            if ($application->session_id !== null) {
                Session::query()->whereKey($application->session_id)->lockForUpdate()->firstOrFail();
            }
            $current = EnrollmentApplication::query()->lockForUpdate()->findOrFail($application->id);

            if ($current->status === EnrollmentApplicationStatus::Waitlisted && $decision === 'waitlist') {
                throw ValidationException::withMessages(['decision' => 'Заявката вече е в списъка на чакащите.']);
            }
            // Waiting applications can still be approved or rejected when a place frees up.
            $reviewable = in_array($current->status, [EnrollmentApplicationStatus::Submitted, EnrollmentApplicationStatus::Waitlisted], true);
            if (! $reviewable || $current->version !== $expectedVersion) {
                throw ValidationException::withMessages([
                    'version' => 'Заявлението е променено междувременно или вече е обработено.',
                ]);
            }

            // A trial or a single visit from a price: a date with a free place or none (docs/product/visits.md, т. 2.7).
            $visitFromPrice = $offer === null && $current->scope !== EnrollmentScope::FullPeriod;
            if ($decision === 'waitlist') {
                if ($visitFromPrice) {
                    throw ValidationException::withMessages(['decision' => 'За посещение няма списък на чакащите. Одобрете или откажете заявката.']);
                }
                if (! $group->waitlist_enabled || ($offer !== null && ! $offer->waitlist_enabled)) {
                    throw ValidationException::withMessages(['decision' => 'Списъкът с чакащи за тази група е изключен.']);
                }

                return $this->transitionApplication(
                    $actor, $current, EnrollmentApplicationStatus::Waitlisted,
                    $publicReason, $internalNote, 'enrollment.application_waitlisted',
                );
            }

            if ($decision === 'reject') {
                if (trim((string) $publicReason) === '') {
                    throw ValidationException::withMessages(['public_reason' => 'Посочете обяснение за родителя.']);
                }

                return $this->transitionApplication(
                    $actor, $current, EnrollmentApplicationStatus::Rejected,
                    $publicReason, $internalNote, 'enrollment.application_rejected',
                );
            }

            if ($decision !== 'approve') {
                throw ValidationException::withMessages(['decision' => 'Невалидно решение.']);
            }

            if ($current->session !== null) {
                $this->allocation->assertAvailable($current->child, $current->session, $current->id);
            } elseif ($current->scope === EnrollmentScope::FullPeriod) {
                foreach (Session::query()->where('group_id', $group->id)->where('status', 'scheduled')
                    ->where('starts_at', '>', now())->orderBy('id')->get() as $session) {
                    $this->allocation->assertAvailable($current->child, $session, $current->id);
                }
            }

            if ($offer !== null) {
                $acceptedType = $current->transitions()->whereNotNull('metadata->visit_offer_type')->first()?->metadata['visit_offer_type']
                    ?? ($current->scope === EnrollmentScope::Trial ? 'trial' : null);
                // Legacy trial scope is unambiguous; legacy single/open-day requests need resubmission.
                if ($acceptedType !== $offer->type->value) {
                    throw ValidationException::withMessages(['visit_offer_id' => 'Видът на предложението е променен. Подайте нова заявка.']);
                }
                if ($current->session === null || now()->greaterThanOrEqualTo($current->session->starts_at)) {
                    throw ValidationException::withMessages(['session_id' => 'Избраната дата вече е започнала.']);
                }
                $this->eligibility->assertEligible($current->child, $offer, $current->session, $actor, $eligibilityOverrideReason);
                if ($eligibilityOverrideReason !== null) {
                    $this->eligibility->assertOverride($actor, $eligibilityOverrideReason);
                }
            } elseif ($visitFromPrice) {
                if ($current->session === null || now()->greaterThanOrEqualTo($current->session->starts_at)) {
                    throw ValidationException::withMessages(['session_id' => 'Избраната дата вече е започнала.']);
                }
                if ($current->scope === EnrollmentScope::Trial) {
                    $this->visits->assertTrialOnce($current->child, $group, $current->session, $current->id);
                }
            }
            $remaining = $offer !== null
                ? $this->capacity->remainingForOffer($offer, $current->session, forAllocation: true)
                : ($current->scope === EnrollmentScope::FullPeriod
                ? $this->capacity->remainingForGroup($group, forAllocation: true)
                : $this->capacity->remainingForVisit($current->session()->firstOrFail(), forAllocation: true));

            if ($remaining !== null && $remaining < 1) {
                if ($visitFromPrice) {
                    throw ValidationException::withMessages(['capacity' => 'Няма свободни места на тази дата. Откажете заявката с обяснение за родителя.']);
                }
                if ($current->status === EnrollmentApplicationStatus::Waitlisted) {
                    throw ValidationException::withMessages(['capacity' => 'Все още няма свободно място в групата.']);
                }
                if (! $group->waitlist_enabled || ($offer !== null && ! $offer->waitlist_enabled)) {
                    throw ValidationException::withMessages(['capacity' => 'Няма свободни места и списъкът с чакащи е изключен.']);
                }

                return $this->transitionApplication(
                    $actor, $current, EnrollmentApplicationStatus::Waitlisted,
                    null, $internalNote, 'enrollment.application_waitlisted', true, $eligibilityOverrideReason,
                );
            }

            $transition = $this->recordTransition(
                $actor, $current, EnrollmentApplicationStatus::Approved, $publicReason, $internalNote, $eligibilityOverrideReason,
            );
            $reviewed = $current->refresh();
            $timezone = is_string($group->schedule_pattern['timezone'] ?? null) && $group->schedule_pattern['timezone'] !== ''
                ? $group->schedule_pattern['timezone'] : 'Europe/Sofia';
            $enrollment = Enrollment::query()->create([
                'application_id' => $reviewed->id,
                'child_id' => $reviewed->child_id,
                'group_id' => $reviewed->group_id,
                'price_option_id' => $reviewed->price_option_id,
                'session_id' => $reviewed->session_id,
                'scope' => $reviewed->scope,
                'status' => EnrollmentStatus::Active,
                // Calendar day in the group's time zone (docs/product/staff-enrollment.md, т. 9.1): a visit on its
                // session's day; the period from the group's first session that has not started (decision of
                // 30.10.2026), so ending it before then is a cancellation. Without sessions yet — today.
                'starts_on' => $reviewed->session !== null
                    ? $reviewed->session->starts_at->setTimezone($timezone)->toDateString()
                    : ($this->applicability->firstSessionDay($group, CarbonImmutable::now($timezone)->toDateString()) ?? CarbonImmutable::now($timezone)->toDateString()),
            ]);
            $this->event('enrollment.application_approved', $reviewed, $enrollment, $transition);

            return $reviewed;
        });
    }

    private function transitionApplication(
        User $actor,
        EnrollmentApplication $application,
        EnrollmentApplicationStatus $status,
        ?string $publicReason,
        ?string $internalNote,
        string $eventType,
        bool $recordEvent = true,
        ?string $eligibilityOverrideReason = null,
    ): EnrollmentApplication {
        $transition = $this->recordTransition($actor, $application, $status, $publicReason, $internalNote, $eligibilityOverrideReason);
        if ($recordEvent) {
            $this->event($eventType, $application, null, $transition);
        }

        return $application->refresh();
    }

    /** The application's new status and its immutable history row (the source of the text a parent is told). */
    private function recordTransition(
        User $actor,
        EnrollmentApplication $application,
        EnrollmentApplicationStatus $status,
        ?string $publicReason,
        ?string $internalNote,
        ?string $eligibilityOverrideReason,
    ): EnrollmentTransition {
        $fromStatus = $application->status->value;
        $application->update([
            'status' => $status,
            'public_reason' => $publicReason,
            'internal_note' => $internalNote,
            'version' => $application->version + 1,
        ]);

        return EnrollmentTransition::query()->create([
            'application_id' => $application->id,
            'actor_user_id' => $actor->id,
            'from_status' => $fromStatus,
            'to_status' => $status->value,
            'public_reason' => $publicReason,
            'internal_note' => $internalNote,
            'metadata' => $eligibilityOverrideReason === null ? null : ['eligibility_override_reason' => trim($eligibilityOverrideReason)],
        ]);
    }

    /** The event carries the transition, so an email later quotes the reason as it was given (docs/product/enrollment-contacts-and-emails.md, т. 6.2). */
    private function event(string $type, EnrollmentApplication $application, ?Enrollment $enrollment, EnrollmentTransition $transition): void
    {
        EnrollmentEvent::query()->create([
            'type' => $type,
            'application_id' => $application->id,
            'enrollment_id' => $enrollment?->id,
            'payload' => ['status' => $application->status->value, 'transition_id' => $transition->id],
            'occurred_at' => now(),
        ]);
    }
}
