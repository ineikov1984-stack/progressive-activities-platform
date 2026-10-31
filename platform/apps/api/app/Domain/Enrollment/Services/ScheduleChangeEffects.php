<?php

namespace App\Domain\Enrollment\Services;

use App\Domain\Catalog\Models\Session;
use App\Domain\Enrollment\Delivery\BulgarianDates;
use App\Domain\Enrollment\Enums\EnrollmentApplicationStatus;
use App\Domain\Enrollment\Enums\EnrollmentScope;
use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\EnrollmentApplication;
use App\Domain\Enrollment\Models\EnrollmentSessionException;
use App\Domain\Enrollment\Models\EnrollmentTransition;
use App\Domain\Enrollment\ValueObjects\ScheduleEffects;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * What a change of the schedule does to the enrollments (docs/product/schedule-changes.md, т. 5, 6.1) — the one place
 * the Catalog asks, in its own transaction. Who comes is `SessionApplicability`'s rule on the session as it is and as it
 * will be: a cancelled session's children and visits are told, its visits are cancelled by the organization (the trial
 * is free again) and the applications waiting for it refused; a session that comes back is told to whom it counts again,
 * and the families whose visit it cancelled hear that the day is free. Every enrollment that does not run yet starts at
 * the group's first session from today again (т. 5.4). One event per family and one per teacher; the reason never goes
 * into an event, and into the history only when it goes to the parents.
 *
 * From a number of queries that grows with the groups, not with the children (т. 4.1а).
 *
 * @phpstan-import-type Item from ScheduleEffects
 * @phpstan-import-type Warning from ScheduleEffects
 */
final class ScheduleChangeEffects
{
    /** The letter to a family. */
    public const LETTER = 'schedule.sessions_changed';

    /** The letter to a teacher of the session (decision 6). */
    public const TEACHER_LETTER = 'schedule.teacher_sessions_changed';

    public function __construct(
        private readonly SessionApplicability $applicability,
        private readonly TenantContext $context,
    ) {}

    /**
     * Inside the groups the change has locked: the applications for its sessions, then the groups' enrollments (т. 4.1а).
     *
     * @param  list<string>  $sessionIds
     * @param  list<string>  $groupIds
     */
    public function lock(array $sessionIds, array $groupIds): void
    {
        EnrollmentApplication::query()->whereIn('session_id', $sessionIds)->orderBy('id')->lockForUpdate()->get(['id']);
        Enrollment::query()->whereIn('group_id', $groupIds)->orderBy('id')->lockForUpdate()->get(['id']);
    }

