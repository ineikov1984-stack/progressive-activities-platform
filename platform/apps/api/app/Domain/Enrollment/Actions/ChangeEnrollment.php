<?php

namespace App\Domain\Enrollment\Actions;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\ValueObjects\AuditableReference;
use App\Domain\Catalog\Enums\EnrollmentMode;
use App\Domain\Catalog\Models\Group;
use App\Domain\Catalog\Models\PriceOption;
use App\Domain\Catalog\Models\Session;
use App\Domain\Enrollment\Enums\EnrollmentApplicationStatus;
use App\Domain\Enrollment\Enums\EnrollmentScope;
use App\Domain\Enrollment\Enums\EnrollmentSource;
use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Models\Child;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\EnrollmentApplication;
use App\Domain\Enrollment\Models\EnrollmentEvent;
use App\Domain\Enrollment\Models\EnrollmentPause;
use App\Domain\Enrollment\Models\EnrollmentSessionException;
use App\Domain\Enrollment\Models\EnrollmentTransition;
use App\Domain\Enrollment\Services\EnrollmentCapacity;
use App\Domain\Enrollment\Services\SessionAllocation;
use App\Domain\Enrollment\Services\SessionApplicability;
use App\Domain\Identity\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Every change to a full-period enrollment — by the organization's team or by the parent —
 * goes through here with the same rules (docs/product/staff-enrollment.md, т. 9):
 * dates are calendar days in the group's time zone, from today on, and never touch a session
 * that has started; the team sends the version it saw (the parent does not); sessions that
 * count again are checked for a free place. Lock order: child → groups (by id) → enrollment;
 * capacity checks then lock sessions and the group's enrollments.
 */
final class ChangeEnrollment
{
    /** Price modes that enroll a child for the whole period. */
    public const FULL_PERIOD_MODES = [EnrollmentMode::FullPeriod->value, EnrollmentMode::Monthly->value, EnrollmentMode::Package->value];

    public const PAUSE_REASONS = ['health', 'holiday', 'family', 'other'];

    private const MAX_DAYS_AHEAD = 365;

    private const MAX_PAUSE_DAYS = 183;

    public function __construct(
        private readonly EnrollmentCapacity $capacity,
        private readonly SessionAllocation $allocation,
        private readonly SessionApplicability $applicability,
        private readonly AuditRecorder $audit,
    ) {}

