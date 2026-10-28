<?php

namespace App\Domain\Enrollment\Actions;

use App\Domain\Catalog\Models\Group;
use App\Domain\Catalog\Models\Session;
use App\Domain\Enrollment\Enums\EnrollmentApplicationStatus;
use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\EnrollmentApplication;
use App\Domain\Enrollment\Models\EnrollmentEvent;
use App\Domain\Enrollment\Models\EnrollmentSessionException;
use App\Domain\Enrollment\Models\EnrollmentTransition;
use App\Domain\Identity\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ChangeEnrollment
{
    public function endFrom(User $actor, Enrollment $enrollment, string $effectiveDate, string $reason): Enrollment
    {
        $date = CarbonImmutable::parse($effectiveDate)->startOfDay();
        $this->validateReasonAndDate($reason, $date, $enrollment);

        return DB::transaction(function () use ($actor, $enrollment, $date, $reason): Enrollment {
            $current = Enrollment::query()->lockForUpdate()->findOrFail($enrollment->id);
            $fromStatus = $current->status->value;
            $current->update([
                'status' => EnrollmentStatus::Ended,
                'ends_on' => $date->toDateString(),
                'version' => $current->version + 1,
            ]);
            $this->transition($actor, $current, $fromStatus, EnrollmentStatus::Ended->value, $reason);
            $this->event('enrollment.ended', $current, ['effective_date' => $date->toDateString()]);

            return $current->refresh();
        });
    }

    public function excludeSession(User $actor, Enrollment $enrollment, Session $session, string $reason): EnrollmentSessionException
    {
        if (trim($reason) === '' || $session->group_id !== $enrollment->group_id) {
            throw ValidationException::withMessages(['reason' => 'Посочете валидно занятие и основание.']);
        }

        return DB::transaction(function () use ($actor, $enrollment, $session, $reason): EnrollmentSessionException {
            $exception = EnrollmentSessionException::query()->firstOrCreate([
                'enrollment_id' => $enrollment->id,
                'session_id' => $session->id,
            ], [
                'kind' => 'excluded',
                'reason' => $reason,
                'created_by' => $actor->id,
            ]);
            if ($exception->wasRecentlyCreated) {
                $this->transition($actor, $enrollment, $enrollment->status->value, $enrollment->status->value, $reason, [
                    'session_id' => $session->id,
                ]);
                $this->event('enrollment.session_excluded', $enrollment, ['session_id' => $session->id]);
            }

            return $exception;
        });
    }

    public function transfer(
        User $actor,
        Enrollment $enrollment,
        Group $targetGroup,
        string $effectiveDate,
        string $reason,
    ): Enrollment {
        $date = CarbonImmutable::parse($effectiveDate)->startOfDay();
        if (trim($reason) === '' || $targetGroup->id === $enrollment->group_id) {
            throw ValidationException::withMessages(['group_id' => 'Изберете друга група и посочете основание.']);
        }

        return DB::transaction(function () use ($actor, $enrollment, $targetGroup, $date, $reason): Enrollment {
            $source = Enrollment::query()->lockForUpdate()->findOrFail($enrollment->id);
            if ($source->ends_on === null || $source->ends_on->toDateString() !== $date->toDateString()) {
                $source = $this->endFrom($actor, $source, $date->toDateString(), $reason);
            }
            $application = $source->application;
            $targetApplication = EnrollmentApplication::query()->create([
                'parent_user_id' => $application->parent_user_id,
                'child_id' => $application->child_id,
                'group_id' => $targetGroup->id,
                'price_option_id' => $application->price_option_id,
                'scope' => $application->scope,
                'source' => $application->source,
                'status' => EnrollmentApplicationStatus::Approved,
                'idempotency_key' => 'transfer-'.$source->id.'-'.$targetGroup->id,
                'accepted_terms_version' => $application->accepted_terms_version,
                'accepted_terms_at' => $application->accepted_terms_at,
                'amount_cents_snapshot' => $application->amount_cents_snapshot,
                'currency_snapshot' => $application->currency_snapshot,
                'unit_snapshot' => $application->unit_snapshot,
                'public_reason' => $reason,
            ]);
            $target = Enrollment::query()->create([
                'application_id' => $targetApplication->id,
                'child_id' => $source->child_id,
                'group_id' => $targetGroup->id,
                'price_option_id' => $source->price_option_id,
                'scope' => $source->scope,
                'status' => EnrollmentStatus::Active,
                'starts_on' => $date->toDateString(),
                'transferred_from_id' => $source->id,
            ]);
            $this->transition($actor, $target, null, EnrollmentStatus::Active->value, $reason, [
                'transferred_from_id' => $source->id,
            ]);
            $this->event('enrollment.transferred', $target, ['transferred_from_id' => $source->id]);

            return $target;
        });
    }

    private function validateReasonAndDate(string $reason, CarbonImmutable $date, Enrollment $enrollment): void
    {
        if (trim($reason) === '' || $date->lt($enrollment->starts_on)) {
            throw ValidationException::withMessages(['effective_date' => 'Посочете валидна дата и основание.']);
        }
    }

    /** @param array<string, mixed>|null $metadata */
    private function transition(
        User $actor,
        Enrollment $enrollment,
        ?string $fromStatus,
        string $toStatus,
        string $reason,
        ?array $metadata = null,
    ): void {
        EnrollmentTransition::query()->create([
            'enrollment_id' => $enrollment->id,
            'actor_user_id' => $actor->id,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'public_reason' => $reason,
            'metadata' => $metadata,
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function event(string $type, Enrollment $enrollment, array $payload): void
    {
        EnrollmentEvent::query()->create([
            'type' => $type,
            'application_id' => $enrollment->application_id,
            'enrollment_id' => $enrollment->id,
            'payload' => $payload,
            'occurred_at' => now(),
        ]);
    }
}