    /**
     * @param  list<array{before: Session, after: Session}>  $changes  each session as it is and as it will be
     */
    public function assess(array $changes, CarbonImmutable $now): ScheduleEffects
    {
        if ($changes === []) {
            return ScheduleEffects::none();
        }
        $all = [];
        $sessionIds = [];
        $groupIds = [];
        foreach ($changes as $change) {
            $all[] = $change['before'];
            $all[] = $change['after'];
            $sessionIds[] = (string) $change['before']->id;
            $groupIds[(string) $change['before']->group_id] = true;
        }
        $groupIds = array_map('strval', array_keys($groupIds));

        /** @var array<string, Enrollment> $enrollments */
        $enrollments = [];
        foreach ($this->applicability->candidates(new Collection($all)) as $enrollment) {
            $enrollments[(string) $enrollment->id] = $enrollment;
        }
        $notStarted = $this->notStarted($groupIds, $sessionIds, $now);
        foreach ($notStarted as $enrollment) {
            $enrollments[(string) $enrollment->id] ??= $enrollment;
        }

        // Who comes to each session as it is; then, with the starts worked out again, as it will be (т. 5.1, 5.4).
        $before = [];
        foreach ($changes as $change) {
            $before[(string) $change['before']->id] = array_values(array_filter($enrollments,
                fn (Enrollment $enrollment): bool => ($enrollment->scope === EnrollmentScope::FullPeriod || $enrollment->status === EnrollmentStatus::Active)
                    && $this->applicability->appliesTo($enrollment, $change['before'])));
        }
        $starts = $this->newStarts($notStarted, $changes, $now);
        $moved = [];
        foreach ($changes as $change) {
            if ($change['before']->status === 'scheduled' && $change['after']->status === 'scheduled') {
                $moved[(string) $change['after']->id] = $change['after'];
            }
        }
        $droppedSkips = [];
        foreach (EnrollmentSessionException::query()->whereIn('session_id', array_keys($moved))->where('kind', 'excluded')->get() as $skip) {
            $droppedSkips[(string) $skip->enrollment_id][] = (string) $skip->session_id;
        }
        // Include even a skipped enrollment outside both days: dropping its skip still needs history.
        foreach (Enrollment::query()->whereKey(array_diff(array_keys($droppedSkips), array_keys($enrollments)))
            ->with(['group', 'sessionExceptions', 'pauses'])->get() as $enrollment) {
            $enrollments[(string) $enrollment->id] = $enrollment;
        }
        $later = [];
        foreach ($enrollments as $id => $enrollment) {
            $enrollment = clone $enrollment;
            $enrollment->setRelation('sessionExceptions', $enrollment->sessionExceptions->reject(
                fn ($skip): bool => in_array((string) $skip->session_id, $droppedSkips[$id] ?? [], true))->values());
            if (isset($starts[$id])) {
                $copy = clone $enrollment;
                $copy->starts_on = CarbonImmutable::parse($starts[$id]['to']);
                $enrollment = $copy;
            }
            $later[$id] = $enrollment;
        }
        $after = [];
        foreach ($changes as $change) {
            $after[(string) $change['after']->id] = array_values(array_filter($later,
                fn (Enrollment $enrollment): bool => ($enrollment->scope === EnrollmentScope::FullPeriod || $enrollment->status === EnrollmentStatus::Active)
                    && $this->applicability->appliesTo($enrollment, $change['after'])));
        }

        $cancelled = [];
        $restored = [];
        foreach ($changes as $change) {
            $id = (string) $change['before']->id;
            if ($change['before']->status === 'scheduled' && $change['after']->status === 'cancelled') {
                $cancelled[] = $id;
            } elseif ($change['before']->status === 'cancelled' && $change['after']->status === 'scheduled') {
                $restored[] = $id;
            }
        }
        // The visit applications still waiting for a cancelled session are refused (т. 5.6).
        $waiting = array_values(EnrollmentApplication::query()->whereIn('session_id', [...$cancelled, ...array_keys($moved)])
            ->whereIn('status', [EnrollmentApplicationStatus::Submitted->value, EnrollmentApplicationStatus::Waitlisted->value])
            ->orderBy('id')->get(['id', 'parent_user_id', 'child_id', 'session_id', 'status'])->all());
        [$freedVisits, $freedApplications] = $this->freedByCancellation($restored);

        $parents = EnrollmentApplication::query()->whereKey(array_values(array_unique([
            ...array_map(fn (Enrollment $enrollment): string => (string) $enrollment->application_id, array_values($enrollments)),
            ...array_map(fn (Enrollment $enrollment): string => (string) $enrollment->application_id, $freedVisits),
        ])))->pluck('parent_user_id', 'id')->map(fn (mixed $id): string => (string) $id)->all();
        $parentOf = fn (Enrollment $enrollment): string => $parents[(string) $enrollment->application_id] ?? '';

        $items = [];
        foreach ($cancelled as $sessionId) {
            foreach ($before[$sessionId] as $enrollment) {
                if ($enrollment->scope === EnrollmentScope::FullPeriod) {
                    $items[] = self::item($parentOf($enrollment), $sessionId, 'cancelled', (string) $enrollment->id, null, (string) $enrollment->child_id);
                } elseif ($enrollment->status === EnrollmentStatus::Active) {
                    $items[] = self::item($parentOf($enrollment), $sessionId, 'visit_cancelled', (string) $enrollment->id, null, (string) $enrollment->child_id);
                }
            }
            foreach ($waiting as $application) {
                if ((string) $application->session_id === $sessionId) {
                    $items[] = self::item((string) $application->parent_user_id, $sessionId, 'application_rejected', null, (string) $application->id, (string) $application->child_id);
                }
            }
        }
        foreach ($restored as $sessionId) {
            foreach ($after[$sessionId] as $enrollment) {
                if ($enrollment->scope === EnrollmentScope::FullPeriod || $enrollment->status === EnrollmentStatus::Active) {
                    $items[] = self::item($parentOf($enrollment), $sessionId, 'restored', (string) $enrollment->id, null, (string) $enrollment->child_id);
                }
            }
            // The visit stays cancelled — the family may have chosen another day; they hear the day is free (т. 5.5).
            foreach ($freedVisits as $visit) {
                if ((string) $visit->session_id === $sessionId) {
                    $items[] = self::item($parentOf($visit), $sessionId, 'visit_day_free_again', (string) $visit->id, null, (string) $visit->child_id);
                }
            }
            foreach ($freedApplications as $application) {
                if ((string) $application->session_id === $sessionId) {
                    $items[] = self::item((string) $application->parent_user_id, $sessionId, 'visit_day_free_again', null, (string) $application->id, (string) $application->child_id);
                }
            }
        }
        $visitStarts = [];
        foreach ($moved as $sessionId => $session) {
            $affected = [];
            foreach ([...$before[$sessionId], ...$after[$sessionId]] as $enrollment) {
                $affected[(string) $enrollment->id] = $enrollment;
            }
            foreach ($droppedSkips as $id => $ids) {
                if (in_array($sessionId, $ids, true) && isset($enrollments[$id])) {
                    $affected[$id] = $enrollments[$id];
                }
            }
            $comingIds = array_map(fn (Enrollment $enrollment): string => (string) $enrollment->id, $after[$sessionId]);
            foreach ($affected as $id => $enrollment) {
                $effect = ! in_array($id, $comingIds, true) ? 'moved_not_attending'
                    : (in_array($sessionId, $droppedSkips[$id] ?? [], true) ? 'moved_skip_dropped' : 'moved');
                $items[] = self::item($parentOf($enrollment), $sessionId, $effect, $id, null, (string) $enrollment->child_id);
                if ($enrollment->scope !== EnrollmentScope::FullPeriod && $enrollment->status === EnrollmentStatus::Active) {
                    $day = $session->starts_at->setTimezone(SessionApplicability::timezone($enrollment))->toDateString();
                    if ($enrollment->starts_on->toDateString() !== $day) {
                        $visitStarts[$id] = ['from' => $enrollment->starts_on->toDateString(), 'to' => $day];
                    }
                }
            }
            foreach ($waiting as $application) {
                if ((string) $application->session_id === $sessionId) {
                    $items[] = self::item((string) $application->parent_user_id, $sessionId, 'moved', null, (string) $application->id, (string) $application->child_id);
                }
            }
        }
        $items = array_values(array_filter($items, fn (array $told): bool => $told['parent_user_id'] !== ''));

        // Who comes: the children of the period and the visits that still hold, before or after (т. 5.1, 7.2).
        $sessions = [];
        $children = [];
        $warnings = [];
        foreach ($changes as $change) {
            $sessionId = (string) $change['before']->id;
            $coming = [];
            foreach ([...$before[$sessionId], ...$after[$sessionId]] as $enrollment) {
                if ($enrollment->scope === EnrollmentScope::FullPeriod || $enrollment->status === EnrollmentStatus::Active) {
                    $coming[(string) $enrollment->id] = $enrollment;
                }
            }
            $childIds = array_values(array_unique(array_map(fn (Enrollment $enrollment): string => (string) $enrollment->child_id, $coming)));
            array_push($children, ...$childIds);
            $sessions[$sessionId] = [
                'children' => count($childIds),
                'visits' => count(array_filter($coming, fn (Enrollment $enrollment): bool => $enrollment->scope !== EnrollmentScope::FullPeriod)),
                'families' => count(array_unique(array_column(array_filter($items, fn (array $told): bool => $told['session_id'] === $sessionId), 'parent_user_id'))),
            ];
            $warning = $this->overbooked($change['after'], count($after[$sessionId]));
            if ($warning !== null) {
                $warnings[] = $warning;
            }
        }

        $statuses = [];
        foreach ($enrollments as $id => $enrollment) {
            $statuses[$id] = $enrollment->status->value;
        }
        $applicationStatuses = [];
        foreach ($waiting as $application) {
            $applicationStatuses[(string) $application->id] = $application->status->value;
        }
        $familyIds = array_values(array_unique(array_column($items, 'parent_user_id')));
        $confirmed = [];
        foreach (User::query()->whereKey($familyIds)->get(['id', 'email_verified_at']) as $user) {
            $confirmed[(string) $user->id] = $user->email_verified_at !== null;
        }

        return new ScheduleEffects($items, $sessions, $starts, $statuses, $applicationStatuses, $confirmed, $warnings,
            count(array_unique($children)), $droppedSkips, $visitStarts);
    }

