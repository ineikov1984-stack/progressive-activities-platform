<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\ValueObjects\AuditableReference;
use App\Domain\Catalog\Enums\ActivityStatus;
use App\Domain\Catalog\Enums\EnrollmentMode;
use App\Domain\Catalog\Enums\PriceUnit;
use App\Domain\Catalog\Models\Activity;
use App\Domain\Catalog\Models\Group;
use App\Domain\Catalog\Models\InstructorProfile;
use App\Domain\Catalog\Models\Offering;
use App\Domain\Catalog\Models\Session;
use App\Domain\Catalog\Services\GroupTeachers;
use App\Domain\Catalog\Services\SessionScheduleGenerator;
use App\Domain\Catalog\Services\TeamAssignments;
use App\Domain\Catalog\ValueObjects\SchedulePattern;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\StaffMember;
use App\Domain\Organizations\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final readonly class SaveGroupSchedule
{
    public function __construct(
        private TenantContext $context,
        private SessionScheduleGenerator $generator,
        private SyncPriceOptions $prices,
        private GroupTeachers $teachers,
        private TeamAssignments $assignments,
        private AuditRecorder $audit,
    ) {}

    /** @param array<string, mixed> $payload */
    public function handle(Activity $activity, array $payload): Group
    {
        abort_unless($activity->organization_id === $this->context->organizationId(), 404);
        Gate::authorize('catalog.manage', Organization::query()->findOrFail($activity->organization_id));

        return DB::transaction(function () use ($activity, $payload): Group {
            Organization::query()->whereKey($activity->organization_id)->lockForUpdate()->firstOrFail();
            $activity = Activity::query()->whereKey($activity->id)->lockForUpdate()->firstOrFail();
            abort_unless($activity->getAttribute('status') === ActivityStatus::Draft, 409, 'Only draft schedules can be edited.');
            $group = isset($payload['id'])
                ? Group::query()->whereHas('offering', fn ($query) => $query->where('activity_id', $activity->id))->lockForUpdate()->findOrFail($payload['id'])
                : new Group;
            $data = $this->validate($activity, $group, $payload);
            if ($group->exists) {
                abort_unless($group->version === (int) $data['version'], 409, 'Group version conflict.');
                abort_unless($group->status === 'draft', 409, 'Only draft groups can be edited.');
            }
            if (array_key_exists('instructor_ids', $data)) {
                // Who teaches the group is an assignment (docs/product/team-rights.md, т. 2.2); the same teachers need nothing.
                $current = $group->exists ? $group->instructors()->pluck('instructor_profiles.id')->map(fn (mixed $id): string => (string) $id)->all() : [];
                $this->assertTeachers($current, $data['instructor_ids']);
            }
            $offering = $group->exists ? $group->offering()->firstOrFail() : $activity->offerings()
                ->when(isset($data['offering_id']), fn ($query) => $query->whereKey($data['offering_id']))
                ->orderBy('created_at')->firstOrFail();
            $previousDefaults = $group->exists ? [
                'location_id' => $group->location_id, 'capacity' => $group->capacity_max,
                'instructor_ids' => $group->instructors()->pluck('instructor_profiles.id')->all(),
            ] : null;
            $group->fill(Arr::only($data, ['name', 'age_label', 'capacity_min', 'capacity_max', 'location_id', 'enrollment_approval_mode', 'enrollment_modes', 'visit_places']));
            if (($data['enrollment_modes'] ?? null) !== null) {
                $this->assertActivityHasModes($offering, $group, $data['enrollment_modes']);
            }
            if (! $group->exists) {
                $group->offering_id = $offering->id;
                $group->capacity_min ??= 1;
                $group->location_id = array_key_exists('location_id', $data) ? $data['location_id'] : $offering->location_id;
                $group->status = 'draft';
                $group->version = 1;
            } else {
                $group->version++;
            }
            if ($group->capacity_max !== null && $group->capacity_max < $group->capacity_min) {
                throw ValidationException::withMessages(['capacity_max' => 'Максималният капацитет трябва да е поне минималния.']);
            }
            if ($group->visit_places !== null && $group->capacity_max !== null && $group->visit_places > $group->capacity_max) {
                throw ValidationException::withMessages([array_key_exists('visit_places', $data) ? 'visit_places' : 'capacity_max' => 'Местата за посещения не може да са повече от максимума деца в групата.']);
            }

            $generated = null;
            if (isset($data['schedule_pattern'])) {
                /** @var array<string, mixed> $schedule */
                $schedule = $data['schedule_pattern'];
                $schedule['timezone'] ??= 'Europe/Sofia';
                $schedule['excluded_dates'] ??= [];
                $schedule['added_dates'] ??= [];
                try {
                    $generated = $this->generator->generate(new SchedulePattern(
                        $schedule['start_date'], $schedule['end_date'], $schedule['frequency'],
                        array_map(fn ($slot) => [...$slot, 'weekday' => (int) $slot['weekday'], 'duration_minutes' => (int) $slot['duration_minutes']], $schedule['slots']),
                        array_map(fn ($slot) => [...$slot, 'duration_minutes' => (int) $slot['duration_minutes']], $schedule['added_dates']),
                    ), $schedule['excluded_dates'], $schedule['timezone']);
                } catch (InvalidArgumentException $exception) {
                    throw ValidationException::withMessages(['schedule_pattern' => $exception->getMessage()]);
                }
                // Slot and exception order does not change the recurring schedule.
                /** @var array<string, mixed> $storedSchedule */
                $storedSchedule = $group->schedule_pattern ?? [];
                if ($group->exists && Arr::sortRecursive($storedSchedule) == Arr::sortRecursive($schedule)) {
                    $generated = null;
                }
                $group->schedule_pattern = $schedule;
            }
            $group->save();
            if (array_key_exists('instructor_ids', $data)) {
                $this->setCurrentTeachers($group, $data['instructor_ids']);
            }
            if ($generated !== null) {
                // An explicit pattern replaces a draft series. Metadata-only edits preserve overrides.
                $group->sessions()->delete();
                $assignments = $group->assignments()->get();
                foreach ($generated as $occurrence) {
                    $session = $group->sessions()->create([
                        'starts_at' => $occurrence['starts_at']->utc(), 'ends_at' => $occurrence['ends_at']->utc(),
                        'location_id' => $group->location_id, 'capacity' => $group->capacity_max,
                    ]);
                    $session->instructors()->sync($this->teachers->forSession($group, $session->starts_at, $assignments));
                }
            } elseif ($previousDefaults !== null) {
                $this->updateInheritedAssignments($group, $previousDefaults, $data);
            }
            foreach ($data['session_overrides'] ?? [] as $index => $override) {
                $session = $group->sessions()->lockForUpdate()->find($override['id']);
                if (! $session) {
                    throw ValidationException::withMessages(["session_overrides.{$index}.id" => 'Занятието не е в тази група.']);
                }
                if (array_key_exists('instructor_ids', $override)) {
                    $this->assertTeachers($session->instructors()->pluck('instructor_profiles.id')->map(fn (mixed $id): string => (string) $id)->all(), $override['instructor_ids']);
                }
                $this->overrideSession($session, $override, $index);
            }
            if (array_key_exists('price_options', $data)) {
                $this->prices->handle($offering, $group, $data['price_options']);
            }

            return $group->refresh()->load(['instructors', 'sessions.instructors', 'priceOptions']);
        });
    }

    /**
     * Changing who teaches places teachers and takes them away: "team.assign", and not oneself with it from an added
     * right alone. The same teachers again need nothing.
     *
     * @param  array<int, string>  $current
     */
    private function assertTeachers(array $current, mixed $wanted): void
    {
        $wanted = array_values(array_unique(array_map(fn (mixed $id): string => (string) $id, is_array($wanted) ? $wanted : [])));
        $current = array_values(array_unique($current));
        sort($wanted);
        sort($current);
        if ($wanted === $current) {
            return;
        }
        $actor = Auth::user();
        if (! $actor instanceof User) {
            throw new AuthorizationException;
        }
        $this->assignments->assertMayAssign($actor, array_values(array_diff($wanted, $current)));
    }

    /**
     * @param  array{location_id: string|null, capacity: int|null, instructor_ids: array<int, string>}  $previous
     * @param  array<string, mixed>  $data
     */
    private function updateInheritedAssignments(Group $group, array $previous, array $data): void
    {
        if (array_intersect(['location_id', 'capacity_max', 'instructor_ids'], array_keys($data)) === []) {
            return;
        }
        foreach ($group->sessions()->where('status', 'scheduled')->lockForUpdate()->get() as $session) {
            if (array_key_exists('location_id', $data) && $session->location_id === $previous['location_id']) {
                $session->location_id = $group->location_id;
            }
            if (array_key_exists('capacity_max', $data) && $session->capacity === $previous['capacity']) {
                $session->capacity = $group->capacity_max;
            }
            if ($session->isDirty()) {
                $session->version++;
                $session->save();
            }
        }
        if (array_key_exists('instructor_ids', $data)) {
            // Sessions changed by hand keep their teachers (docs/product/team.md, т. 5.2).
            $this->teachers->apply($group);
        }
    }

    /**
     * Step 7 of the editor (docs/product/team.md, т. 5.3): the ticked teachers teach the group now. A new one leads with
     * no period, an ended or a later one is opened from now on, and an unticked current one leaves the draft group.
     * Ended and later assignments of teachers who are not ticked stay as they are.
     *
     * @param  array<int, string>  $ids
     */
    private function setCurrentTeachers(Group $group, array $ids): void
    {
        // Under a lock, like assigning: archiving a ticked person at the same moment waits for this save.
        $archived = StaffMember::query()
            ->whereIn('id', InstructorProfile::query()->whereKey($ids)->whereNotNull('staff_member_id')->select('staff_member_id'))
            ->orderBy('id')->lockForUpdate()->get()->contains(fn (StaffMember $person): bool => $person->archived_at !== null);
        if ($archived) {
            throw ValidationException::withMessages(['instructor_ids' => 'Преподавателят е архивиран.']);
        }

        $today = CarbonImmutable::now('Europe/Sofia')->toDateString();
        $rows = $group->assignments()->get()->keyBy(fn (InstructorProfile $profile): string => (string) $profile->getKey());
        foreach ($ids as $id) {
            $row = $rows->get($id);
            $current = $row === null ? null : GroupTeachers::assignment($row);
            if ($current !== null && GroupTeachers::holds($current, $today)) {
                continue;
            }
            if ($current === null) {
                $group->assignments()->attach($id, ['role' => 'lead', 'version' => 1, 'created_by' => Auth::guard('web')->id()]);
            } else {
                $group->assignments()->updateExistingPivot($id, ['starts_on' => null, 'ends_on' => null, 'version' => $current['version'] + 1]);
            }
            $this->audit->record(AuditAction::CatalogAssignmentAdded, new AuditableReference('group', $group->id), [
                'instructor_profile_id' => $id, 'role' => $current['role'] ?? 'lead', 'starts_on' => null, 'ends_on' => null,
            ]);
        }
        foreach ($rows as $id => $row) {
            if (in_array($id, $ids, true) || ! GroupTeachers::holds(GroupTeachers::assignment($row), $today)) {
                continue;
            }
            // A draft group has not run yet, so the row goes; a running group ends assignments instead (т. 5.4).
            $group->assignments()->detach($id);
            $this->audit->record(AuditAction::CatalogAssignmentRemoved, new AuditableReference('group', $group->id), [
                'instructor_profile_id' => $id,
            ]);
        }
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function validate(Activity $activity, Group $group, array $payload): array
    {
        $tenant = $this->context->organizationId();
        $required = $group->exists ? 'sometimes' : 'required';
        $location = ['nullable', 'uuid', Rule::exists('locations', 'id')->where('organization_id', $tenant)];
        $instructor = ['required', 'uuid', 'distinct', Rule::exists('instructor_profiles', 'id')->where('organization_id', $tenant)];

        return Validator::make($payload, [
            'version' => $group->exists ? ['required', 'integer', 'min:1'] : ['prohibited'],
            'offering_id' => $group->exists ? ['prohibited'] : ['sometimes', 'required', 'uuid', Rule::exists('offerings', 'id')->where('organization_id', $tenant)->where('activity_id', $activity->id)],
            'name' => [$required, 'required', 'string', 'max:255'],
            'age_label' => ['sometimes', 'nullable', 'string', 'max:100'],
            'capacity_min' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'capacity_max' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000'],
            // Automatic: an application with a free place is approved at once; a full group puts it on the waiting list.
            'enrollment_approval_mode' => ['sometimes', Rule::in(['manual', 'automatic'])],
            // Which of the activity's ways of enrolling this group takes; null — all of them.
            'enrollment_modes' => ['sometimes', 'nullable', 'array', 'min:1', 'max:5'],
            'enrollment_modes.*' => ['required', 'string', Rule::in(['trial', 'single', 'monthly', 'full_period', 'package']), 'distinct'],
            // Children on a trial or single visit from a price in one session; null — every free place (docs/product/visits.md).
            'visit_places' => ['sometimes', 'nullable', 'integer', 'between:1,500'],
            'location_id' => ['sometimes', ...$location],
            'instructor_ids' => ['sometimes', 'array', 'max:100'],
            'instructor_ids.*' => $instructor,
            'schedule_pattern' => [$required, 'array:start_date,end_date,frequency,slots,added_dates,excluded_dates,timezone'],
            'schedule_pattern.start_date' => ['required_with:schedule_pattern', 'date_format:Y-m-d'],
            'schedule_pattern.end_date' => ['required_with:schedule_pattern', 'date_format:Y-m-d', 'after_or_equal:schedule_pattern.start_date'],
            'schedule_pattern.frequency' => ['required_with:schedule_pattern', Rule::in(['weekly', 'biweekly'])],
            'schedule_pattern.timezone' => ['sometimes', 'required', 'timezone'],
            'schedule_pattern.slots' => ['required_with:schedule_pattern', 'array', 'min:1', 'max:28'],
            'schedule_pattern.slots.*' => ['array:weekday,starts_at,duration_minutes'],
            'schedule_pattern.slots.*.weekday' => ['required', 'integer', 'between:1,7'],
            'schedule_pattern.slots.*.starts_at' => ['required', 'date_format:H:i'],
            'schedule_pattern.slots.*.duration_minutes' => ['required', 'integer', 'between:1,1440'],
            'schedule_pattern.excluded_dates' => ['sometimes', 'array', 'max:1831'],
            'schedule_pattern.excluded_dates.*' => ['required', 'date_format:Y-m-d'],
            'schedule_pattern.added_dates' => ['sometimes', 'array', 'max:366'],
            'schedule_pattern.added_dates.*' => ['array:date,starts_at,duration_minutes'],
            'schedule_pattern.added_dates.*.date' => ['required', 'date_format:Y-m-d'],
            'schedule_pattern.added_dates.*.starts_at' => ['required', 'date_format:H:i'],
            'schedule_pattern.added_dates.*.duration_minutes' => ['required', 'integer', 'between:1,1440'],
            'session_overrides' => [$group->exists ? 'sometimes' : 'prohibited', 'array', 'max:366'],
            'session_overrides.*' => ['array:id,starts_at,ends_at,location_id,instructor_ids,capacity,status,change_reason'],
            'session_overrides.*.id' => ['required', 'uuid', 'distinct'],
            'session_overrides.*.starts_at' => ['sometimes', 'required', 'date_format:Y-m-d\TH:i:sP'],
            'session_overrides.*.ends_at' => ['sometimes', 'required', 'date_format:Y-m-d\TH:i:sP'],
            'session_overrides.*.location_id' => ['sometimes', ...$location],
            'session_overrides.*.instructor_ids' => ['sometimes', 'array', 'max:100'],
            'session_overrides.*.instructor_ids.*' => array_values(array_filter($instructor, fn ($rule) => $rule !== 'distinct')),
            'session_overrides.*.capacity' => ['sometimes', 'nullable', 'integer', 'between:1,100000'],
            'session_overrides.*.status' => ['sometimes', 'required', Rule::in(['scheduled', 'cancelled'])],
            'session_overrides.*.change_reason' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'price_options' => ['sometimes', 'array', 'max:20'],
            'price_options.*' => ['array:enrollment_mode,unit,amount_cents,currency,terms,is_available,cancellation_policy,payment_due'],
            'price_options.*.enrollment_mode' => ['required', Rule::enum(EnrollmentMode::class), 'distinct'],
            'price_options.*.unit' => ['required', Rule::enum(PriceUnit::class)],
            'price_options.*.amount_cents' => ['required', 'integer', 'min:1'],
            'price_options.*.currency' => ['sometimes', 'required', Rule::in(['EUR'])],
            'price_options.*.terms' => ['sometimes', 'nullable', 'string'],
            'price_options.*.is_available' => ['sometimes', 'boolean'],
            'price_options.*.cancellation_policy' => ['sometimes', 'nullable', 'string'],
            'price_options.*.payment_due' => ['sometimes', 'nullable', 'string', 'max:255'],
        ])->validate();
    }

    /**
     * A group takes only ways of enrolling its activity has a price for (step 6 of the editor).
     *
     * @param  array<int, string>  $modes
     */
    private function assertActivityHasModes(Offering $offering, Group $group, array $modes): void
    {
        // The activity's own prices, or this group's.
        $priced = $offering->priceOptions()->where(fn ($query) => $query->whereNull('group_id')
            ->when($group->exists, fn ($own) => $own->orWhere('group_id', $group->id)))->pluck('enrollment_mode')->all();
        foreach ($modes as $index => $mode) {
            if (! in_array($mode, $priced, true)) {
                throw ValidationException::withMessages(["enrollment_modes.{$index}" => 'Дейността няма такъв начин на записване. Добавете го в стъпка 6 „Цени и записване“.']);
            }
        }
    }

    /** @param array<string, mixed> $override */
    private function overrideSession(Session $session, array $override, int $index): void
    {
        $session->fill(Arr::only($override, ['location_id', 'capacity', 'status', 'change_reason']));
        foreach (['starts_at', 'ends_at'] as $field) {
            if (isset($override[$field])) {
                $session->{$field} = CarbonImmutable::parse($override[$field])->utc();
            }
        }
        if ($session->ends_at <= $session->starts_at) {
            throw ValidationException::withMessages(["session_overrides.{$index}.ends_at" => 'Крайният час трябва да е след началния.']);
        }
        if (array_key_exists('instructor_ids', $override)) {
            // Changed by hand, also to no teacher: the group's assignments no longer change this session's teachers.
            $session->setAttribute('instructors_source', 'manual');
        }
        $session->version++;
        $session->save();
        if (array_key_exists('instructor_ids', $override)) {
            $session->instructors()->sync(array_fill_keys(array_unique($override['instructor_ids']), ['role' => 'lead']));
        }
    }
}
