<?php

namespace App\Domain\Enrollment\Actions;

use App\Domain\Enrollment\Enums\EnrollmentApplicationStatus;
use App\Domain\Enrollment\Enums\EnrollmentScope;
use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\EnrollmentApplication;
use App\Domain\Enrollment\Models\EnrollmentEvent;
use App\Domain\Enrollment\Models\EnrollmentTransition;
use App\Domain\Enrollment\Services\EnrollmentCapacity;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ReviewEnrollmentApplication
{
    public function __construct(private readonly EnrollmentCapacity $capacity) {}

    public function handle(
        User $actor,
        EnrollmentApplication $application,
        string $decision,
        int $expectedVersion,
        ?string $publicReason,
        ?string $internalNote,
    ): EnrollmentApplication {
        return DB::transaction(function () use (
            $actor, $application, $decision, $expectedVersion, $publicReason, $internalNote,
        ): EnrollmentApplication {
            $current = EnrollmentApplication::query()->lockForUpdate()->findOrFail($application->id);
            $group = $current->group()->lockForUpdate()->firstOrFail();

            if ($current->status !== EnrollmentApplicationStatus::Submitted
                || $current->version !== $expectedVersion) {
                throw ValidationException::withMessages([
                    'version' => 'Заявлението е променено междувременно или вече е обработено.',
                ]);
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

            $remaining = $current->scope === EnrollmentScope::FullPeriod
                ? $this->capacity->remainingForGroup($group)
                : $this->capacity->remainingForSession($current->session()->firstOrFail());

            if ($remaining !== null && $remaining < 1) {
                if (! $group->waitlist_enabled) {
                    throw ValidationException::withMessages(['capacity' => 'Няма свободни места и списъкът с чакащи е изключен.']);
                }

                return $this->transitionApplication(
                    $actor, $current, EnrollmentApplicationStatus::Waitlisted,
                    null, $internalNote, 'enrollment.application_waitlisted',
                );
            }

            $reviewed = $this->transitionApplication(
                $actor, $current, EnrollmentApplicationStatus::Approved,
                $publicReason, $internalNote, 'enrollment.application_approved', false,
            );
            Enrollment::query()->create([
                'application_id' => $reviewed->id,
                'child_id' => $reviewed->child_id,
                'group_id' => $reviewed->group_id,
                'price_option_id' => $reviewed->price_option_id,
                'session_id' => $reviewed->session_id,
                'scope' => $reviewed->scope,
                'status' => EnrollmentStatus::Active,
                'starts_on' => $reviewed->session?->starts_at->toDateString() ?? now()->toDateString(),
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
