<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\ValueObjects\AuditableReference;
use App\Domain\Catalog\Enums\ActivityStatus;
use App\Domain\Catalog\Models\Group;
use App\Domain\Catalog\Models\Session;
use App\Domain\Catalog\Services\AccessScope;
use App\Domain\Catalog\Services\TeachingScope;
use App\Domain\Enrollment\Actions\ReviewEnrollmentApplication;
use App\Domain\Enrollment\Enums\EnrollmentApplicationStatus;
use App\Domain\Enrollment\Enums\EnrollmentScope;
use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\EnrollmentApplication;
use App\Domain\Enrollment\Models\EnrollmentPause;
use App\Domain\Enrollment\Models\EnrollmentSessionException;
use App\Domain\Enrollment\Services\ChildCard;
use App\Domain\Enrollment\Services\EnrolledChildSearch;
use App\Domain\Enrollment\Services\EnrollmentCapacity;
use App\Domain\Enrollment\Services\EnrollmentChain;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Presenters\StaffEnrollmentPresenter;
use App\Http\Presenters\TeacherRosterPresenter;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
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

    public function __construct(
        private readonly TenantContext $context,
        private readonly EnrollmentChain $chain,
        private readonly AccessScope $scope,
    ) {}

    /**
     * The applications in the groups the user sees (docs/product/team-rights.md, т. 9): with a narrower scope only
     * those; the parent's name and the search by it only in the groups where the user sees contacts.
     */
    public function applications(Request $request): JsonResponse
    {
        $user = $this->requireRight($request, 'enrollment.view');
        $visible = $this->scope->groups($user, 'enrollment.view');
        $contactGroups = $this->scope->groups($user, 'contacts.view');
        $validated = $request->validate([
            'status' => ['sometimes', Rule::in(['submitted', 'waitlisted'])],
            'activity_id' => ['sometimes', 'uuid'],
            'group_id' => ['sometimes', 'uuid'],
            'q' => ['sometimes', 'nullable', 'string', 'max:100', self::readableText()],
        ]);
        $status = $validated['status'] ?? 'submitted';
        $contacts = $contactGroups !== [];
        $seesContacts = fn (EnrollmentApplication $application): bool => $contactGroups === null || in_array($application->group_id, $contactGroups, true);
        $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim((string) ($validated['q'] ?? ''))).'%';
        $like = fn (string $column): string => "{$column} ".$this->likeOperator()." ? escape '!'";

        $query = EnrollmentApplication::query()->where('status', $status)
            ->when($visible !== null, fn ($query) => $query->whereIn('group_id', $visible ?? []))
            ->when($validated['group_id'] ?? null, fn ($query, string $groupId) => $query->where('group_id', $groupId))
            ->when($validated['activity_id'] ?? null, fn ($query, string $activityId) => $query
                ->whereHas('group.offering', fn ($offering) => $offering->where('activity_id', $activityId)))
            // The child's name; the parent's name only for those who may see it — otherwise the results
            // would tell which child belongs to which parent.
            ->when(trim((string) ($validated['q'] ?? '')) !== '', fn ($query) => $query->where(fn ($either) => $either
                ->whereHas('child', fn ($child) => $child->whereRaw($like("(first_name || ' ' || coalesce(last_name, ''))"), [$pattern]))
                ->when($contacts, fn ($or) => $or->orWhere(fn ($byParent) => $byParent
                    ->whereHas('parent', fn ($parent) => $parent->whereRaw($like('name'), [$pattern]))
                    ->when($contactGroups !== null, fn ($inGroups) => $inGroups->whereIn('group_id', $contactGroups ?? []))))));
        $total = (clone $query)->count();
        // Oldest first: the team works through the queue in the order parents applied.
        $applications = $query->with(['child', 'group.offering.activity', 'session', ...($contacts ? ['parent'] : [])])
            ->oldest()
            ->limit(self::PAGE_SIZE)
            ->get();

        // Places in each group today (the same count as the group list): the team sees a full group before deciding.
        $taken = $this->placesTakenByGroup($applications->pluck('group_id')->unique()->values()->all());

        return response()->json([
            'applications' => $applications->map(fn (EnrollmentApplication $application): array => StaffEnrollmentPresenter::application($application, (int) ($taken[$application->group_id] ?? 0), $seesContacts($application)))->all(),
            'total' => $total,
            'has_more' => $total > $applications->count(),
            'facets' => $this->facets($status, $visible),
        ]);
    }

    /**
     * The enrolled children by name, for the filter "Дете" of the schedule (docs/product/schedule.md, т. 3.5): only in
     * the groups where the user sees enrollments — the same groups as that filter — so the identifier found is the one
     * `GET /schedule?child=` takes. The name and the class only; nothing is written to the audit log.
     */
    public function children(Request $request, EnrolledChildSearch $search): JsonResponse
    {
        $user = $this->requireRight($request, 'enrollment.view');
        $hint = 'Напишете поне 2 букви от името.';
        $validated = $request->validate(
            ['q' => ['required', 'string', 'min:2', 'max:80', self::readableText()]],
            ['q.required' => $hint, 'q.string' => $hint, 'q.min' => $hint, 'q.max' => 'Напишете до 80 знака.'],
        );

        return response()->json([
            'children' => $search->find((string) $validated['q'], $this->scope->groups($user, 'enrollment.view')),
        ]);
    }

    /**
     * The card of a child in the schedule (docs/product/schedule.md, т. 4.1): only through the child's enrollments in the
     * groups where the user sees the children — the rule of the session's panel (`AccessScope::scheduleChildren`):
     * "enrollment.view" in the group or one's own group. Without such an enrollment — another organization's child, a
     * child elsewhere or no right at all — 404, never 403, so that nothing tells the child exists. For each group what the
     * user may do there: show the parent's phone ("contacts.view"), skip a session and pause ("enrollment.enroll", as the
     * routes `skipped-sessions` and `pauses`). No contacts, money or health; nothing is written to the audit log.
     */
    public function child(Request $request, string $child, ChildCard $cards): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $card = $cards->find(Str::lower($child), $this->scope->scheduleChildren($user));
        if ($card === null) {
            abort(404, 'Детето не е намерено.');
        }
        $groups = [];
        foreach ($card['groups'] as $group) {
            $enrols = $this->scope->allows($user, 'enrollment.enroll', $group['group_id']);
            $groups[] = [...$group, 'abilities' => [
                'contacts' => $this->scope->allows($user, 'contacts.view', $group['group_id']),
                'skip' => $enrols,
                'pause' => $enrols,
            ]];
        }

        return response()->json([...$card, 'groups' => $groups]);
    }

    /**
     * Activities and groups that have applications with this status, with counts — the choices
     * for the filters (two queries, whatever filter is applied).
     *
     * @param  list<string>|null  $visible  the groups the user sees; null — all
     * @return array{activities: list<array{id: string, title: string|null, count: int}>, groups: list<array{id: string, name: string, activity_id: string|null, count: int}>}
     */
    private function facets(string $status, ?array $visible): array
    {
        $counts = EnrollmentApplication::query()->where('status', $status)
            ->when($visible !== null, fn ($query) => $query->whereIn('group_id', $visible ?? []))
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

    /**
     * A search text that is not UTF-8 is refused before the database: PostgreSQL would fail on it, and the error with the
     * text would stay in the log.
     */
    private static function readableText(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && ! mb_check_encoding($value, 'UTF-8')) {
                $fail('Търсенето има знаци, които не могат да се прочетат.');
            }
        };
    }

    /** Case-insensitive search for Cyrillic names needs ILIKE on PostgreSQL; SQLite (tests) has only LIKE. */
    private function likeOperator(): string
    {
        return DB::getDriverName() === 'pgsql' ? 'ilike' : 'like';
    }

    public function review(Request $request, string $application, ReviewEnrollmentApplication $review, AuditRecorder $audit): JsonResponse
    {
        $user = $this->requireRight($request, 'enrollment.enroll');
        $validated = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'waitlist', 'reject'])],
            'version' => ['required', 'integer', 'min:1'],
            'public_reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'internal_note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);
        $record = EnrollmentApplication::query()->findOrFail($application);
        $this->requireGroup($user, 'enrollment.enroll', $record->group_id);

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
        ['team' => $team, 'own' => $own] = $this->visibleGroups($request, $teaching);
        $visible = $team === null ? null : array_values(array_unique([...$team, ...$own]));
        $groups = Group::query()->with(['offering.activity', 'offering.location', 'location'])->where('status', '!=', 'archived')
            ->whereHas('offering.activity', fn ($query) => $query->where('status', '!=', ActivityStatus::Archived->value))
            ->when($visible !== null, fn ($query) => $query->whereKey($visible ?? []))
            ->get()
            ->sortBy(fn (Group $group): string => ($group->offering->activity->title ?? '').'|'.$group->name)
            ->values();
        $taken = $this->placesTakenByGroup($visible);
        $waiting = EnrollmentApplication::query()->where('status', EnrollmentApplicationStatus::Waitlisted->value)
            ->selectRaw('group_id, count(*) as aggregate')->groupBy('group_id')->pluck('aggregate', 'group_id');

        // The groups the user teaches through the teacher's presenter; any other the team's (docs/product/team.md, т. 8).
        return response()->json([
            'groups' => $groups->map(fn (Group $group): array => in_array($group->id, $own, true)
                ? TeacherRosterPresenter::group($group, (int) ($taken[$group->id] ?? 0)) : [
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
                ])->values()->all(),
        ]);
    }

    /**
     * How the group's applications are decided: by the team, or at once when there is a place
     * (a full group puts the application on the waiting list). An operational setting, so it can
     * change on a published group too; it applies to applications from now on. A setting of the group: it is changed by
     * whoever changes the activities (the owner, the administrator, the coordinator), not by "Записва деца", which
     * decides the applications (DECISIONS 31.10.2026).
     */
    public function updateApproval(Request $request, string $group, AuditRecorder $audit): JsonResponse
    {
        $user = $this->requireRight($request, 'catalog.manage');
        $validated = $request->validate([
            'enrollment_approval_mode' => ['required', Rule::in(['manual', 'automatic'])],
        ], ['required' => 'Изберете как се одобряват заявките.', 'in' => 'Изберете как се одобряват заявките.']);
        $record = Group::query()->findOrFail($group);
        $this->requireGroup($user, 'catalog.manage', $record->id);
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
        /** @var User $user */
        $user = $request->user();
        $access = $this->scope->access($user);
        $viewer = $access->has('enrollment.view');
        if (! $viewer) {
            $this->authorizeAbility($request, 'enrollment.view_own');
        }
        $record = Group::query()->with('offering.activity')->findOrFail($group);
        $timezone = $record->schedule_pattern['timezone'] ?? 'Europe/Sofia';
        $today = CarbonImmutable::now($timezone)->toDateString();
        // A teacher's own group through the teacher's roster, also with "enrollment.view" added; the team's roster in
        // the other groups of the user's scope (docs/product/team-rights.md, т. 9).
        $teachersOwn = ! $access->fromRole('enrollment.view') && $this->allows($request, 'enrollment.view_own') && $teaching->includes($user, $record->id);
        if ($teachersOwn || ! $viewer || ! $this->scope->allows($user, 'enrollment.view', $record->id)) {
            abort_unless($this->allows($request, 'enrollment.view_own') && $teaching->includes($user, $record->id), 403, 'Нямате достъп до тази група.');

            // With "sees the phone" added in the group, each row offers to show the parent's contact (т. 6).
            return response()->json(TeacherRosterPresenter::roster(
                $record, $this->rosterEnrollments($record, $today, false), $today, $this->scope->allows($user, 'contacts.view', $record->id),
            ));
        }

        $enrollments = $this->rosterEnrollments($record, $today, true);
        $contacts = $this->scope->allows($user, 'contacts.view', $record->id);
        $waiting = EnrollmentApplication::query()
            ->where('group_id', $record->id)
            ->where('status', EnrollmentApplicationStatus::Waitlisted->value)
            ->with(['child', ...($contacts ? ['parent'] : [])])
            ->oldest()
            ->get();
        $canManage = $this->scope->allows($user, 'enrollment.enroll', $record->id);

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
     * The groups the user sees, and which of them through the teacher's presenter:
     * - "enrollment.view" from the role — those of the scope (null — every group, as before), all the team's way;
     * - a teacher (enrollment.view_own) — their own groups, current and coming, the teacher's way; with
     *   "enrollment.view" added, also the other groups of the scope, the team's way;
     * - neither → 403.
     *
     * @return array{team: list<string>|null, own: list<string>} `team` null — every group
     */
    private function visibleGroups(Request $request, TeachingScope $teaching): array
    {
        /** @var User $user */
        $user = $request->user();
        $access = $this->scope->access($user);
        if ($access->fromRole('enrollment.view')) {
            return ['team' => $this->scope->groups($user, 'enrollment.view'), 'own' => []];
        }
        $this->authorizeAbility($request, 'enrollment.view_own');
        $own = $teaching->groupsFor($user);
        if (! $access->has('enrollment.view')) {
            return ['team' => [], 'own' => $own];
        }
        $added = $this->scope->groups($user, 'enrollment.view');

        return ['team' => $added === null ? null : array_values(array_diff($added, $own)), 'own' => $own];
    }

    /** The user has the right somewhere — otherwise 403 before anything is read, as the Gate refuses. */
    private function requireRight(Request $request, string $ability): User
    {
        /** @var User $user */
        $user = $request->user();
        if (! $this->scope->access($user)->has($ability)) {
            throw new AuthorizationException;
        }

        return $user;
    }

    /** The right holds in the record's group — the scope (docs/product/team-rights.md, т. 4.4). */
    private function requireGroup(User $user, string $ability, string $groupId): void
    {
        abort_unless($this->scope->allows($user, $ability, $groupId), 403, 'Нямате достъп до тази група.');
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