    public function transfer(
        User $actor,
        Enrollment $enrollment,
        string $targetGroupId,
        string $priceOptionId,
        string $effectiveDate,
        string $reason,
        int $version,
    ): Enrollment {
        $reason = $this->reason($reason);

        return DB::transaction(function () use ($actor, $enrollment, $targetGroupId, $priceOptionId, $effectiveDate, $reason, $version): Enrollment {
            $target = Group::query()->find($targetGroupId);
            if ($target === null || $target->id === $enrollment->group_id) {
                throw ValidationException::withMessages(['target_group_id' => 'Изберете друга група от същата дейност.']);
            }
            [$current, $groups] = $this->lock($enrollment, $version, [$target->id]);
            if ($current->status !== EnrollmentStatus::Active) {
                throw ValidationException::withMessages(['enrollment' => 'Записването вече има дата на прекратяване или е приключило.']);
            }
            $source = $groups->get($current->group_id);
            $target = $groups->get($target->id);
            if (! $source instanceof Group || ! $target instanceof Group || $target->status !== 'published'
                || $target->offering?->activity_id === null || $target->offering->activity_id !== $source->offering?->activity_id) {
                throw ValidationException::withMessages(['target_group_id' => 'Изберете друга група от същата дейност.']);
            }

            $date = $this->day($effectiveDate, $this->timezone($target), 'effective_date');
            $this->assertNotStarted($source, $date, 'effective_date');
            $this->assertNotStarted($target, $date, 'effective_date');
            $price = PriceOption::query()->whereKey($priceOptionId)->where('group_id', $target->id)
                ->where('is_available', true)->whereIn('enrollment_mode', self::FULL_PERIOD_MODES)->first();
            if ($price === null) {
                throw ValidationException::withMessages(['price_option_id' => 'Изберете цена на новата група.']);
            }

            $remaining = $this->capacity->remainingForGroup($target, $date, forAllocation: true);
            if ($remaining !== null && $remaining < 1) {
                throw ValidationException::withMessages(['capacity' => 'В групата няма свободно място от тази дата.']);
            }
            // Also when the target group has no sessions yet: no second full-period place for the same child.
            $already = Enrollment::query()->where('group_id', $target->id)->where('child_id', $current->child_id)
                ->where('scope', EnrollmentScope::FullPeriod->value)
                ->whereIn('status', [EnrollmentStatus::Active->value, EnrollmentStatus::Paused->value, EnrollmentStatus::Ended->value])
                ->where(fn ($query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $date->toDateString()))
                ->first();
            if ($already !== null) {
                throw ValidationException::withMessages(['target_group_id' => $already->ends_on === null
                    ? 'Детето вече е записано в тази група.'
                    : 'Детето е в тази група до '.$already->ends_on->format('d.m.Y').'. Изберете дата след това.']);
            }
            $child = Child::query()->whereKey($current->child_id)->firstOrFail();
            foreach (Session::query()->where('group_id', $target->id)->where('status', 'scheduled')
                ->where('starts_at', '>=', $date->utc())->orderBy('id')->get() as $session) {
                try {
                    $this->allocation->assertAvailable($child, $session);
                } catch (ValidationException) {
                    throw ValidationException::withMessages(['target_group_id' => 'Детето вече има място в тази група.']);
                }
            }

            // The old enrollment stops the day before, so no session counts in both groups.
            $fromStatus = $current->status->value;
            $notStarted = $date->toDateString() <= $current->starts_on->toDateString();
            $lastDay = $notStarted ? null : $date->subDay();
            $current->update([
                'status' => $notStarted ? EnrollmentStatus::Cancelled : EnrollmentStatus::Ended,
                'ends_on' => $lastDay?->toDateString() ?? $current->ends_on?->toDateString(),
                'version' => $current->version + 1,
            ]);
            $trimmed = $this->trimPauses($actor, $current, $lastDay);
            $this->transition($actor, $current, $fromStatus, $current->status->value, $reason, [
                'transferred_to_group_id' => $target->id, 'pauses_changed' => $trimmed,
            ]);

            $application = $current->application;
            $priceChanged = $price->amount_cents !== $application->amount_cents_snapshot || $price->unit !== $application->unit_snapshot;
            $targetApplication = EnrollmentApplication::query()->create([
                'parent_user_id' => $application->parent_user_id,
                'child_id' => $current->child_id,
                'group_id' => $target->id,
                'price_option_id' => $price->id,
                'scope' => EnrollmentScope::FullPeriod,
                'source' => EnrollmentSource::Staff,
                'status' => EnrollmentApplicationStatus::Approved,
                // Version in the key: after a cancelled move the same enrollment may be transferred again.
                'idempotency_key' => 'transfer-'.$current->id.'-'.$current->version,
                // Same activity, same terms; the history records that the team made the change.
                'accepted_terms_version' => $application->accepted_terms_version,
                'accepted_terms_at' => $application->accepted_terms_at,
                'amount_cents_snapshot' => $price->amount_cents,
                'currency_snapshot' => $price->currency,
                'unit_snapshot' => $price->unit,
                'public_reason' => $reason,
            ]);
            EnrollmentTransition::query()->create([
                'application_id' => $targetApplication->id,
                'actor_user_id' => $actor->id,
                'from_status' => null,
                'to_status' => EnrollmentApplicationStatus::Approved->value,
                'public_reason' => $reason,
                'metadata' => ['terms_basis' => 'staff_transfer', 'transferred_from_id' => $current->id, 'price_changed' => $priceChanged],
            ]);
            $moved = Enrollment::query()->create([
                'application_id' => $targetApplication->id,
                'child_id' => $current->child_id,
                'group_id' => $target->id,
                'price_option_id' => $price->id,
                'scope' => EnrollmentScope::FullPeriod,
                'status' => EnrollmentStatus::Active,
                'starts_on' => $date->toDateString(),
                'transferred_from_id' => $current->id,
            ]);
            $this->transition($actor, $moved, null, EnrollmentStatus::Active->value, $reason, ['transferred_from_id' => $current->id]);
            $this->event('enrollment.transferred', $moved, [
                'transferred_from_id' => $current->id, 'effective_date' => $date->toDateString(),
                'amount_cents' => $price->amount_cents, 'currency' => $price->currency, 'unit' => $price->unit,
                'price_changed' => $priceChanged,
            ]);
            $this->audit->record(AuditAction::EnrollmentTransferred, new AuditableReference('enrollment', $current->id), [
                'new_enrollment_id' => $moved->id, 'from_group_id' => $current->group_id,
                'to_group_id' => $target->id, 'effective_date' => $date->toDateString(),
            ]);

            return $moved->refresh();
        });
    }