    /**
     * Writes what the change does (т. 5, 6.1): the history of every enrollment and application it touches with the
     * change's identifier and a new version, the visits it cancels and the applications it refuses, the new starts, one
     * event per family and one per teacher. In the history the reason only when it goes to the parents; otherwise words
     * that say what happened. Batched: a query per kind of row, per refused session and per new start.
     *
     * @param  array<string, string>  $days  session => its day in the group's time zone, for the words of a refusal
     * @param  array<string, list<array{session_id: string, effect: string}>>  $teachers  user => the sessions they hear of
     */
    public function apply(User $actor, string $changeId, ScheduleEffects $effects, ?string $reasonForParents, array $days, array $teachers, CarbonImmutable $now): void
    {
        $organizationId = $this->context->organizationId();
        $transitions = [];
        $touched = [];
        $visits = [];
        $refused = [];
        $withStart = [];
        $movedApplications = [];
        $row = fn (?string $applicationId, ?string $enrollmentId, ?string $from, string $to, string $reason, array $metadata): array => [
            'id' => (string) Str::uuid7(), 'organization_id' => $organizationId, 'application_id' => $applicationId,
            'enrollment_id' => $enrollmentId, 'actor_user_id' => (string) $actor->id, 'from_status' => $from, 'to_status' => $to,
            'public_reason' => $reason, 'internal_note' => null,
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'created_at' => $now, 'updated_at' => $now,
        ];

        foreach ($effects->items as $item) {
            $metadata = ['schedule_change_id' => $changeId, 'session_id' => $item['session_id'], 'effect' => $item['effect']];
            $enrollmentId = $item['enrollment_id'];
            if ($enrollmentId !== null && in_array($item['session_id'], $effects->droppedSkips[$enrollmentId] ?? [], true)) {
                $metadata['skip_dropped'] = true;
            }
            $applicationId = $item['application_id'];
            if (in_array($item['effect'], ['cancelled', 'restored', 'moved', 'moved_skip_dropped', 'moved_not_attending'], true) && $enrollmentId !== null) {
                $status = $effects->statuses[$enrollmentId] ?? EnrollmentStatus::Active->value;
                $start = $effects->starts[$enrollmentId] ?? $effects->visitStarts[$enrollmentId] ?? null;
                if ($start !== null) {
                    $metadata += ['previous_starts_on' => $start['from'], 'starts_on' => $start['to']];
                    $withStart[$enrollmentId] = true;
                }
                $transitions[] = $row(null, $enrollmentId, $status, $status, $reasonForParents ?? ($item['effect'] === 'cancelled'
                    ? 'Заниманието е отменено от организацията.' : ($item['effect'] === 'restored' ? 'Заниманието отново е в графика.' : 'Заниманието е преместено от организацията.')), $metadata);
                $touched[] = $enrollmentId;
            } elseif ($item['effect'] === 'moved' && $applicationId !== null) {
                $status = $effects->applicationStatuses[$applicationId];
                $transitions[] = $row($applicationId, null, $status, $status, $reasonForParents ?? 'Заниманието е преместено от организацията.', $metadata);
                $movedApplications[] = $applicationId;
            } elseif ($item['effect'] === 'visit_cancelled' && $enrollmentId !== null) {
                // By the organization: no fee, and the trial is free again (т. 5.5).
                $transitions[] = $row(null, $enrollmentId, EnrollmentStatus::Active->value, EnrollmentStatus::Cancelled->value,
                    $reasonForParents ?? 'Заниманието е отменено от организацията.', [...$metadata, 'by_organization' => true]);
                $touched[] = $enrollmentId;
                $visits[] = $enrollmentId;
            } elseif ($item['effect'] === 'application_rejected' && $applicationId !== null) {
                $transitions[] = $row($applicationId, null, $effects->applicationStatuses[$applicationId] ?? null,
                    EnrollmentApplicationStatus::Rejected->value, self::refusal($days[$item['session_id']] ?? null, $reasonForParents), $metadata);
                $refused[$item['session_id']][] = $applicationId;
            }
        }
        foreach ($effects->starts as $enrollmentId => $start) {
            if (isset($withStart[$enrollmentId])) {
                continue;
            }
            $status = $effects->statuses[$enrollmentId] ?? EnrollmentStatus::Active->value;
            $transitions[] = $row(null, $enrollmentId, $status, $status, 'Началото на записването е сменено заради промяна в графика.', [
                'schedule_change_id' => $changeId, 'effect' => 'starts_changed', 'previous_starts_on' => $start['from'], 'starts_on' => $start['to'],
            ]);
            $touched[] = $enrollmentId;
        }

        if ($movedApplications !== []) {
            EnrollmentApplication::query()->whereKey($movedApplications)->update(['version' => DB::raw('version + 1')]);
        }
        $skippedSessions = array_values(array_unique(array_merge([], ...array_values($effects->droppedSkips))));
        if ($skippedSessions !== []) {
            EnrollmentSessionException::query()->whereIn('session_id', $skippedSessions)->where('kind', 'excluded')->delete();
        }
        if ($touched !== []) {
            Enrollment::query()->whereKey(array_values(array_unique($touched)))->update(['version' => DB::raw('version + 1')]);
        }
        if ($visits !== []) {
            Enrollment::query()->whereKey($visits)->update(['status' => EnrollmentStatus::Cancelled->value]);
        }
        $byDay = [];
        foreach ([...$effects->starts, ...$effects->visitStarts] as $enrollmentId => $start) {
            $byDay[$start['to']][] = $enrollmentId;
        }
        foreach ($byDay as $day => $enrollmentIds) {
            Enrollment::query()->whereKey($enrollmentIds)->update(['starts_on' => $day]);
        }
        foreach ($refused as $sessionId => $applicationIds) {
            EnrollmentApplication::query()->whereKey($applicationIds)->update([
                'status' => EnrollmentApplicationStatus::Rejected->value,
                'public_reason' => self::refusal($days[$sessionId] ?? null, $reasonForParents),
                'version' => DB::raw('version + 1'),
            ]);
        }
        if ($transitions !== []) {
            DB::table('enrollment_transitions')->insert($transitions);
        }

        $events = [];
        $event = fn (string $type, array $payload): array => [
            'id' => (string) Str::uuid7(), 'organization_id' => $organizationId, 'type' => $type, 'application_id' => null,
            'enrollment_id' => null, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'occurred_at' => $now, 'attempts' => 0, 'created_at' => $now, 'updated_at' => $now,
        ];
        $byFamily = [];
        foreach ($effects->items as $item) {
            $byFamily[$item['parent_user_id']][] = [
                'session_id' => $item['session_id'], 'enrollment_id' => $item['enrollment_id'],
                'application_id' => $item['application_id'], 'effect' => $item['effect'],
            ];
        }
        foreach ($byFamily as $parentId => $told) {
            $events[] = $event(self::LETTER, ['schedule_change_id' => $changeId, 'parent_user_id' => (string) $parentId, 'items' => $told]);
        }
        foreach ($teachers as $userId => $told) {
            $events[] = $event(self::TEACHER_LETTER, ['schedule_change_id' => $changeId, 'user_id' => (string) $userId, 'items' => $told]);
        }
        if ($events !== []) {
            DB::table('enrollment_events')->insert($events);
        }
    }

