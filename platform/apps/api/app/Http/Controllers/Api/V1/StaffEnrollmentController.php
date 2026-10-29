<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\Models\Group;
use App\Domain\Enrollment\Actions\ReviewEnrollmentApplication;
use App\Domain\Enrollment\Enums\EnrollmentApplicationStatus;
use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Models\Child;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\EnrollmentApplication;
use App\Domain\Enrollment\Services\EnrollmentCapacity;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Enrollment work for the organization's team (docs/product/staff-enrollment.md, Б1).
 * Everything is tenant-scoped: models use the tenant global scope and the database RLS.
 * Parent contact details are deliberately not exposed here.
 */
final class StaffEnrollmentController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function applications(Request $request): JsonResponse
    {
        $this->authorizeAbility($request, 'enrollment.view');
        $validated = $request->validate([
            'status' => ['sometimes', Rule::in(['submitted', 'waitlisted'])],
        ]);

        $applications = EnrollmentApplication::query()
            ->where('status', $validated['status'] ?? 'submitted')
            ->with(['child', 'group.offering.activity', 'session'])
            ->oldest()
            ->limit(200)
            ->get();

        return response()->json(['applications' => $applications->map($this->presentApplication(...))->all()]);
    }

    public function review(Request $request, string $application, ReviewEnrollmentApplication $review): JsonResponse
    {
        $this->authorizeAbility($request, 'enrollment.manage');
        $validated = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'waitlist', 'reject'])],
            'version' => ['required', 'integer', 'min:1'],
            'public_reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'internal_note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);
        $record = EnrollmentApplication::query()->findOrFail($application);

        $reviewed = $review->handle(
            $request->user(),
            $record,
            $validated['decision'],
            (int) $validated['version'],
            $validated['public_reason'] ?? null,
            $validated['internal_note'] ?? null,
        );

        return response()->json([
            'application' => $this->presentApplication($reviewed->load(['child', 'group.offering.activity', 'session'])),
        ]);
    }

    public function roster(Request $request, string $group, EnrollmentCapacity $capacity): JsonResponse
    {
        $this->authorizeAbility($request, 'enrollment.view');
        $record = Group::query()->with('offering.activity')->findOrFail($group);

        $enrollments = Enrollment::query()
            ->where('group_id', $record->id)
            ->whereIn('status', [EnrollmentStatus::Active->value, EnrollmentStatus::Paused->value])
            ->with('child')
            ->orderBy('starts_on')
            ->get();
        $waiting = EnrollmentApplication::query()
            ->where('group_id', $record->id)
            ->where('status', EnrollmentApplicationStatus::Waitlisted->value)
            ->with('child')
            ->oldest()
            ->get();

        return response()->json([
            'group' => [
                'id' => $record->id,
                'name' => $record->name,
                'activity_id' => $record->offering?->activity?->id,
                'activity_title' => $record->offering?->activity?->title,
                'capacity_max' => $record->capacity_max,
                'remaining' => $capacity->remainingForGroup($record),
                'waitlist_enabled' => $record->waitlist_enabled,
            ],
            'enrollments' => $enrollments->map(fn (Enrollment $enrollment): array => [
                'id' => $enrollment->id,
                'child' => $this->presentChild($enrollment->child),
                'scope' => $enrollment->scope->value,
                'status' => $enrollment->status->value,
                'starts_on' => $enrollment->starts_on->format('Y-m-d'),
                'ends_on' => $enrollment->ends_on?->format('Y-m-d'),
                'version' => $enrollment->version,
            ])->all(),
            'waitlist' => $waiting->map($this->presentApplication(...))->all(),
        ]);
    }

    /** @return array<string, mixed> */
    private function presentApplication(EnrollmentApplication $application): array
    {
        $activity = $application->group?->offering?->activity;

        return [
            'id' => $application->id,
            'status' => $application->status->value,
            'version' => $application->version,
            'scope' => $application->scope->value,
            'source' => $application->source->value,
            'child' => $this->presentChild($application->child),
            'group' => ['id' => $application->group_id, 'name' => $application->group?->name],
            'activity' => ['id' => $activity?->id, 'title' => $activity?->title],
            'session_starts_at' => $application->session?->starts_at?->toIso8601String(),
            'visit_offer_title' => $application->visit_offer_title_snapshot,
            'price' => [
                'amount_cents' => $application->amount_cents_snapshot,
                'currency' => $application->currency_snapshot,
                'unit' => $application->unit_snapshot,
            ],
            'public_reason' => $application->public_reason,
            'submitted_at' => $application->created_at?->toIso8601String(),
        ];
    }

    /** @return array{id: string|null, name: string|null, class_label: string|null} */
    private function presentChild(?Child $child): array
    {
        return [
            'id' => $child?->id,
            'name' => $child === null ? null : trim($child->first_name.' '.$child->last_name),
            'class_label' => $child?->class_label,
        ];
    }

    private function authorizeAbility(Request $request, string $ability): void
    {
        $organization = Organization::query()->findOrFail($this->context->organizationId());
        Gate::forUser($request->user())->authorize($ability, $organization);
    }
}