    public function pause(
        User $actor,
        Enrollment $enrollment,
        string $startsOn,
        string $endsOn,
        string $category,
        ?string $note,
        int $version,
    ): EnrollmentPause {
        if (! in_array($category, self::PAUSE_REASONS, true)) {
            throw ValidationException::withMessages(['reason_category' => 'Изберете причина.']);
        }
        $note = $note === null || trim($note) === '' ? null : trim($note);

        return DB::transaction(function () use ($actor, $enrollment, $startsOn, $endsOn, $category, $note, $version): EnrollmentPause {
            [$current, $groups] = $this->lock($enrollment, $version);
            $group = $this->groupOf($current, $groups);
            $timezone = $this->timezone($group);
            $this->assertRunning($current, $timezone);
            $start = $this->day($startsOn, $timezone, 'starts_on');
            $end = $this->day($endsOn, $timezone, 'ends_on');
            if ($start->toDateString() < $current->starts_on->toDateString()) {
                throw ValidationException::withMessages(['starts_on' => 'Паузата започва преди началото на записването.']);
            }
            $this->assertNotStarted($group, $start, 'starts_on');
            if ($end->lt($start) || $start->diffInDays($end) >= self::MAX_PAUSE_DAYS
                || ($current->ends_on !== null && $end->toDateString() > $current->ends_on->toDateString())) {
                throw ValidationException::withMessages(['ends_on' => 'Изберете край до 6 месеца след началото и не след края на записването.']);
            }
            $overlaps = EnrollmentPause::query()->where('enrollment_id', $current->id)->whereNull('cancelled_at')
                ->whereDate('starts_on', '<=', $end->toDateString())->whereDate('ends_on', '>=', $start->toDateString())->exists();
            if ($overlaps) {
                throw ValidationException::withMessages(['starts_on' => 'Има пауза в тези дати.']);
            }

            $pause = EnrollmentPause::query()->create([
                'enrollment_id' => $current->id,
                'starts_on' => $start->toDateString(),
                'ends_on' => $end->toDateString(),
                'reason_category' => $category,
                'note' => $note,
                'created_by' => $actor->id,
            ]);
            $dates = ['pause_id' => $pause->id, 'starts_on' => $start->toDateString(), 'ends_on' => $end->toDateString()];
            $this->touch($current);
            // The note may hold personal details; it stays in the pause (editable), never in the immutable history.
            $this->transition($actor, $current, $current->status->value, $current->status->value, null, [...$dates, 'reason_category' => $category]);
            $this->event('enrollment.paused', $current, [...$dates, 'reason_category' => $category]);
            $this->audit->record(AuditAction::EnrollmentPaused, new AuditableReference('enrollment', $current->id), $dates);

            return $pause->refresh();
        });
    }

    public function endPause(User $actor, Enrollment $enrollment, string $pauseId, string $resumeOn, int $version): EnrollmentPause
    {
        return DB::transaction(function () use ($actor, $enrollment, $pauseId, $resumeOn, $version): EnrollmentPause {
            [$current, $groups] = $this->lock($enrollment, $version);
            $group = $this->groupOf($current, $groups);
            $pause = EnrollmentPause::query()->where('enrollment_id', $current->id)->whereNull('cancelled_at')
                ->lockForUpdate()->findOrFail($pauseId);
            $resume = $this->day($resumeOn, $this->timezone($group), 'resume_on');
            if ($resume->toDateString() > $pause->ends_on->toDateString()) {
                throw ValidationException::withMessages(['resume_on' => 'Изберете дата до края на паузата.']);
            }
            $this->assertNotStarted($group, $resume, 'resume_on');

            $before = $this->futureSessionIds($current);
            $cancelled = $resume->toDateString() <= $pause->starts_on->toDateString();
            $pause->update($cancelled
                ? ['cancelled_at' => now(), 'ended_by' => $actor->id]
                : ['ends_on' => $resume->subDay()->toDateString(), 'ended_by' => $actor->id]);
            $this->assertPlacesFor($current, $before);

            $details = ['pause_id' => $pause->id, 'resume_on' => $resume->toDateString(), 'cancelled' => $cancelled];
            $this->touch($current);
            $this->transition($actor, $current, $current->status->value, $current->status->value, null, $details);
            $this->event('enrollment.pause_ended', $current, $details);
            $this->audit->record(AuditAction::EnrollmentPauseEnded, new AuditableReference('enrollment', $current->id), $details);

            return $pause->refresh();
        });
    }

