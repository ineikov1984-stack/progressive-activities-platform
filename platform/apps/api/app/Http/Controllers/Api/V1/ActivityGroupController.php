<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\Actions\AssignGroupInstructor;
use App\Domain\Catalog\Actions\EndGroupAssignment;
use App\Domain\Catalog\Actions\SaveGroupSchedule;
use App\Domain\Catalog\Models\Activity;
use App\Domain\Catalog\Models\Group;
use App\Domain\Catalog\Models\InstructorProfile;
use App\Domain\Catalog\Models\Session;
use App\Domain\Catalog\Services\AccessScope;
use App\Domain\Catalog\Services\GroupTeachers;
use App\Domain\Catalog\Services\ScheduleConflictDetector;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;

final class ActivityGroupController extends Controller
{
    public function __construct(
        private readonly ScheduleConflictDetector $conflicts,
        private readonly AccessScope $scope,
    ) {}

    public function index(Request $request, TenantContext $context, string $activity): JsonResponse
    {
        Gate::forUser($request->user())->authorize('catalog.view', Organization::query()->findOrFail($context->organizationId()));
        $record = Activity::query()->findOrFail($activity);
        $groups = Group::query()->whereHas('offering', fn ($query) => $query->where('activity_id', $record->id))
            ->with(['instructors', 'assignments', 'sessions.instructors'])->orderBy('created_at')->orderBy('id')->get();

        $reasons = $this->reasons($request);

        return response()->json(['groups' => $groups->map(fn (Group $group): array => $this->present($group, $reasons))->all()]);
    }

    public function store(Request $request, TenantContext $context, string $activity, SaveGroupSchedule $action): JsonResponse
    {
        Gate::forUser($request->user())->authorize('catalog.manage', Organization::query()->findOrFail($context->organizationId()));
        $group = $action->handle(Activity::query()->findOrFail($activity), $request->except('id'));

        return response()->json(['group' => $this->present($group, $this->reasons($request))], 201);
    }

    public function update(Request $request, TenantContext $context, string $group, SaveGroupSchedule $action): JsonResponse
    {
        Gate::forUser($request->user())->authorize('catalog.manage', Organization::query()->findOrFail($context->organizationId()));
        $record = Group::query()->findOrFail($group);
        $activity = $record->offering()->firstOrFail()->activity()->firstOrFail();
        $updated = $action->handle($activity, [...$request->all(), 'id' => $record->id]);

        return response()->json(['group' => $this->present($updated, $this->reasons($request))]);
    }

    /** Lead or assistant in a group, also a published one (docs/product/team.md, т. 5.3). */
    public function assign(Request $request, string $group, AssignGroupInstructor $action): JsonResponse
    {
        $record = Group::query()->findOrFail($group);

        $updated = $action->handle($request->user(), $record, $request->all());

        return response()->json(['group' => $this->present($updated, $this->reasons($request))], 201);
    }

    public function endAssignment(Request $request, string $group, string $instructor, EndGroupAssignment $action): JsonResponse
    {
        $record = Group::query()->findOrFail($group);

        $updated = $action->handle($request->user(), $record, $instructor, $request->all());

        return response()->json(['group' => $this->present($updated, $this->reasons($request))]);
    }

    /**
     * Why a session changed is often personal ("Треньорът е болен"): the office sees it, that is "schedule.manage" for the
     * whole organization, as in the panel of the schedule (docs/product/schedule-changes.md, т. 3.3, 4.1). "catalog.view"
     * alone does not.
     */
    private function reasons(Request $request): bool
    {
        /** @var User $user */
        $user = $request->user();

        return $this->scope->allowsEverywhere($user, 'schedule.manage');
    }

    /** @return array<string, mixed> */
    private function present(Group $group, bool $reasons): array
    {
        $timezone = $group->schedule_pattern['timezone'] ?? 'Europe/Sofia';

        return [
            'id' => $group->id, 'offering_id' => $group->offering_id, 'version' => $group->version,
            'name' => $group->name, 'age_label' => $group->age_label, 'status' => $group->status,
            'capacity_min' => $group->capacity_min, 'capacity_max' => $group->capacity_max,
            'enrollment_approval_mode' => $group->enrollment_approval_mode,
            // The activity's ways of enrolling this group takes; null — all of them.
            'enrollment_modes' => $group->enrollment_modes,
            'visit_places' => $group->visit_places,
            'location_id' => $group->location_id, 'instructor_ids' => $group->instructors->modelKeys(),
            // Lead or assistant with a period, ended ones included (docs/product/team.md, т. 5).
            'assignments' => $group->assignments->map(fn (InstructorProfile $profile) => [
                'instructor_profile_id' => $profile->id,
                ...Arr::only(GroupTeachers::assignment($profile), ['role', 'starts_on', 'ends_on', 'version']),
            ])->values()->all(),
            'schedule_pattern' => $group->schedule_pattern,
            'price_options' => $group->priceOptions->map(fn ($price) => [
                'id' => $price->id, 'enrollment_mode' => $price->enrollment_mode, 'unit' => $price->unit,
                'amount_cents' => $price->amount_cents, 'currency' => $price->currency,
                'terms' => $price->terms, 'is_available' => $price->is_available,
                'cancellation_policy' => $price->cancellation_policy, 'payment_due' => $price->payment_due,
            ])->all(),
            'sessions' => $group->sessions->sortBy('starts_at')->values()->map(fn (Session $session) => [
                'id' => $session->id, 'version' => $session->version,
                'starts_at' => $session->starts_at->setTimezone($timezone)->toIso8601String(),
                'ends_at' => $session->ends_at->setTimezone($timezone)->toIso8601String(),
                'location_id' => $session->location_id, 'instructor_ids' => $session->instructors->modelKeys(),
                'capacity' => $session->capacity, 'status' => $session->status,
                ...($reasons ? ['change_reason' => $session->change_reason] : []),
            ])->all(),
            'conflicts' => $this->conflicts->forGroup($group),
        ];
    }
}
