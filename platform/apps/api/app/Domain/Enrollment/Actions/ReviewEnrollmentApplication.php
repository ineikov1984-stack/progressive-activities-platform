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
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ReviewEnrollmentApplication
{
    public function __construct(private readonly EnrollmentCapacity $capacity, private readonly VisitOfferEligibility $eligibility, private readonly SessionAllocation $allocation) {}

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

            if ($decision === 'waitlist') {
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
            }
            $remaining = $offer !== null
                ? $this->capacity->remainingForOffer($offer, $current->session, forAllocation: true)
                : ($current->scope === EnrollmentScope::FullPeriod
                ? $this->capacity->remainingForGroup($group, forAllocation: true)
                : $this->capacity->remainingForSession($current->session()->firstOrFail(), forAllocation: true));

            if ($remaining !== null && $remaining < 1) {
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

            $reviewed = $this->transitionApplication(
                $actor, $current, EnrollmentApplicationStatus::Approved,
                $publicReason, $internalNote, 'enrollment.application_approved', false, $eligibilityOverrideReason,
            );
            Enrollment::query()->create([
                'application_id' => $reviewed->id,
                'child_id' => $reviewed->child_id,
                'group_id' => $reviewed->group_id,
                'price_option_id' => $reviewed->price_option_id,
                'session_id' => $reviewed->session_id,
                'scope' => $reviewed->scope,
                'status' => EnrollmentStatus::Active,
                'starts_on' => $reviewed->session?->starts_at->setTimezone($group->schedule_pattern['timezone'] ?? 'Europe/Sofia')->toDateString() ?? now()->toDateString(),
            ]);
            $this->event('enrollment.application_approved', $reviewed, null);

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
        $fromStatus = $application->status->value;
        $application->update([
            'status' => $status,
            'public_reason' => $publicReason,
            'internal_note' => $internalNote,
            'version' => $application->version + 1,
        ]);
        EnrollmentTransition::query()->create([
            'application_id' => $application->id,
            'actor_user_id' => $actor->id,
            'from_status' => $fromStatus,
            'to_status' => $status->value,
            'public_reason' => $publicReason,
            'internal_note' => $internalNote,
            'metadata' => $eligibilityOverrideReason === null ? null : ['eligibility_override_reason' => trim($eligibilityOverrideReason)],
        ]);
        if ($recordEvent) {
            $this->event($eventType, $application, null);
        }

        return $application->refresh();
    }

    private function event(string $type, EnrollmentApplication $application, ?Enrollment $enrollment): void
    {
        EnrollmentEvent::query()->create([
            'type' => $type,
            'application_id' => $application->id,
            'enrollment_id' => $enrollment?->id,
            'payload' => ['status' => $application->status->value],
            'occurred_at' => now(),
        ]);
    }
}