    /** $version is null for the parent, who always changes the current state of their own child. */
    public function end(User $actor, Enrollment $enrollment, string $lastDay, string $reason, ?int $version): Enrollment
    {
        $reason = $this->reason($reason);

        return DB::transaction(function () use ($actor, $enrollment, $lastDay, $reason, $version): Enrollment {
            [$current, $groups] = $this->lock($enrollment, $version);
            if ($current->status !== EnrollmentStatus::Active) {
                throw ValidationException::withMessages(['enrollment' => 'Записването вече има дата на прекратяване или е приключило.']);
            }
            $last = $this->day($lastDay, $this->timezone($this->groupOf($current, $groups)), 'last_day');
            if ($current->ends_on !== null && $last->toDateString() > $current->ends_on->toDateString()) {
                throw ValidationException::withMessages(['last_day' => 'Записването приключва на '.$current->ends_on->format('d.m.Y').'. Изберете дата до тогава.']);
            }
            $notStarted = $last->toDateString() < $current->starts_on->toDateString();
            $fromStatus = $current->status->value;
            $previousEnd = $current->ends_on?->toDateString();
            $current->update([
                'status' => $notStarted ? EnrollmentStatus::Cancelled : EnrollmentStatus::Ended,
                'ends_on' => $notStarted ? $previousEnd : $last->toDateString(),
                'version' => $current->version + 1,
            ]);
            $trimmed = $this->trimPauses($actor, $current, $notStarted ? null : $last);
            $details = ['last_day' => $last->toDateString(), 'cancelled' => $notStarted];
            $this->transition($actor, $current, $fromStatus, $current->status->value, $reason, [
                ...$details, 'previous_ends_on' => $previousEnd, 'pauses_changed' => $trimmed,
            ]);
            $this->event($notStarted ? 'enrollment.cancelled' : 'enrollment.ended', $current, $details);
            $this->audit->record(AuditAction::EnrollmentEnded, new AuditableReference('enrollment', $current->id), $details);

            return $current->refresh();
        });
    }

    /** Undo a scheduled end ("we changed our mind"); a transfer is undone by transferring back. */
    public function reopen(User $actor, Enrollment $enrollment, int $version): Enrollment
    {
        return DB::transaction(function () use ($actor, $enrollment, $version): Enrollment {
            [$current, $groups] = $this->lock($enrollment, $version);
            $group = $this->groupOf($current, $groups);
            $timezone = $this->timezone($group);
            $today = CarbonImmutable::now($timezone)->toDateString();
            if ($current->status !== EnrollmentStatus::Ended || $current->ends_on === null || $current->ends_on->toDateString() < $today) {
                throw ValidationException::withMessages(['enrollment' => 'Само насрочено прекратяване може да се отмени.']);
            }
            if (Enrollment::query()->where('transferred_from_id', $current->id)->where('status', '!=', EnrollmentStatus::Cancelled->value)->exists()) {
                throw ValidationException::withMessages(['enrollment' => 'Детето е преместено в друга група. За да се върне, преместете го обратно.']);
            }

            $dayAfter = CarbonImmutable::parse($current->ends_on->toDateString(), $timezone)->addDay();
            $remaining = $this->capacity->remainingForGroup($group, $dayAfter, forAllocation: true);
            if ($remaining !== null && $remaining < 1) {
                throw ValidationException::withMessages(['capacity' => 'Мястото в групата вече е заето.']);
            }

            $previousEnd = $current->transitions()->where('to_status', EnrollmentStatus::Ended->value)
                ->latest()->first()?->metadata['previous_ends_on'] ?? null;
            $current->update([
                'status' => EnrollmentStatus::Active,
                'ends_on' => is_string($previousEnd) ? $previousEnd : null,
                'version' => $current->version + 1,
            ]);
            $details = ['previous_last_day' => $dayAfter->subDay()->toDateString()];
            $this->transition($actor, $current, EnrollmentStatus::Ended->value, EnrollmentStatus::Active->value, null, $details);
            $this->event('enrollment.reopened', $current, $details);
            $this->audit->record(AuditAction::EnrollmentReopened, new AuditableReference('enrollment', $current->id), $details);

            return $current->refresh();
        });
    }

