<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Enums\LocationType;
use App\Domain\Catalog\Enums\SpaceUsage;
use App\Domain\Catalog\Models\Group;
use App\Domain\Catalog\Models\InstructorProfile;
use App\Domain\Catalog\Models\Location;
use App\Domain\Catalog\Models\Session;
use App\Domain\Catalog\ValueObjects\PlannedSessionChange;
use App\Domain\Catalog\ValueObjects\ScheduleChangeRequest;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

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
        if ($request->action === 'holiday') {
            $days = $request->days;
            sort($days);
            $from = CarbonImmutable::parse($days[0], self::TIMEZONE)->utc();
            $until = CarbonImmutable::parse($days[count($days) - 1], self::TIMEZONE)->addDay()->utc();

            return Session::query()->with('group')->whereHas('group', fn ($query) => $query->whereNotIn('status', ['draft', 'archived'])
                ->when($request->activities !== [], fn ($groups) => $groups->whereHas('offering', fn ($offering) => $offering->whereIn('activity_id', $request->activities))))
                ->when($request->locations !== [], fn ($query) => $query->whereIn('location_id', $request->locations))
                ->where('starts_at', '>=', $from)->where('starts_at', '<', $until)->orderBy('id')->get()
                ->filter(fn (Session $session): bool => in_array($session->starts_at->setTimezone(self::TIMEZONE)->toDateString(), $days, true))->values();
        }

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
        if ($request->action === 'add') {
            $group = Group::query()->find($request->groupId);
            abort_if($group === null || $group->status === 'draft', 404, 'Заниманието не е намерено.');

            return;
        }
        if ($request->action === 'holiday') {
            if ($request->sessions !== []) {
                $count = Session::query()->whereKey($request->sessionIds())->whereHas('group', fn ($query) => $query->where('status', '!=', 'draft'))->count();
                abort_if($count !== count($request->sessionIds()), 404, 'Заниманието не е намерено.');
            }

            return;
        }
        $visible = $sessions->filter(fn (Session $session): bool => ($session->group->status ?? 'draft') !== 'draft');
        abort_if($visible->count() !== count($request->sessionIds()), 404, 'Заниманието не е намерено.');
    }

    /**
     * Under the locks of the change (or none, for the preview): the version that was seen (409), then the group, the
     * start and the state of each session (422), and what it becomes (т. 4.3, 4.4, 7.4 steps 7–9).
     *
     * @param  Collection<int, Session>|null  $selection
     * @return list<PlannedSessionChange>
     */
    public function plan(ScheduleChangeRequest $request, bool $lock, CarbonImmutable $now, bool $allowEmpty = false, ?Collection $selection = null): array
    {
        if ($request->action === 'holiday') {
            $this->assertDays($request);
        }
        if ($request->action === 'change_location') {
            $this->assertLocation($request->locationId);
        }
        if ($request->action === 'add') {
            return [$this->planAddition($request, $now)];
        }
        $query = Session::query()->with(['group.offering.activity', 'group.assignments', 'instructors'])->whereKey($request->sessionIds())->orderBy('id');
        if ($lock) {
            $query->lockForUpdate();
        }
        $sessions = $request->action === 'holiday' ? ($selection ?? $this->found($request))->load(['group.offering.activity', 'group.assignments', 'instructors']) : $query->get();
        $this->assertFound($request, $sessions);

        $changed = [];
        foreach ($sessions as $session) {
            if ($request->action !== 'holiday' && $session->version !== $request->versionOf((string) $session->id)) {
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
            if ($request->action === 'holiday' && ($session->status !== 'scheduled' || SessionCutoff::passed($session, $now))) {
                continue;
            }
            if ($request->action === 'change_location' && $session->location_id === $request->locationId) {
                // Validate even skipped rows: a stale, archived or started selection is never silently accepted.
                $this->planOne($request, $session, $now);

                continue;
            }
            $planned[] = $this->planOne($request, $session, $now);
        }

        if (count($planned) > 300) {
            throw ValidationException::withMessages(['sessions' => 'Изберете по-малко дни или места — най-много 300 занимания наведнъж.']);
        }
        if ($planned === [] && ! $allowEmpty) {
            throw ValidationException::withMessages(['sessions' => $request->action === 'holiday' ? 'В тези дни няма занимания за отмяна.' : 'Нищо не е променено.']);
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
        $active = array_values(array_filter($planned, fn (PlannedSessionChange $change): bool => $change->after->status === 'scheduled'));
        if ($active === []) {
            return [];
        }
        $ids = array_map(fn (PlannedSessionChange $change): string => $change->sessionId(), $planned);
        $first = min(array_map(fn (PlannedSessionChange $change): CarbonImmutable => $change->after->starts_at, $active));
        $last = max(array_map(fn (PlannedSessionChange $change): CarbonImmutable => $change->after->ends_at, $active));
        $others = Session::query()->where('status', 'scheduled')->whereNotIn('id', $ids)
            ->whereHas('group', fn ($group) => $group->whereNotIn('status', ['draft', 'archived']))
            ->where('starts_at', '<', $last)->where('ends_at', '>', $first)->with(['instructors', 'group.offering.activity'])->orderBy('starts_at')->orderBy('id')->get()->all();
        foreach ($active as $change) {
            $copy = clone $change->after;
            if ($change->syncTeachers !== null) {
                $copy->setRelation('instructors', new Collection(array_map(fn (string $id): InstructorProfile => (new InstructorProfile)->forceFill(['id' => $id]), $change->teachersAfter)));
            }
            $others[] = $copy;
        }
        $profileIds = array_values(array_unique(array_merge([], ...array_map(fn (PlannedSessionChange $change): array => $change->teachersAfter, $active))));
        $names = InstructorProfile::query()->whereKey($profileIds)->pluck('public_name', 'id')->all();
        $locations = Location::query()->whereKey(array_values(array_filter(array_map(fn (PlannedSessionChange $change): ?string => $change->after->location_id, $active))))->get()->keyBy('id');
        $warnings = [];
        foreach ($active as $change) {
            $after = $change->after;
            $sessionId = $change->sessionId();
            $onlyLocation = $change->effect() === 'location_changed';
            $location = $locations->get($after->location_id);
            $physical = $location?->getAttribute('type') === LocationType::Physical ? $location : null;
            foreach ($others as $other) {
                if ((string) $other->id === $sessionId || $other->starts_at->greaterThanOrEqualTo($after->ends_at) || $other->ends_at->lessThanOrEqualTo($after->starts_at)) {
                    continue;
                }
                $otherId = (string) $other->id;
                $shared = array_values(array_intersect($change->teachersAfter, array_map('strval', $other->instructors->modelKeys())));
                if (! $onlyLocation && $shared !== []) {
                    $who = implode(' и ', array_map(fn (string $id): string => (string) ($names[$id] ?? ''), $shared));
                    $warnings[] = ['type' => 'instructor', 'session_id' => $sessionId, 'other_session_id' => $otherId, 'needs_confirmation' => true,
                        'message' => $who.(count($shared) === 1 ? ' води' : ' водят').' друго занимание по това време.'];
                }
                if ($physical !== null && $other->location_id !== null && (string) $other->location_id === (string) $physical->id) {
                    $exclusive = $physical->getAttribute('space_usage') === SpaceUsage::Exclusive;
                    $warnings[] = ['type' => $exclusive ? 'exclusive_location' : 'shared_location', 'session_id' => $sessionId,
                        'other_session_id' => $otherId, 'needs_confirmation' => $exclusive,
                        'message' => $physical->name.' е заета '.self::time($other->starts_at).'–'.self::time($other->ends_at).' от '.self::title($other).'.'];
                }
            }
            $group = $after->group;
            $assigned = $change->syncTeachers ?? ($group === null ? [] : $this->teachers->forSession($group, $after->starts_at, $group->assignments));
            if (! $onlyLocation && ! in_array('lead', array_column($assigned, 'role'), true)) {
                $warnings[] = ['type' => 'no_lead', 'session_id' => $sessionId, 'other_session_id' => null, 'needs_confirmation' => false,
                    'message' => 'В този ден групата няма водещ — назначете заместник.'];
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
        if ($action !== 'change_location' && SessionCutoff::passed($session, $now)) {
            throw ValidationException::withMessages(['sessions' => 'До началото остават под 15 минути — заниманието вече не се мести и не се отменя. Ако не се проведе, отбележете го в „Присъствия“.']);
        }
        $teachersBefore = array_values(array_map('strval', $session->instructors->modelKeys()));
        $after = clone $session;
        $after->version = $session->version + 1;

        if (in_array($action, ['cancel', 'holiday'], true)) {
            if ($session->status !== 'scheduled') {
                throw ValidationException::withMessages(['sessions' => 'Заниманието вече е отменено.']);
            }
            $after->status = 'cancelled';

            return new PlannedSessionChange($session, $after, null, $teachersBefore, $teachersBefore);
        }

        if ($action === 'change_location') {
            if ($session->status !== 'scheduled') {
                throw ValidationException::withMessages(['sessions' => 'Заниманието вече е отменено.']);
            }
            $after->location_id = $request->locationId;

            return new PlannedSessionChange($session, $after, null, $teachersBefore, $teachersBefore, 'location_changed');
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
            if ($start->format('Y-m-d H:i') !== $day.' '.$request->from || $start->lessThanOrEqualTo($now->addMinutes(SessionCutoff::OPENS_BEFORE_MINUTES))) {
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
        $sync = $session->instructors_source === 'assignment' && $group !== null ? $this->teachers->forSession($group, $after->starts_at, $group->assignments) : null;

        return new PlannedSessionChange($session, $after, $sync, $teachersBefore,
            $sync === null ? $teachersBefore : array_map('strval', array_keys($sync)));
    }

    /**
     * All possible groups are locked for a holiday, including a group with no session yet on those days.
     *
     * @param  Collection<int, Session>  $found
     * @return list<string>
     */
    public function groupsToLock(ScheduleChangeRequest $request, Collection $found): array
    {
        if ($request->action === 'add') {
            return $request->groupId === null ? [] : [$request->groupId];
        }
        if ($request->action !== 'holiday') {
            return self::groupIds($found);
        }

        return Group::query()->whereNotIn('status', ['draft', 'archived'])
            ->when($request->activities !== [], fn ($query) => $query->whereHas('offering', fn ($offering) => $offering->whereIn('activity_id', $request->activities)))
            ->orderBy('id')->pluck('id')->map(fn (mixed $id): string => (string) $id)->all();
    }

    /** @param Collection<int, Session>|null $selection */
    public function holidayMatches(ScheduleChangeRequest $request, CarbonImmutable $now, ?Collection $selection = null): bool
    {
        $actual = [];
        foreach ($selection ?? $this->found($request) as $session) {
            if ($session->status === 'scheduled' && $session->starts_at->greaterThan($now)) {
                $actual[(string) $session->id] = $session->version;
            }
        }
        $seen = array_column($request->sessions, 'version', 'id');
        ksort($actual);
        ksort($seen);

        return $actual === $seen;
    }

    /** @return array{started: int, cancelled: int, same_location: int} */
    public function skipped(ScheduleChangeRequest $request, CarbonImmutable $now): array
    {
        $skipped = ['started' => 0, 'cancelled' => 0, 'same_location' => 0];
        if (! in_array($request->action, ['holiday', 'change_location'], true)) {
            return $skipped;
        }
        foreach ($this->found($request) as $session) {
            if ($request->action === 'holiday') {
                if ($session->status === 'cancelled') {
                    $skipped['cancelled']++;
                } elseif (SessionCutoff::passed($session, $now)) {
                    $skipped['started']++;
                }
            } elseif ($session->location_id === $request->locationId) {
                $skipped['same_location']++;
            }
        }

        return $skipped;
    }

    public function assertDays(ScheduleChangeRequest $request): void
    {
        $today = CarbonImmutable::now(self::TIMEZONE)->startOfDay();
        foreach ($request->days as $day) {
            if ($day < $today->toDateString() || $day > $today->addDays(365)->toDateString()) {
                throw ValidationException::withMessages(['days' => 'Изберете от 1 до 31 дни от днес до една година напред.']);
            }
        }
    }

    private function assertLocation(?string $id): void
    {
        if ($id !== null && ! Location::query()->whereKey($id)->exists()) {
            throw ValidationException::withMessages(['location_id' => 'Мястото не е намерено.']);
        }
    }

    private function planAddition(ScheduleChangeRequest $request, CarbonImmutable $now): PlannedSessionChange
    {
        $group = Group::query()->with(['offering.activity', 'assignments'])->find($request->groupId);
        abort_if($group === null || $group->status === 'draft', 404, 'Заниманието не е намерено.');
        if ($group->status === 'archived') {
            throw ValidationException::withMessages(['group_id' => 'Групата е в архива.']);
        }
        $timezone = $group->schedule_pattern['timezone'] ?? self::TIMEZONE;
        $start = CarbonImmutable::parse($request->date.' '.$request->from, $timezone);
        $end = CarbonImmutable::parse($request->date.' '.$request->to, $timezone);
        if ($start->format('Y-m-d H:i') !== $request->date.' '.$request->from || $start->lessThanOrEqualTo($now)
            || $start->toDateString() > $now->setTimezone($timezone)->addDays(365)->toDateString()) {
            throw ValidationException::withMessages(['date' => 'Изберете ден от днес до една година напред.']);
        }
        if ($end->format('Y-m-d H:i') !== $request->date.' '.$request->to || $end->lessThanOrEqualTo($start)) {
            throw ValidationException::withMessages(['to' => 'Краят трябва да е след началото.']);
        }
        $location = $request->locationProvided ? $request->locationId : $group->location_id;
        $this->assertLocation($location);
        if (Session::query()->where('group_id', $group->id)->where('status', 'scheduled')->where('starts_at', '<', $end->utc())->where('ends_at', '>', $start->utc())->exists()) {
            throw ValidationException::withMessages(['sessions' => 'Групата вече има занимание по това време.']);
        }
        // Stable while previewed; an intervening addition gives new warning keys and requires a fresh confirmation.
        // The group lock serializes additions; a later legitimate addition gets a fresh id even at a formerly cancelled time.
        $generation = Session::query()->where('group_id', $group->id)->count();
        $id = Uuid::uuid5(Uuid::NAMESPACE_URL, 'schedule-add:'.$group->organization_id.':'.$group->id.':'.$generation.':'.$start->getTimestamp().':'.$end->getTimestamp().':'.$location)->toString();
        $before = (new Session)->forceFill(['id' => $id, 'organization_id' => $group->organization_id, 'group_id' => $group->id,
            'starts_at' => $start->utc(), 'ends_at' => $end->utc(), 'status' => 'cancelled', 'version' => 0,
            'location_id' => $location, 'capacity' => $group->capacity_max, 'extra' => true, 'instructors_source' => 'assignment']);
        $before->setRelation('group', $group);
        $before->setRelation('instructors', new Collection);
        $after = clone $before;
        $after->status = 'scheduled';
        $after->version = 1;
        $teachers = $this->teachers->forSession($group, $start, $group->assignments);

        return new PlannedSessionChange($before, $after, $teachers, [], array_map('strval', array_keys($teachers)));
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
