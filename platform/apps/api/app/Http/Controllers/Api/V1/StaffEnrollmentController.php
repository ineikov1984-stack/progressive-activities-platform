<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\ValueObjects\AuditableReference;
use App\Domain\Catalog\Enums\ActivityStatus;
use App\Domain\Catalog\Models\Group;
use App\Domain\Catalog\Models\Session;
use App\Domain\Catalog\Services\TeachingScope;
use App\Domain\Enrollment\Actions\ReviewEnrollmentApplication;
use App\Domain\Enrollment\Enums\EnrollmentApplicationStatus;
use App\Domain\Enrollment\Enums\EnrollmentScope;
use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\EnrollmentApplication;
use App\Domain\Enrollment\Models\EnrollmentPause;
use App\Domain\Enrollment\Models\EnrollmentSessionException;
use App\Domain\Enrollment\Services\EnrollmentCapacity;
use App\Domain\Enrollment\Services\EnrollmentChain;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Presenters\StaffEnrollmentPresenter;
use App\Http\Presenters\TeacherRosterPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Enrollment work for the organization's team (docs/product/staff-enrollment.md, Б1).
 * Everything is tenant-scoped: models use the tenant global scope and the database RLS.
 * Parent contact details are not exposed here: the parent's name only to those who see contacts
 * (docs/product/enrollment-contacts-and-emails.md, т. 3.2); phone, email and note in the details.
 */
final class StaffEnrollmentController extends Controller
{
    private const PAGE_SIZE = 200;

    public function __construct(private readonly TenantContext $context, private readonly EnrollmentChain $chain) {}

    public function applications(Request $request): JsonResponse
    {
        $this->authorizeAbility($request, 'enrollment.view');
        $validated = $request->validate([
            'status' => ['sometimes', Rule::in(['submitted', 'waitlisted'])],
            'activity_id' => ['sometimes', 'uuid'],
            'group_id' => ['sometimes', 'uuid'],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);
        $status = $validated['status'] ?? 'submitted';
        $contacts = $this->allows($request, 'enrollment.contacts');
        $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim((string) ($validated['q'] ?? ''))).'%';
        $like = fn (string $column): string => "{$column} ".$this->likeOperator()." ? escape '!'";

        $query = EnrollmentApplication::query()->where('status', $status)
            ->when($validated['group_id'] ?? null, fn ($query, string $groupId) => $query->where('group_id', $groupId))
            ->when($validated['activity_id'] ?? null, fn ($query, string $activityId) => $query
                ->whereHas('group.offering', fn ($offering) => $offering->where('activity_id', $activityId)))
            // The child's name; the parent's name only for those who may see it — otherwise the results
            // would tell which child belongs to which parent.
            ->when(trim((string) ($validated['q'] ?? '')) !== '', fn ($query) => $query->where(fn ($either) => $either
                ->whereHas('child', fn ($child) => $child->whereRaw($like("(first_name || ' ' || coalesce(last_name, ''))"), [$pattern]))
                ->when($contacts, fn ($or) => $or->orWhereHas('parent', fn ($parent) => $parent->whereRaw($like('name'), [$pattern])))));
        $total = (clone $query)->count();
        // Oldest first: the team works through the queue in the order parents applied.
        $applications = $query->with(['child', 'group.offering.activity', 'session', ...($contacts ? ['parent'] : [])])
            ->oldest()
            ->limit(self::PAGE_SIZE)
            ->get();

        // Places in each group today (the same count as the group list): the team sees a full group before deciding.
        $taken = $this->placesTakenByGroup($applications->pluck('group_id')->unique()->values()->all());

        return response()->json([
            'applications' => $applications->map(fn (EnrollmentApplication $application): array => StaffEnrollmentPresenter::application($application, (int) ($taken[$application->group_id] ?? 0), $contacts))->all(),
            'total' => $total,
            'has_more' => $total > $applications->count(),
            'facets' => $this->facets($status),
        ]);
    }

    /**
     * Activities and groups that have applications with this status, with counts — the choices
     * for the filters (two queries, whatever filter is applied).
     *
     * @return array{activities: list<array{id: string, title: string|null, count: int}>, groups: list<array{id: string, name: string, activity_id: string|null, count: int}>}
     */
    private function facets(string $status): array
    {
        $counts = EnrollmentApplication::query()->where('status', $status)
            ->selectRaw('group_id, count(*) as aggregate')->groupBy('group_id')->pluck('aggregate', 'group_id');
        $groups = Group::query()->with('offering.activity')->whereKey($counts->keys()->all())->get()
            ->sortBy(fn (Group $group): string => ($group->offering->activity->title ?? '').'|'.$group->name)->values();
        $activities = [];
        foreach ($groups as $group) {
            $activity = $group->offering?->activity;
            if ($activity === null) {
                continue;
            }
            $activities[$activity->id] ??= ['id' => $activity->id, 'title' => $activity->title, 'count' => 0];
            $activities[$activity->id]['count'] += (int) $counts[$group->id];
        }

        return [
            'activities' => array_values($activities),
            'groups' => $groups->map(fn (Group $group): array => [
                'id' => $group->id, 'name' => $group->name, 'activity_id' => $group->offering?->activity?->id, 'count' => (int) $counts[$group->id],
            ])->all(),
        ];
    }

