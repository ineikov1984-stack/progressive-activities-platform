<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Enums\LocationType;
use App\Domain\Catalog\Enums\SpaceUsage;
use App\Domain\Catalog\Models\InstructorProfile;
use App\Domain\Catalog\Models\Location;
use App\Domain\Catalog\Models\Session;
use App\Domain\Catalog\ValueObjects\PlannedSessionChange;
use App\Domain\Catalog\ValueObjects\ScheduleChangeRequest;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;

/**
 * The Catalog's part of a change of the schedule (docs/product/schedule-changes.md, т. 4, 7.4): which sessions, the
 * checks in their order, what each session becomes and its warnings. The same for the preview (without locks) and the
 * change (under locks), so both say the same. Who comes and the letters are Enrollment's (`ScheduleChangeEffects`).
 *
 * @phpstan-type Warning array{type: string, session_id: string, other_session_id: string|null, needs_confirmation: bool, message: string}
 */
final readonly class ScheduleChangePlanner
{
    public const TIMEZONE = 'Europe/Sofia';

    public function __construct(private GroupTeachers $teachers) {}

    /**
     * The sessions of the request as the organization sees them, without locks: which groups to lock (т. 7.4, step 4).
     *
     * @return Collection<int, Session>
     */
    public function found(ScheduleChangeRequest $request): Collection
    {
        return Session::query()->with('group')->whereKey($request->sessionIds())->orderBy('id')->get();
    }

    /**
     * The groups of the sessions, by id — the order of the locks (т. 4.1а).
     *
     * @param  Collection<int, Session>  $sessions
     * @return list<string>
     */
    public static function groupIds(Collection $sessions): array
    {
        $ids = array_values(array_unique(array_map(fn (Session $session): string => (string) $session->group_id, $sessions->all())));
        sort($ids);

        return $ids;
    }

    /**
     * 404 — a session of another organization, one that does not exist or one of a draft (drafts are not in the schedule).
     * When only one of several is such, the whole request is 404, without telling which (т. 4.1).
     *
     * @param  Collection<int, Session>  $sessions
     */
    public function assertFound(ScheduleChangeRequest $request, Collection $sessions): void
    {
        $visible = $sessions->filter(fn (Session $session): bool => ($session->group->status ?? 'draft') !== 'draft');
        abort_if($visible->count() !== count($request->sessionIds()), 404, 'Заниманието не е намерено.');
    }

    /**
     * Under the locks of the change (or none, for the preview): the version that was seen (409), then the group, the
     * start and the state of each session (422), and what it becomes (т. 4.3, 4.4, 7.4 steps 7–9).
     *
     * @return list<PlannedSessionChange>
     */
    public function plan(ScheduleChangeRequest $request, bool $lock, CarbonImmutable $now): array
    {
        $query = Session::query()->with(['group.offering.activity', 'instructors'])->whereKey($request->sessionIds())->orderBy('id');
        if ($lock) {
            $query->lockForUpdate();
        }
        $sessions = $query->get();
        $this->assertFound($request, $sessions);

        $changed = [];
        foreach ($sessions as $session) {
            if ($session->version !== $request->versionOf((string) $session->id)) {
                $changed[] = (string) $session->id;
            }
        }
        if ($changed !== []) {
            throw new HttpResponseException(response()->json([
                'message' => 'Заниманието е променено междувременно. Заредете графика отново.',
                'changed' => $changed,
            ], 409));
        }

        $planned = [];
        foreach ($sessions as $session) {
            $planned[] = $this->planOne($request, $session, $now);
        }

        return $planned;
    }

    /**
     * The warnings of the sessions as they will be (т. 4.8), against the planned sessions of groups that are neither a
     * draft nor archived: the same teacher (to confirm), the same place — shared (to know) or for one group only (to
     * confirm, `ScheduleConflictDetector` stops on it), and a day without a lead by assignment. A cancelled session warns
     * of nothing. Too many children for the places is Enrollment's (`ScheduleChangeEffects`).
     *
     * @param  list<PlannedSessionChange>  $planned
     * @return list<Warning>
     */
    public function warnings(array $planned): array
    {
        $warnings = [];
        foreach ($planned as $change) {
            $after = $change->after;
            if ($after->status !== 'scheduled') {
                continue;
            }
            $sessionId = $change->sessionId();
            $others = Session::query()->where('status', 'scheduled')->whereKeyNot($sessionId)
                ->whereHas('group', fn ($group) => $group->whereNotIn('status', ['draft', 'archived']))
                ->where('starts_at', '<', $after->ends_at)->where('ends_at', '>', $after->starts_at)
                ->with(['instructors', 'group.offering.activity'])->orderBy('starts_at')->orderBy('id')->get();
            $names = [];
            if ($change->teachersAfter !== []) {
                foreach (InstructorProfile::query()->whereKey($change->teachersAfter)->orderBy('public_name')->get(['id', 'public_name']) as $profile) {
                    $names[(string) $profile->id] = (string) $profile->public_name;
                }
            }
            // An online place and "to be agreed" are not checked.
            $location = $after->location_id === null ? null : Location::query()->find($after->location_id);
            $physical = $location?->getAttribute('type') === LocationType::Physical ? $location : null;

            foreach ($others as $other) {
                $otherId = (string) $other->id;
                $shared = array_values(array_intersect(array_map('strval', array_keys($names)), array_map('strval', $other->instructors->modelKeys())));
                if ($shared !== []) {
                    $who = implode(' и ', array_map(fn (string $profileId): string => $names[$profileId] ?? '', $shared));
                    $warnings[] = [
                        'type' => 'instructor', 'session_id' => $sessionId, 'other_session_id' => $otherId, 'needs_confirmation' => true,
                        'message' => $who.(count($shared) === 1 ? ' води' : ' водят').' друго занимание по това време.',
                    ];
                }
                if ($physical !== null && $other->location_id !== null && (string) $other->location_id === (string) $physical->id) {
                    $exclusive = $physical->getAttribute('space_usage') === SpaceUsage::Exclusive;
                    $warnings[] = [
                        'type' => $exclusive ? 'exclusive_location' : 'shared_location', 'session_id' => $sessionId,
                        'other_session_id' => $otherId, 'needs_confirmation' => $exclusive,
                        'message' => $physical->name.' е заета '.self::time($other->starts_at).'–'.self::time($other->ends_at)
                            .' от '.self::title($other).'.',
                    ];
                }
            }

            $group = $after->group;
            $assigned = $change->syncTeachers ?? ($group === null ? [] : $this->teachers->forSession($group, $after->starts_at));
            if (! in_array('lead', array_column($assigned, 'role'), true)) {
                $warnings[] = [
                    'type' => 'no_lead', 'session_id' => $sessionId, 'other_session_id' => null, 'needs_confirmation' => false,
                    'message' => 'В този ден групата няма водещ — назначете заместник.',
                ];
            }
        }

        return $warnings;
    }

    private function planOne(ScheduleChangeRequest $request, Session $session, CarbonImmutable $now): PlannedSessionChange
    {
        $action = $request->action;
        $group = $session->group;
        if ($group?->status === 'archived') {
            throw ValidationException::withMessages(['sessions' => 'Групата е в архива.']);
        }
        if ($session->starts_at->lessThanOrEqualTo($now)) {
            throw ValidationException::withMessages(['sessions' => 'Заниманието вече е започнало.']);
        }
        $teachersBefore = array_values(array_map('strval', $session->instructors->modelKeys()));
        $after = clone $session;
        $after->version = $session->version + 1;

        if ($action === 'cancel') {
            if ($session->status !== 'scheduled') {
                throw ValidationException::withMessages(['sessions' => 'Заниманието вече е отменено.']);
            }
            $after->status = 'cancelled';

            return new PlannedSessionChange($session, $after, null, $teachersBefore, $teachersBefore);
        }

        if ($action === 'move') {
            if ($session->status !== 'scheduled') {
                throw ValidationException::withMessages(['sessions' => 'Отмененото занимание първо го върнете.']);
            }
            $timezone = $group?->schedule_pattern['timezone'] ?? self::TIMEZONE;
            $today = $now->setTimezone($timezone)->startOfDay();
            $day = (string) $request->date;
            if ($day < $today->toDateString() || $day > $today->addDays(365)->toDateString()) {
                throw ValidationException::withMessages(['date' => 'Изберете ден от днес до една година напред.']);
            }
            $start = CarbonImmutable::parse($day.' '.$request->from, $timezone);
            $end = CarbonImmutable::parse($day.' '.$request->to, $timezone);
            // A missing spring hour must not silently become another time.
            if ($start->format('Y-m-d H:i') !== $day.' '.$request->from || $start->lessThanOrEqualTo($now)) {
                throw ValidationException::withMessages(['date' => 'Изберете бъдещ ден и час.']);
            }
            if ($end->format('Y-m-d H:i') !== $day.' '.$request->to || $end->lessThanOrEqualTo($start)) {
                throw ValidationException::withMessages(['to' => 'Краят трябва да е след началото.']);
            }
            $locationId = $request->locationProvided ? $request->locationId : $session->location_id;
            if ($request->locationProvided && $locationId !== null && ! Location::query()->whereKey($locationId)->exists()) {
                throw ValidationException::withMessages(['location_id' => 'Мястото не е намерено.']);
            }
            if ($start->equalTo($session->starts_at) && $end->equalTo($session->ends_at) && $locationId === $session->location_id) {
                throw ValidationException::withMessages(['sessions' => 'Нищо не е променено.']);
            }
            $after->starts_at = $start->utc();
            $after->ends_at = $end->utc();
            $after->location_id = $locationId;
            if (! $start->equalTo($session->starts_at)) {
                $original = $session->original_starts_at ?? $session->starts_at;
                $after->original_starts_at = $start->equalTo($original) ? null : $original;
            }
        } elseif ($session->status !== 'cancelled') {
            throw ValidationException::withMessages(['sessions' => 'Заниманието не е отменено.']);
        }
        $after->status = 'scheduled';
        // Meanwhile another session of the group may have been put at this time (т. 4.4).
        $taken = Session::query()->where('group_id', $session->group_id)->where('status', 'scheduled')->whereKeyNot($session->id)
            ->where('starts_at', '<', $after->ends_at)->where('ends_at', '>', $after->starts_at)->exists();
        if ($taken) {
            throw ValidationException::withMessages(['sessions' => 'Групата вече има занимание по това време.']);
        }
        // While it was cancelled, the session did not follow the assignments (т. 4.9); changed by hand, it keeps its teachers.
        $sync = $session->instructors_source === 'assignment' && $group !== null ? $this->teachers->forSession($group, $after->starts_at) : null;

        return new PlannedSessionChange($session, $after, $sync, $teachersBefore,
            $sync === null ? $teachersBefore : array_map('strval', array_keys($sync)));
    }

    private static function time(CarbonImmutable $moment): string
    {
        return $moment->setTimezone(self::TIMEZONE)->format('H:i');
    }

    /** „Рисуване · Група 3“ */
    private static function title(Session $session): string
    {
        $activity = $session->group?->offering?->activity?->title;

        return trim(($activity === null ? '' : $activity.' · ').($session->group->name ?? ''));
    }
}
