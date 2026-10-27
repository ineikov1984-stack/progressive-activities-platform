<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Enums\ActivityStatus;
use App\Domain\Catalog\Models\Activity;
use App\Domain\Catalog\Models\Group;
use App\Domain\Catalog\Models\Session;
use App\Domain\Catalog\Services\SessionScheduleGenerator;
use App\Domain\Catalog\ValueObjects\SchedulePattern;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final readonly class SaveGroupSchedule
{
    public function __construct(private TenantContext $context, private SessionScheduleGenerator $generator) {}

    /** @param array<string, mixed> $payload */
    public function handle(Activity $activity, array $payload): Group
    {
        abort_unless($activity->organization_id === $this->context->organizationId(), 404);
        Gate::authorize('catalog.manage', Organization::query()->findOrFail($activity->organization_id));

        return DB::transaction(function () use ($activity, $payload): Group {
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
            $offering = $group->exists ? $group->offering()->firstOrFail() : $activity->offerings()
                ->when(isset($data['offering_id']), fn ($query) => $query->whereKey($data['offering_id']))
                ->orderBy('created_at')->firstOrFail();
            $previousDefaults = $group->exists ? [
                'location_id' => $group->location_id, 'capacity' => $group->capacity_max,
                'instructor_ids' => $group->instructors()->pluck('instructor_profiles.id')->all(),
            ] : null;
            $group->fill(Arr::only($data, ['name', 'age_label', 'capacity_min', 'capacity_max', 'location_id']));
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

            $generated = null;
            if (isset($data['schedule_pattern'])) {
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
                if ($group->exists && $group->schedule_pattern == $schedule) {
                    $generated = null;
                }
                $group->schedule_pattern = $schedule;
            }
            $group->save();
            if (array_key_exists('instructor_ids', $data)) {
                $group->instructors()->sync($data['instructor_ids']);
            }
            if ($generated !== null) {
                // An explicit pattern replaces a draft series. Metadata-only edits preserve overrides.
                $group->sessions()->delete();
                $instructors = $group->instructors()->pluck('instructor_profiles.id')->all();
                foreach ($generated as $occurrence) {
                    $session = $group->sessions()->create([
                        'starts_at' => $occurrence['starts_at']->utc(), 'ends_at' => $occurrence['ends_at']->utc(),
                        'location_id' => $group->location_id, 'capacity' => $group->capacity_max,
                    ]);
                    $session->instructors()->sync($instructors);
                }
            } elseif ($previousDefaults !== null) {
                $this->updateInheritedAssignments($group, $previousDefaults, $data);
            }
            foreach ($data['session_overrides'] ?? [] as $index => $override) {
                $session = $group->sessions()->lockForUpdate()->find($override['id']);
                if (! $session) {
                    throw ValidationException::withMessages(["session_overrides.{$index}.id" => 'Занятието не е в тази група.']);
                }
                $this->overrideSession($session, $override, $index);
            }

            return $group->refresh()->load(['instructors', 'sessions.instructors']);
        });
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
        $oldInstructors = $previous['instructor_ids'];
        sort($oldInstructors);
        foreach ($group->sessions()->where('status', 'scheduled')->with('instructors')->lockForUpdate()->get() as $session) {
            if (array_key_exists('location_id', $data) && $session->location_id === $previous['location_id']) {
                $session->location_id = $group->location_id;
            }
            if (array_key_exists('capacity_max', $data) && $session->capacity === $previous['capacity']) {
                $session->capacity = $group->capacity_max;
            }
            $currentInstructors = $session->instructors->modelKeys();
            sort($currentInstructors);
            $changedInstructors = false;
            if (array_key_exists('instructor_ids', $data) && $currentInstructors === $oldInstructors) {
                $changes = $session->instructors()->sync($data['instructor_ids']);
                $changedInstructors = $changes['attached'] !== [] || $changes['detached'] !== [];
            }
            if ($session->isDirty() || $changedInstructors) {
                $session->version++;
                $session->save();
            }
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
        ])->validate();
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
        $session->version++;
        $session->save();
        if (array_key_exists('instructor_ids', $override)) {
            $session->instructors()->sync(array_unique($override['instructor_ids']));
        }
    }
}
