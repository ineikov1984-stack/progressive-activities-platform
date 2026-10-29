<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\ValueObjects\AuditableReference;
use App\Domain\Catalog\Enums\ActivityStatus;
use App\Domain\Catalog\Models\Group;
use App\Domain\Catalog\Models\Session;
use App\Domain\Enrollment\Actions\ReviewEnrollmentApplication;
use App\Domain\Enrollment\Enums\EnrollmentApplicationStatus;
use App\Domain\Enrollment\Enums\EnrollmentScope;
use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Models\Child;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\EnrollmentApplication;
use App\Domain\Enrollment\Models\EnrollmentPause;
use App\Domain\Enrollment\Models\EnrollmentSessionException;
use App\Domain\Enrollment\Services\EnrollmentCapacity;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
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
    private const PAGE_SIZE = 200;

    public function __construct(private readonly TenantContext $context) {}

    public function applications(Request $request): JsonResponse
    {
        $this->authorizeAbility($request, 'enrollment.view');
        $validated = $request->validate([
            'status' => ['sometimes', Rule::in(['submitted', 'waitlisted'])],
        ]);

        $query = EnrollmentApplication::query()->where('status', $validated['status'] ?? 'submitted');
        $total = (clone $query)->count();
        // Oldest first: the team works through the queue in the order parents applied.
        $applications = $query->with(['child', 'group.offering.activity', 'session'])
            ->oldest()
            ->limit(self::PAGE_SIZE)
            ->get();

        return response()->json([
            'applications' => $applications->map($this->presentApplication(...))->all(),
            'total' => $total,
            'has_more' => $total > $applications->count(),
        ]);
    }

    public function review(Request $request, string $application, ReviewEnrollmentApplication $review, AuditRecorder $audit): JsonResponse
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
        // Identifiers only: no names, reasons or notes in the audit log.
        $audit->record(AuditAction::EnrollmentApplicationReviewed, new AuditableReference('enrollment_application', $reviewed->id), [
            'decision' => $validated['decision'], 'status' => $reviewed->status->value, 'group_id' => $reviewed->group_id,
        ]);

        return response()->json([
            'application' => $this->presentApplication($reviewed->load(['child', 'group.offering.activity', 'session'])),
        ]);
    }

    /** Groups the user may see, with places taken and children waiting — two grouped queries, no capacity math per group. */
    public function groups(Request $request): JsonResponse
    {
        $this->authorizeAbility($request, 'enrollment.view');
        $today = CarbonImmutable::now('Europe/Sofia')->toDateString();
        $groups = Group::query()->with('offering.activity')->where('status', '!=', 'archived')
            ->whereHas('offering.activity', fn ($query) => $query->where('status', '!=', ActivityStatus::Archived->value))
            ->get()
            ->sortBy(fn (Group $group): string => ($group->offering->activity->title ?? '').'|'.$group->name)
            ->values();
        $taken = Enrollment::query()->where('scope', EnrollmentScope::FullPeriod->value)
            ->whereIn('status', [EnrollmentStatus::Active->value, EnrollmentStatus::Paused->value, EnrollmentStatus::Ended->value])
            ->where(fn ($query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $today))
            ->selectRaw('group_id, count(*) as aggregate')->groupBy('group_id')->pluck('aggregate', 'group_id');
        $waiting = EnrollmentApplication::query()->where('status', EnrollmentApplicationStatus::Waitlisted->value)
            ->selectRaw('group_id, count(*) as aggregate')->groupBy('group_id')->pluck('aggregate', 'group_id');

        return response()->json([
            'groups' => $groups->map(fn (Group $group): array => [
                'id' => $group->id,
                'name' => $group->name,
                'status' => $group->status,
                'capacity_max' => $group->capacity_max,
                'activity_id' => $group->offering?->activity?->id,
                'activity_title' => $group->offering?->activity?->title,
                'enrolled_for_period' => (int) ($taken[$group->id] ?? 0),
                'waitlist_count' => (int) ($waiting[$group->id] ?? 0),
            ])->all(),
        ]);
    }

    public function roster(Request $request, string $group, EnrollmentCapacity $capacity): JsonResponse
    {
        $this->authorizeAbility($request, 'enrollment.view');
        $record = Group::query()->with('offering.activity')->findOrFail($group);
        $timezone = $record->schedule_pattern['timezone'] ?? 'Europe/Sofia';
        $today = CarbonImmutable::now($timezone)->toDateString();

        // Everyone who holds a place: active, paused, or with an end date still ahead.
        $enrollments = Enrollment::query()
            ->where('group_id', $record->id)
            ->where(fn ($query) => $query
                ->whereIn('status', [EnrollmentStatus::Active->value, EnrollmentStatus::Paused->value])
                ->orWhere(fn ($ended) => $ended->where('status', EnrollmentStatus::Ended->value)->whereDate('ends_on', '>=', $today)))
            ->with([
                'child',
                'pauses' => fn ($query) => $query->whereNull('cancelled_at')->whereDate('ends_on', '>=', $today)->orderBy('starts_on'),
                'sessionExceptions' => fn ($query) => $query->where('kind', 'excluded')->with('session'),
                'transferredTo.group',
            ])
            ->orderBy('starts_on')
            ->get();
        $waiting = EnrollmentApplication::query()
            ->where('group_id', $record->id)
            ->where('status', EnrollmentApplicationStatus::Waitlisted->value)
            ->with('child')
            ->oldest()
            ->get();
        $canManage = Gate::forUser($request->user())->allows('enrollment.manage', Organization::query()->findOrFail($this->context->organizationId()));

        return response()->json([
            'group' => [
                'id' => $record->id,
                'name' => $record->name,
                'activity_id' => $record->offering?->activity?->id,
                'activity_title' => $record->offering?->activity?->title,
                'capacity_max' => $record->capacity_max,
                'enrolled_for_period' => $capacity->placesTaken($record, $today),
                'remaining' => $capacity->remainingForGroup($record),
                'waitlist_enabled' => $record->waitlist_enabled,
            ],
            'enrollments' => $enrollments->map(fn (Enrollment $enrollment): array => $this->presentRosterEnrollment($enrollment, $today))->all(),
            'waitlist' => $waiting->map($this->presentApplication(...))->all(),
            'upcoming_sessions' => $canManage ? Session::query()->where('group_id', $record->id)->where('status', 'scheduled')
                ->where('starts_at', '>', now())->orderBy('starts_at')->limit(12)->get()
                ->map(fn (Session $session): array => ['id' => $session->id, 'starts_at' => $session->starts_at->toIso8601String()])->all() : [],
        ]);
    }

    /**
     * What the team needs on a roster row: no reasons or notes (they stay in the history).
     *
     * @return array<string, mixed>
     */
    private function presentRosterEnrollment(Enrollment $enrollment, string $today): array
    {
        $next = $enrollment->transferredTo;

        return [
            'id' => $enrollment->id,
            'child' => $this->presentChild($enrollment->child),
            'scope' => $enrollment->scope->value,
            'status' => $enrollment->status->value,
            'starts_on' => $enrollment->starts_on->format('Y-m-d'),
            'ends_on' => $enrollment->ends_on?->format('Y-m-d'),
            'version' => $enrollment->version,
            'transferred_from_id' => $enrollment->transferred_from_id,
            'moves_to' => $next === null ? null : [
                'group_id' => $next->group_id, 'group_name' => $next->group?->name, 'starts_on' => $next->starts_on->format('Y-m-d'),
            ],
            'paused_today' => $enrollment->pauses->contains(
                fn (EnrollmentPause $pause): bool => $pause->starts_on->toDateString() <= $today && $pause->ends_on->toDateString() >= $today,
            ),
            'pauses' => $enrollment->pauses->map(fn (EnrollmentPause $pause): array => [
                'id' => $pause->id,
                'starts_on' => $pause->starts_on->format('Y-m-d'),
                'ends_on' => $pause->ends_on->format('Y-m-d'),
                'reason_category' => $pause->reason_category,
            ])->values()->all(),
            'skipped_sessions' => $enrollment->sessionExceptions
                ->filter(fn (EnrollmentSessionException $exception): bool => $exception->session !== null && $exception->session->starts_at->isFuture())
                ->sortBy(fn (EnrollmentSessionException $exception): string => $exception->session?->starts_at->toIso8601String() ?? '')
                ->map(fn (EnrollmentSessionException $exception): array => [
                    'session_id' => $exception->session_id,
                    'starts_at' => $exception->session?->starts_at->toIso8601String(),
                ])->values()->all(),
        ];
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
