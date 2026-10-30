<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\Actions\SaveGroupSchedule;
use App\Domain\Catalog\Models\Activity;
use App\Domain\Catalog\Models\Group;
use App\Domain\Catalog\Models\Session;
use App\Domain\Catalog\Services\ScheduleConflictDetector;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class ActivityGroupController extends Controller
{
    public function __construct(private readonly ScheduleConflictDetector $conflicts) {}

    public function index(Request $request, TenantContext $context, string $activity): JsonResponse
    {
        Gate::forUser($request->user())->authorize('catalog.view', Organization::query()->findOrFail($context->organizationId()));
        $record = Activity::query()->findOrFail($activity);
        $groups = Group::query()->whereHas('offering', fn ($query) => $query->where('activity_id', $record->id))
            ->with(['instructors', 'sessions.instructors'])->orderBy('created_at')->orderBy('id')->get();

        return response()->json(['groups' => $groups->map($this->present(...))->all()]);
    }

    public function store(Request $request, TenantContext $context, string $activity, SaveGroupSchedule $action): JsonResponse
    {
        Gate::forUser($request->user())->authorize('catalog.manage', Organization::query()->findOrFail($context->organizationId()));
        $group = $action->handle(Activity::query()->findOrFail($activity), $request->except('id'));

        return response()->json(['group' => $this->present($group)], 201);
    }

    public function update(Request $request, TenantContext $context, string $group, SaveGroupSchedule $action): JsonResponse
    {
        Gate::forUser($request->user())->authorize('catalog.manage', Organization::query()->findOrFail($context->organizationId()));
        $record = Group::query()->findOrFail($group);
        $activity = $record->offering()->firstOrFail()->activity()->firstOrFail();
        $updated = $action->handle($activity, [...$request->all(), 'id' => $record->id]);

        return response()->json(['group' => $this->present($updated)]);
    }

    /** @return array<string, mixed> */
    private function present(Group $group): array
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
                'capacity' => $session->capacity, 'status' => $session->status, 'change_reason' => $session->change_reason,
            ])->all(),
            'conflicts' => $this->conflicts->forGroup($group),
        ];
    }
}