    /**
     * Places held today by full-period enrollments, per group (one grouped query) — the number the
     * group list shows. Optionally only for some groups.
     *
     * @param  list<string>|null  $groupIds
     * @return Collection<array-key, mixed>
     */
    private function placesTakenByGroup(?array $groupIds = null): Collection
    {
        $today = CarbonImmutable::now('Europe/Sofia')->toDateString();

        return Enrollment::query()->where('scope', EnrollmentScope::FullPeriod->value)
            ->whereIn('status', [EnrollmentStatus::Active->value, EnrollmentStatus::Paused->value, EnrollmentStatus::Ended->value])
            ->where(fn ($query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $today))
            ->when($groupIds !== null, fn ($query) => $query->whereIn('group_id', $groupIds ?? []))
            ->selectRaw('group_id, count(*) as aggregate')->groupBy('group_id')->pluck('aggregate', 'group_id');
    }

    /** Case-insensitive search for Cyrillic names needs ILIKE on PostgreSQL; SQLite (tests) has only LIKE. */
    private function likeOperator(): string
    {
        return DB::getDriverName() === 'pgsql' ? 'ilike' : 'like';
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
            'application' => StaffEnrollmentPresenter::application($reviewed->load(['child', 'group.offering.activity', 'session'])),
        ]);
    }

    /**
     * Groups the user may see, with places taken and children waiting — two grouped queries, no capacity math per group.
     * A teacher (enrollment.view_own) gets only their own groups, without the waiting list (docs/product/team.md, т. 8).
     */
    public function groups(Request $request, TeachingScope $teaching): JsonResponse
    {
        $own = $this->ownGroupsOnly($request, $teaching);
        $groups = Group::query()->with(['offering.activity', 'offering.location', 'location'])->where('status', '!=', 'archived')
            ->whereHas('offering.activity', fn ($query) => $query->where('status', '!=', ActivityStatus::Archived->value))
            ->when($own !== null, fn ($query) => $query->whereKey($own ?? []))
            ->get()
            ->sortBy(fn (Group $group): string => ($group->offering->activity->title ?? '').'|'.$group->name)
            ->values();
        $taken = $this->placesTakenByGroup($own);
        if ($own !== null) {
            return response()->json([
                'groups' => $groups->map(fn (Group $group): array => TeacherRosterPresenter::group($group, (int) ($taken[$group->id] ?? 0)))->all(),
            ]);
        }
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
                'activity_category' => $group->offering?->activity?->category,
                'enrollment_approval_mode' => $group->enrollment_approval_mode,
                'schedule' => $group->weeklySlots(),
                'location_name' => $group->location->name ?? $group->offering?->location?->name,
                'enrolled_for_period' => (int) ($taken[$group->id] ?? 0),
                'waitlist_count' => (int) ($waiting[$group->id] ?? 0),
            ])->all(),
        ]);
    }

    /**
     * How the group's applications are decided: by the team, or at once when there is a place
     * (a full group puts the application on the waiting list). An operational setting, so it can
     * change on a published group too; it applies to applications from now on.
     */
    public function updateApproval(Request $request, string $group, AuditRecorder $audit): JsonResponse
    {
        $this->authorizeAbility($request, 'enrollment.manage');
        $validated = $request->validate([
            'enrollment_approval_mode' => ['required', Rule::in(['manual', 'automatic'])],
        ], ['required' => 'Изберете как се одобряват заявките.', 'in' => 'Изберете как се одобряват заявките.']);
        $record = Group::query()->findOrFail($group);
        $record->forceFill(['enrollment_approval_mode' => $validated['enrollment_approval_mode']])->save();
        // Identifiers and the new mode only.
        $audit->record(AuditAction::EnrollmentGroupApprovalChanged, new AuditableReference('activity_group', $record->id), [
            'enrollment_approval_mode' => $record->enrollment_approval_mode,
        ]);

        return response()->json(['group' => ['id' => $record->id, 'enrollment_approval_mode' => $record->enrollment_approval_mode]]);
    }

    /**
     * The children of a group. A teacher (enrollment.view_own) only of their own group — another group of the
     * organization is 403, another organization's 404 — and only through the teacher's presenter (docs/product/team.md, т. 8).
     */
    public function roster(Request $request, string $group, EnrollmentCapacity $capacity, TeachingScope $teaching): JsonResponse
    {
        $all = $this->allows($request, 'enrollment.view');
        if (! $all) {
            $this->authorizeAbility($request, 'enrollment.view_own');
        }
        $record = Group::query()->with('offering.activity')->findOrFail($group);
        $timezone = $record->schedule_pattern['timezone'] ?? 'Europe/Sofia';
        $today = CarbonImmutable::now($timezone)->toDateString();
        if (! $all) {
            /** @var User $user */
            $user = $request->user();
            abort_unless($teaching->includes($user, $record->id), 403, 'Нямате достъп до тази група.');

            return response()->json(TeacherRosterPresenter::roster($record, $this->rosterEnrollments($record, $today, false), $today));
        }

        $enrollments = $this->rosterEnrollments($record, $today, true);
        $contacts = $this->allows($request, 'enrollment.contacts');
        $waiting = EnrollmentApplication::query()
            ->where('group_id', $record->id)
            ->where('status', EnrollmentApplicationStatus::Waitlisted->value)
            ->with(['child', ...($contacts ? ['parent'] : [])])
            ->oldest()
            ->get();
        $canManage = $this->allows($request, 'enrollment.manage');

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
                'enrollment_approval_mode' => $record->enrollment_approval_mode,
            ],
            'enrollments' => $enrollments->map(fn (Enrollment $enrollment): array => $this->presentRosterEnrollment($enrollment, $today, $canManage))->all(),
            'waitlist' => $waiting->map(fn (EnrollmentApplication $application): array => StaffEnrollmentPresenter::application($application, null, $contacts))->all(),
            'upcoming_sessions' => $canManage ? Session::query()->where('group_id', $record->id)->where('status', 'scheduled')
                ->where('starts_at', '>', now())->orderBy('starts_at')->limit(12)->get()
                ->map(fn (Session $session): array => ['id' => $session->id, 'starts_at' => $session->starts_at->toIso8601String()])->all() : [],
        ]);
    }

    /**
     * Everyone who holds a place in the group: active, paused, or with an end date still ahead; with the pauses that
     * have not passed and the skipped sessions, and for the team where the child moves to.
     *
     * @return Collection<int, Enrollment>
     */
    private function rosterEnrollments(Group $group, string $today, bool $withMoves): Collection
    {
        return Enrollment::query()
            ->where('group_id', $group->id)
            ->where(fn ($query) => $query
                ->whereIn('status', [EnrollmentStatus::Active->value, EnrollmentStatus::Paused->value])
                ->orWhere(fn ($ended) => $ended->where('status', EnrollmentStatus::Ended->value)->whereDate('ends_on', '>=', $today)))
            ->with([
                'child',
                'pauses' => fn ($query) => $query->whereNull('cancelled_at')->whereDate('ends_on', '>=', $today)->orderBy('starts_on'),
                'sessionExceptions' => fn ($query) => $query->where('kind', 'excluded')->with('session'),
                ...($withMoves ? ['transferredTo' => fn ($query) => $query->where('status', '!=', EnrollmentStatus::Cancelled->value)->with('group')] : []),
            ])
            ->orderBy('starts_on')
            ->get();
    }

    /**
     * What the team needs on a roster row: no reasons or notes. The pause category (it can say
     * "health") only for those who manage enrollments, not for finance or viewers.
     *
     * @return array<string, mixed>
     */
    private function presentRosterEnrollment(Enrollment $enrollment, string $today, bool $canManage): array
    {
        // A → B cancelled → C: the child moves to C.
        $next = $enrollment->transferredTo ?? ($enrollment->status === EnrollmentStatus::Ended ? $this->chain->next($enrollment) : null);

        return [
            'id' => $enrollment->id,
            'child' => StaffEnrollmentPresenter::child($enrollment->child),
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
                'reason_category' => $canManage ? $pause->reason_category : null,
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

    /**
     * Null — every group (enrollment.view, as before); otherwise only the user's own groups (enrollment.view_own). Neither → 403.
     *
     * @return list<string>|null
     */
    private function ownGroupsOnly(Request $request, TeachingScope $teaching): ?array
    {
        if ($this->allows($request, 'enrollment.view')) {
            return null;
        }
        $this->authorizeAbility($request, 'enrollment.view_own');
        /** @var User $user */
        $user = $request->user();

        return $teaching->groupsFor($user);
    }

    private function authorizeAbility(Request $request, string $ability): void
    {
        $organization = Organization::query()->findOrFail($this->context->organizationId());
        Gate::forUser($request->user())->authorize($ability, $organization);
    }

    private function allows(Request $request, string $ability): bool
    {
        return Gate::forUser($request->user())->allows($ability, Organization::query()->findOrFail($this->context->organizationId()));
    }
}
