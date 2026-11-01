<?php

namespace App\Domain\Enrollment\Actions;

use App\Domain\Catalog\Models\Group;
use App\Domain\Catalog\Models\Session;
use App\Domain\Catalog\Services\AccessScope;
use App\Domain\Enrollment\Enums\EnrollmentScope;
use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Models\Child;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\EnrollmentEvent;
use App\Domain\Enrollment\Models\EnrollmentTransition;
use App\Domain\Identity\Models\User;
use App\Domain\PaymentTerms\Enums\BillingKind;
use App\Domain\PaymentTerms\Services\AppliedTerms;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RecordVisitOutcome
{
    public function __construct(private readonly AccessScope $scope) {}

    public function handle(User $actor, Enrollment $enrollment, string $outcome, ?string $reason): EnrollmentTransition
    {
        return DB::transaction(function () use ($actor, $enrollment, $outcome, $reason): EnrollmentTransition {
            Child::query()->whereKey($enrollment->child_id)->lockForUpdate()->firstOrFail();
            Group::query()->whereKey($enrollment->group_id)->lockForUpdate()->firstOrFail();
            $session = Session::query()->whereKey($enrollment->session_id)->lockForUpdate()->firstOrFail();
            $current = Enrollment::query()->lockForUpdate()->findOrFail($enrollment->id);
            $application = $current->application;
            $staff = $actor->is_active && $this->recordsVisits($actor, $current->group_id);
            $cancellation = in_array($outcome, ['cancelled_in_time', 'late_cancelled'], true);
            abort_unless($staff || ($cancellation && $actor->is_active && $application->parent_user_id === $actor->id), 403);
            if ($current->scope === EnrollmentScope::FullPeriod
                || ! in_array($outcome, ['attended', 'cancelled_in_time', 'late_cancelled', 'no_show'], true)) {
                throw ValidationException::withMessages(['outcome' => 'Невалиден резултат за посещението.']);
            }
            if ($staff && ! $cancellation) {
                throw ValidationException::withMessages(['outcome' => 'Дали детето е дошло, се отбелязва в „Присъствия“.']);
            }
            if ($staff && now()->greaterThanOrEqualTo($session->starts_at)) {
                throw ValidationException::withMessages(['outcome' => 'Посещението вече е започнало — отбележете в „Присъствия“, че детето не е дошло.']);
            }
            $existing = $current->transitions()->whereNotNull('metadata->outcome')->first();
            if ($existing !== null) {
                if ($existing->metadata['outcome'] !== $outcome) {
                    throw ValidationException::withMessages(['outcome' => 'Резултатът вече е записан.']);
                }

                return $existing;
            }
            if ($current->status !== EnrollmentStatus::Active) {
                throw ValidationException::withMessages(['outcome' => 'Посещението не е активно.']);
            }
            // The copy of the visit terms decides; an offer visit from before the copies keeps its offer's deadline;
            // anything else follows the platform's standard terms (docs/product/visits.md, т. 8a.7).
            $copy = ($application->payment_terms_snapshot['billing_kind'] ?? null) === 'visit';
            $offer = $copy ? null : $application->visitOffer;
            $accepted = $application->transitions()->whereNotNull($offer !== null
                ? 'metadata->cancellation_deadline_hours' : 'metadata->session_starts_at')->first()?->metadata;
            // The start the parent booked for counts only for the deadline of a timely cancellation; whether the visit
            // has happened is told by the session's current start (docs/product/schedule-changes.md, т. 5.5).
            $booked = isset($accepted['session_starts_at']) ? CarbonImmutable::parse($accepted['session_starts_at']) : $session->starts_at;
            $deadlineHours = $offer !== null
                ? ($accepted['cancellation_deadline_hours'] ?? $offer->cancellation_deadline_hours)
                : $this->freeCancellationHours($application->payment_terms_snapshot);
            // Only the organization moves a session: until its new start a cancellation is always in time, without a fee
            // (decision 5, 31.10.2026).
            $moved = ! $booked->equalTo($session->starts_at);
            $timely = ($moved && now()->lessThan($session->starts_at))
                || ($deadlineHours !== null && now()->lessThanOrEqualTo($booked->subHours($deadlineHours)));
            if ((! $staff && now()->greaterThanOrEqualTo($session->starts_at))
                || ($outcome === 'cancelled_in_time' && ! $timely)
                || ($outcome === 'late_cancelled' && $timely)
            ) {
                throw ValidationException::withMessages(['outcome' => 'Резултатът не съответства на срока за посещението.']);
            }
            $metadata = $offer !== null ? [
                'outcome' => $outcome,
                'session_id' => $session->id,
                'cancellation_outcome' => $accepted['cancellation_outcome'] ?? $offer->cancellation_outcome,
                'refund_performed' => false,
            ] : [
                'outcome' => $outcome,
                'session_id' => $session->id,
                // The amount is worked out from the copy later (docs/product/payment-policies.md, 3a).
                'terms_key' => $application->payment_terms_snapshot['key'] ?? null,
                'refund_performed' => false,
            ];
            $status = $outcome === 'cancelled_in_time' ? EnrollmentStatus::Cancelled : EnrollmentStatus::Ended;
            $transition = EnrollmentTransition::query()->create([
                'application_id' => $application->id, 'enrollment_id' => $current->id,
                'actor_user_id' => $actor->id, 'from_status' => $current->status->value,
                'to_status' => $status->value, 'public_reason' => $reason, 'metadata' => $metadata,
            ]);
            $current->update(['status' => $status, 'version' => $current->version + 1]);
            EnrollmentEvent::query()->create([
                'type' => 'enrollment.visit_outcome_recorded', 'application_id' => $application->id,
                // The transition holds the reason as it was given (docs/product/enrollment-contacts-and-emails.md, т. 4).
                'enrollment_id' => $current->id, 'payload' => [...$metadata, 'transition_id' => $transition->id], 'occurred_at' => now(),
            ]);

            return $transition;
        });
    }

    /**
     * The team cancels a visit before its start with "enrollment.enroll" in the visit's group (docs/product/team-rights.md,
     * т. 2.2, 9). Attendance itself is recorded only through "Присъствия". The platform operator does not cancel.
     */
    private function recordsVisits(User $actor, string $groupId): bool
    {
        return $this->scope->access($actor)->role?->isTeam() === true
            && $this->scope->allows($actor, 'enrollment.enroll', $groupId);
    }

    /**
     * Until when a visit from a price is cancelled without a fee: the latest step of the terms that keeps nothing
     * (docs/product/visits.md, т. 5.4). Null — every cancellation keeps something. A visit booked before the
     * copy of the terms existed follows the platform's standard terms.
     *
     * @param  array<string, mixed>|null  $snapshot
     */
    private function freeCancellationHours(?array $snapshot): ?int
    {
        $rules = ($snapshot['billing_kind'] ?? null) === 'visit' && is_array($snapshot['rules'] ?? null)
            ? $snapshot['rules'] : AppliedTerms::standard(BillingKind::Visit)->rules;
        $free = null;
        foreach ($rules['cancellation']['tiers'] ?? [] as $tier) {
            if (is_array($tier) && ($tier['kept'] ?? null) === 0 && is_int($tier['min_hours'] ?? null)) {
                $free = $free === null ? $tier['min_hours'] : min($free, $tier['min_hours']);
            }
        }

        return $free;
    }
}