    /** @return Item */
    private static function item(string $parent, string $sessionId, string $effect, ?string $enrollmentId, ?string $applicationId, string $childId): array
    {
        return [
            'parent_user_id' => $parent, 'session_id' => $sessionId, 'effect' => $effect,
            'enrollment_id' => $enrollmentId, 'application_id' => $applicationId, 'child_id' => $childId,
        ];
    }

    /** „Заниманието на 18 ноември е отменено.“ — and the reason, when it goes to the parents (т. 5.6). */
    public static function refusal(?string $day, ?string $reasonForParents): string
    {
        $text = $day === null ? 'Заниманието е отменено.' : 'Заниманието на '.BulgarianDates::day($day).' е отменено.';

        return $reasonForParents === null ? $text : $text.' '.$reasonForParents;
    }

    /**
     * The groups' enrollments for the period that do not run yet (т. 5.4) — active, from today on, with what
     * `SessionApplicability::appliesTo` reads; whether today's session has started is decided with the sessions.
     *
     * @param  list<string>  $groupIds
     * @param  list<string>  $sessionIds
     * @return list<Enrollment>
     */
    private function notStarted(array $groupIds, array $sessionIds, CarbonImmutable $now): array
    {
        return array_values(Enrollment::query()->whereIn('group_id', $groupIds)
            ->where('scope', EnrollmentScope::FullPeriod->value)->where('status', EnrollmentStatus::Active->value)
            // A day back: the days of an enrollment are the group's, not UTC's.
            ->whereDate('starts_on', '>=', $now->subDay()->toDateString())
            ->with([
                'group',
                'sessionExceptions' => fn ($query) => $query->where('kind', 'excluded')->whereIn('session_id', $sessionIds),
                'pauses' => fn ($query) => $query->whereNull('cancelled_at'),
            ])
            ->orderBy('id')->get()->all());
    }