    /** $version is null for the parent. */
    public function skipSession(User $actor, Enrollment $enrollment, string $sessionId, string $reason, ?int $version): EnrollmentSessionException
    {
        $reason = $this->reason($reason);

        return DB::transaction(function () use ($actor, $enrollment, $sessionId, $reason, $version): EnrollmentSessionException {
            [$current, $groups] = $this->lock($enrollment, $version);
            $this->assertRunning($current, $this->timezone($this->groupOf($current, $groups)));
            $session = Session::query()->whereKey($sessionId)->where('group_id', $current->group_id)->first();
            if ($session === null || $session->starts_at->lte(now())
                || ! $this->applicability->sessionsFor($current)->contains('id', $session->id)) {
                throw ValidationException::withMessages(['session_id' => 'Изберете бъдещо занимание, което детето посещава.']);
            }

            $exception = EnrollmentSessionException::query()->updateOrCreate(
                ['enrollment_id' => $current->id, 'session_id' => $session->id],
                ['kind' => 'excluded', 'reason' => $reason, 'created_by' => $actor->id],
            );
            $details = ['session_id' => $session->id];
            $this->touch($current);
            $this->transition($actor, $current, $current->status->value, $current->status->value, $reason, $details);
            $this->event('enrollment.session_excluded', $current, $details);
            $this->audit->record(AuditAction::EnrollmentSessionExcluded, new AuditableReference('enrollment', $current->id), $details);

            return $exception;
        });
    }

    public function restoreSession(User $actor, Enrollment $enrollment, string $sessionId, int $version): void
    {
        DB::transaction(function () use ($actor, $enrollment, $sessionId, $version): void {
            [$current, $groups] = $this->lock($enrollment, $version);
            $this->assertRunning($current, $this->timezone($this->groupOf($current, $groups)));
            $exception = EnrollmentSessionException::query()->where('enrollment_id', $current->id)
                ->where('session_id', $sessionId)->where('kind', 'excluded')->lockForUpdate()->first();
            $session = $exception === null ? null : Session::query()->whereKey($exception->session_id)->first();
            if ($exception === null || $session === null || $session->starts_at->lte(now())) {
                throw ValidationException::withMessages(['session_id' => 'Това занимание не е пропуснато или вече е минало.']);
            }

            $before = $this->futureSessionIds($current);
            $exception->delete();
            $this->assertPlacesFor($current, $before);

            $details = ['session_id' => $session->id];
            $this->touch($current);
            $this->transition($actor, $current, $current->status->value, $current->status->value, null, $details);
            $this->event('enrollment.session_restored', $current, $details);
            $this->audit->record(AuditAction::EnrollmentSessionRestored, new AuditableReference('enrollment', $current->id), $details);
        });
    }

    /**
     * Locks child, groups (by id) and then the enrollment, and checks the version the team saw.
     *
     * @param  list<string>  $otherGroupIds
     * @return array{Enrollment, Collection<string, Group>}
     */
    private function lock(Enrollment $enrollment, ?int $version, array $otherGroupIds = []): array
    {
        Child::query()->whereKey($enrollment->child_id)->lockForUpdate()->firstOrFail();
        /** @var Collection<string, Group> $groups */
        $groups = Group::query()->with('offering')->whereKey(array_values(array_unique([$enrollment->group_id, ...$otherGroupIds])))
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id')->toBase();
        $current = Enrollment::query()->with('application')->lockForUpdate()->findOrFail($enrollment->id);
        if ($version !== null && $current->version !== $version) {
            throw ValidationException::withMessages(['version' => 'Записването е променено междувременно. Презаредете страницата.']);
        }
        if ($current->scope !== EnrollmentScope::FullPeriod) {
            throw ValidationException::withMessages([
                'enrollment' => 'Тази промяна е само за записване за периода. За пробно или еднократно посещение отбележете резултата от посещението.',
            ]);
        }
        $group = $groups->get($current->group_id);
        if ($group instanceof Group) {
            $current->setRelation('group', $group);
        }

        return [$current, $groups];
    }

    /** @param Collection<string, Group> $groups */
    private function groupOf(Enrollment $enrollment, Collection $groups): Group
    {
        $group = $groups->get($enrollment->group_id);
        if (! $group instanceof Group) {
            throw ValidationException::withMessages(['enrollment' => 'Групата на записването не е намерена.']);
        }

        return $group;
    }

