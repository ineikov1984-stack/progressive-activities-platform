<?php

namespace App\Domain\Enrollment\Services;

use App\Domain\Catalog\Models\Group;
use App\Domain\Catalog\Models\Session;
use App\Domain\Enrollment\Enums\EnrollmentScope;
use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\EnrollmentPause;
use App\Domain\Enrollment\Models\EnrollmentSessionException;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

/**
 * Which sessions count for an enrollment. Enrollment and pause dates are calendar days in the
 * group's time zone, so a session at 00:30 local time belongs to that local day, not to the UTC one.
 */
final class SessionApplicability
{
    /** @return Collection<int, Session> */
    public function sessionsFor(Enrollment $enrollment): Collection
    {
        if ($enrollment->status === EnrollmentStatus::Cancelled) {
            return new Collection;
        }

        if (in_array($enrollment->scope, [EnrollmentScope::Trial, EnrollmentScope::Single], true)) {
            return Session::query()->whereKey($enrollment->session_id)
                ->where('group_id', $enrollment->group_id)
                ->where('status', 'scheduled')->get();
        }

        $timezone = self::timezone($enrollment);
        $excludedSessionIds = $enrollment->sessionExceptions()
            ->where('kind', 'excluded')->pluck('session_id');
        // Pauses keep the place in the group, but their sessions do not count for the child.
        $pauses = $enrollment->pauses()->whereNull('cancelled_at')->get(['starts_on', 'ends_on']);
        $from = CarbonImmutable::parse($enrollment->starts_on->toDateString(), $timezone)->utc();
        $until = $enrollment->ends_on === null
            ? null
            : CarbonImmutable::parse($enrollment->ends_on->toDateString(), $timezone)->addDay()->utc();

        return Session::query()->where('group_id', $enrollment->group_id)
            ->where('status', 'scheduled')
            ->where('starts_at', '>=', $from)
            ->when($until !== null, fn ($query) => $query->where('starts_at', '<', $until))
            ->when($excludedSessionIds->isNotEmpty(), fn ($query) => $query
                ->whereNotIn('id', $excludedSessionIds))
            ->orderBy('starts_at')->get()
            ->reject(fn (Session $session): bool => $pauses->contains(
                fn (EnrollmentPause $pause): bool => self::within($session, $pause, $timezone),
            ))
            ->values();
    }

    /**
     * Same rule as sessionsFor() for one session, from loaded relations (group, sessionExceptions,
     * pauses) — used when counting who attends a session without a query per enrollment.
     */
    public function appliesTo(Enrollment $enrollment, Session $session): bool
    {
        if ($enrollment->status === EnrollmentStatus::Cancelled || $session->status !== 'scheduled'
            || $session->group_id !== $enrollment->group_id) {
            return false;
        }
        if (in_array($enrollment->scope, [EnrollmentScope::Trial, EnrollmentScope::Single], true)) {
            return $enrollment->session_id === $session->id;
        }

        $timezone = self::timezone($enrollment);
        $day = $session->starts_at->setTimezone($timezone)->toDateString();
        if ($day < $enrollment->starts_on->toDateString()
            || ($enrollment->ends_on !== null && $day > $enrollment->ends_on->toDateString())) {
            return false;
        }
        if ($enrollment->sessionExceptions->contains(
            fn (EnrollmentSessionException $exception): bool => $exception->kind === 'excluded' && $exception->session_id === $session->id,
        )) {
            return false;
        }

        return ! $enrollment->pauses->contains(
            fn (EnrollmentPause $pause): bool => $pause->cancelled_at === null && self::within($session, $pause, $timezone),
        );
    }

    /**
     * The enrollments that may count for these sessions, with what appliesTo() reads already loaded — one query for
     * them and one for each relation, whatever the number of sessions (docs/product/schedule.md, т. 5): of the sessions'
     * groups and not cancelled; for the period only those around the sessions' days, trials and single visits only for
     * one of the sessions; their skipped sessions among these and their pauses not cancelled around those days. The
     * narrowing never changes what appliesTo() says for these sessions. Optionally one child's only.
     *
     * @param  SupportCollection<int, Session>  $sessions
     * @return Collection<int, Enrollment>
     */
    public function candidates(SupportCollection $sessions, ?string $childId = null): Collection
    {
        $sessionIds = [];
        $groupIds = [];
        $earliest = null;
        $latest = null;
        foreach ($sessions as $session) {
            $sessionIds[] = (string) $session->id;
            $groupIds[(string) $session->group_id] = true;
            $earliest = $earliest === null || $session->starts_at->lt($earliest) ? $session->starts_at : $earliest;
            $latest = $latest === null || $session->starts_at->gt($latest) ? $session->starts_at : $latest;
        }
        if ($earliest === null || $latest === null) {
            return new Collection;
        }
        // A day either side: the days of an enrollment are the group's, not UTC's.
        $first = $earliest->utc()->subDay()->toDateString();
        $last = $latest->utc()->addDay()->toDateString();

        return Enrollment::query()
            ->whereIn('group_id', array_keys($groupIds))
            ->whereIn('status', [EnrollmentStatus::Active->value, EnrollmentStatus::Paused->value, EnrollmentStatus::Ended->value])
            ->when($childId !== null, fn ($query) => $query->where('child_id', $childId))
            ->where(fn ($query) => $query
                ->where(fn ($period) => $period->where('scope', EnrollmentScope::FullPeriod->value)
                    ->whereDate('starts_on', '<=', $last)
                    ->where(fn ($open) => $open->whereNull('ends_on')->orWhereDate('ends_on', '>=', $first)))
                ->orWhere(fn ($visit) => $visit->whereIn('scope', [EnrollmentScope::Trial->value, EnrollmentScope::Single->value])
                    ->whereIn('session_id', $sessionIds)))
            ->with([
                'group',
                'sessionExceptions' => fn ($query) => $query->where('kind', 'excluded')->whereIn('session_id', $sessionIds),
                'pauses' => fn ($query) => $query->whereNull('cancelled_at')
                    ->whereDate('starts_on', '<=', $last)->whereDate('ends_on', '>=', $first),
            ])
            ->orderBy('id')
            ->get();
    }

    /**
     * The local day of the group's first session that has not started, on or after a day — where a new
     * enrollment for the period begins (decision of 30.10.2026): ending it before that day is a cancellation,
     * not an end. Null when the group has no such session yet.
     */
    public function firstSessionDay(Group $group, string $fromDay): ?string
    {
        $timezone = $group->schedule_pattern['timezone'] ?? null;
        $timezone = is_string($timezone) && $timezone !== '' ? $timezone : 'Europe/Sofia';
        $session = Session::query()->where('group_id', $group->id)->where('status', 'scheduled')
            ->where('starts_at', '>=', CarbonImmutable::parse($fromDay, $timezone)->startOfDay()->utc())
            ->where('starts_at', '>', now())
            ->orderBy('starts_at')->first(['starts_at']);

        return $session?->starts_at->setTimezone($timezone)->toDateString();
    }

    /** The group's time zone; enrollment dates are calendar days there. */
    public static function timezone(Enrollment $enrollment): string
    {
        $timezone = $enrollment->group?->schedule_pattern['timezone'] ?? null;

        return is_string($timezone) && $timezone !== '' ? $timezone : 'Europe/Sofia';
    }

    private static function within(Session $session, EnrollmentPause $pause, string $timezone): bool
    {
        $day = $session->starts_at->setTimezone($timezone)->toDateString();

        return $day >= $pause->starts_on->toDateString() && $day <= $pause->ends_on->toDateString();
    }
}