    /**
     * The start of each enrollment that does not run yet, worked out again with the sessions as they will be (т. 5.4):
     * the group's first planned session that has not started, from the lower bound on — today, or the day of the
     * transfer for a continuation — and the bound itself when there is none. Only the starts that differ; never one
     * after the last day.
     *
     * @param  list<Enrollment>  $enrollments
     * @param  list<array{before: Session, after: Session}>  $changes
     * @return array<string, array{from: string, to: string}>
     */
    private function newStarts(array $enrollments, array $changes, CarbonImmutable $now): array
    {
        if ($enrollments === []) {
            return [];
        }
        $groupIds = array_values(array_unique(array_map(fn (Enrollment $enrollment): string => (string) $enrollment->group_id, $enrollments)));
        $changed = [];
        foreach ($changes as $change) {
            $changed[(string) $change['after']->id] = $change['after'];
        }
        $planned = Session::query()->whereIn('group_id', $groupIds)->where('status', 'scheduled')
            ->where('starts_at', '>=', $now->subDays(2)->utc())->whereNotIn('id', array_keys($changed))
            ->orderBy('starts_at')->orderBy('id')->get(['id', 'group_id', 'starts_at', 'status']);
        $timeline = $planned->all();
        foreach ($changed as $session) {
            if ($session->status === 'scheduled' && in_array((string) $session->group_id, $groupIds, true)) {
                $timeline[] = $session;
            }
        }
        usort($timeline, fn (Session $a, Session $b): int => [$a->starts_at->getTimestamp(), (string) $a->id] <=> [$b->starts_at->getTimestamp(), (string) $b->id]);

        $starts = [];
        foreach ($enrollments as $enrollment) {
            $timezone = SessionApplicability::timezone($enrollment);
            $today = $now->setTimezone($timezone)->toDateString();
            $todayStart = CarbonImmutable::parse($today, $timezone)->utc();
            $startsOn = $enrollment->starts_on->toDateString();
            $groupId = (string) $enrollment->group_id;
            $startedToday = $startsOn === $today && $planned->contains(fn (Session $session): bool => (string) $session->group_id === $groupId
                && $session->starts_at->greaterThanOrEqualTo($todayStart) && $session->starts_at->lessThanOrEqualTo($now));
            if ($startsOn < $today || $startedToday) {
                continue;
            }
            $notBefore = $enrollment->starts_not_before?->toDateString();
            $lower = $notBefore !== null && $notBefore > $today ? $notBefore : $today;
            $from = CarbonImmutable::parse($lower, $timezone)->utc();
            $first = null;
            foreach ($timeline as $session) {
                if ((string) $session->group_id === $groupId && $session->starts_at->greaterThanOrEqualTo($from) && $session->starts_at->greaterThan($now)) {
                    $first = $session;
                    break;
                }
            }
            $to = $first === null ? $lower : $first->starts_at->setTimezone($timezone)->toDateString();
            if ($to !== $startsOn && ($enrollment->ends_on === null || $to <= $enrollment->ends_on->toDateString())) {
                $starts[(string) $enrollment->id] = ['from' => $startsOn, 'to' => $to];
            }
        }

        return $starts;
    }