    /** The child still attends: active, or with an end date that has not passed. */
    private function assertRunning(Enrollment $enrollment, string $timezone): void
    {
        $today = CarbonImmutable::now($timezone)->toDateString();
        $running = $enrollment->status === EnrollmentStatus::Active
            || ($enrollment->status === EnrollmentStatus::Ended && $enrollment->ends_on !== null && $enrollment->ends_on->toDateString() >= $today);
        if (! $running) {
            throw ValidationException::withMessages(['enrollment' => 'Записването е приключило.']);
        }
    }

    /** A change from $from must not touch a session of the group that has already started. */
    private function assertNotStarted(Group $group, CarbonImmutable $from, string $field): void
    {
        $started = Session::query()->where('group_id', $group->id)->where('status', 'scheduled')
            ->where('starts_at', '>=', $from->utc())->where('starts_at', '<=', now()->utc())->exists();
        if ($started) {
            throw ValidationException::withMessages([$field => 'Днешното занимание вече е започнало — изберете дата от утре.']);
        }
    }

    /** @return Collection<int, string> */
    private function futureSessionIds(Enrollment $enrollment): Collection
    {
        return $this->applicability->sessionsFor($enrollment)
            ->filter(fn (Session $session): bool => $session->starts_at->gt(now()))
            ->pluck('id')->toBase();
    }

    /**
     * Sessions that count for the child again (pause ended early, skip undone) may have been given
     * to a one-off visit in the meantime. Refuse the change if any of them is now over capacity.
     *
     * @param  Collection<int, string>  $before
     */
    private function assertPlacesFor(Enrollment $enrollment, Collection $before): void
    {
        $returning = $this->applicability->sessionsFor($enrollment)
            ->filter(fn (Session $session): bool => $session->starts_at->gt(now()) && ! $before->contains($session->id))
            ->sortBy('id');
        foreach ($returning as $session) {
            Session::query()->whereKey($session->id)->lockForUpdate()->first();
            if ($this->capacity->overbooked($session)) {
                $day = $session->starts_at->setTimezone(SessionApplicability::timezone($enrollment))->format('d.m.Y');
                throw ValidationException::withMessages(['capacity' => "На {$day} вече няма свободно място."]);
            }
        }
    }

    /** After an end or transfer, pauses after the last day are cancelled and one that runs over it is shortened. */
    private function trimPauses(User $actor, Enrollment $enrollment, ?CarbonImmutable $lastDay): int
    {
        $changed = 0;
        $pauses = EnrollmentPause::query()->where('enrollment_id', $enrollment->id)->whereNull('cancelled_at')->lockForUpdate()->get();
        foreach ($pauses as $pause) {
            if ($lastDay === null || $pause->starts_on->toDateString() > $lastDay->toDateString()) {
                $pause->update(['cancelled_at' => now(), 'ended_by' => $actor->id]);
                $changed++;
            } elseif ($pause->ends_on->toDateString() > $lastDay->toDateString()) {
                $pause->update(['ends_on' => $lastDay->toDateString(), 'ended_by' => $actor->id]);
                $changed++;
            }
        }

        return $changed;
    }

    /** A calendar day from today up to a year ahead, in the group's time zone. */
    private function day(string $value, string $timezone, string $field): CarbonImmutable
    {
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $value, $timezone);
        $today = CarbonImmutable::now($timezone)->startOfDay();
        if (! $day instanceof CarbonImmutable || $day->format('Y-m-d') !== $value || $day->lt($today) || $day->gt($today->addDays(self::MAX_DAYS_AHEAD))) {
            throw ValidationException::withMessages([$field => 'Изберете дата от днес до една година напред.']);
        }

        return $day;
    }

    private function reason(string $reason): string
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'Напишете кратко обяснение (до 500 знака).']);
        }

        return $reason;
    }

    private function timezone(Group $group): string
    {
        $timezone = $group->schedule_pattern['timezone'] ?? null;

        return is_string($timezone) && $timezone !== '' ? $timezone : 'Europe/Sofia';
    }

    private function touch(Enrollment $enrollment): void
    {
        $enrollment->update(['version' => $enrollment->version + 1]);
    }

    /** @param array<string, mixed>|null $metadata */
    private function transition(User $actor, Enrollment $enrollment, ?string $fromStatus, string $toStatus, ?string $reason, ?array $metadata = null): void
    {
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