    /**
     * The visits and visit applications of the sessions coming back that their cancellation by the organization ended
     * (т. 5.5, 5.6): they stay so, and their families hear the day is free again.
     *
     * @param  list<string>  $sessionIds
     * @return array{list<Enrollment>, list<EnrollmentApplication>}
     */
    private function freedByCancellation(array $sessionIds): array
    {
        if ($sessionIds === []) {
            return [[], []];
        }
        $visitIds = Enrollment::query()->whereIn('session_id', $sessionIds)
            ->whereIn('scope', [EnrollmentScope::Trial->value, EnrollmentScope::Single->value])
            ->where('status', EnrollmentStatus::Cancelled->value)->pluck('id')->all();
        $applicationIds = EnrollmentApplication::query()->whereIn('session_id', $sessionIds)
            ->where('status', EnrollmentApplicationStatus::Rejected->value)->pluck('id')->all();
        $visits = $visitIds === [] ? [] : EnrollmentTransition::query()->whereIn('enrollment_id', $visitIds)
            ->where('metadata->effect', 'visit_cancelled')->pluck('enrollment_id')->unique()->values()->all();
        $applications = $applicationIds === [] ? [] : EnrollmentTransition::query()->whereIn('application_id', $applicationIds)
            ->whereNull('enrollment_id')->where('metadata->effect', 'application_rejected')->pluck('application_id')->unique()->values()->all();

        return [
            $visits === [] ? [] : array_values(Enrollment::query()->whereKey($visits)->orderBy('id')
                ->get(['id', 'application_id', 'session_id', 'child_id'])->all()),
            $applications === [] ? [] : array_values(EnrollmentApplication::query()->whereKey($applications)->orderBy('id')
                ->get(['id', 'parent_user_id', 'child_id', 'session_id'])->all()),
        ];
    }

    /**
     * More children than places after the change: a warning to confirm, not a refusal (decision 7, т. 4.8, 5.8). Who
     * comes counts as for a place — the visits that hold one too.
     *
     * @return Warning|null
     */
    private function overbooked(Session $after, int $attending): ?array
    {
        $capacity = $after->capacity ?? $after->group?->capacity_max;
        if ($after->status !== 'scheduled' || $capacity === null || $attending <= $capacity) {
            return null;
        }

        return [
            'type' => 'overbooked', 'session_id' => (string) $after->id, 'other_session_id' => null, 'needs_confirmation' => true,
            'message' => "На заниманието ще дойдат {$attending} деца, а местата са {$capacity}. Докато това занимание не мине, новите заявки за групата ще чакат за място.",
        ];
    }
}
