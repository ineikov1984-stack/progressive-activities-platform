<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\ValueObjects\AuditableReference;
use App\Domain\Catalog\Models\Session;
use App\Domain\Enrollment\Enums\EnrollmentApplicationStatus;
use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Models\Child;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\EnrollmentApplication;
use App\Domain\Enrollment\Models\EnrollmentEvent;
use App\Domain\Enrollment\Models\EnrollmentPause;
use App\Domain\Enrollment\Models\EnrollmentSessionException;
use App\Domain\Enrollment\Models\EnrollmentTransition;
use App\Domain\Enrollment\Services\ContactWindow;
use App\Domain\Enrollment\Services\SessionApplicability;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Support\PhoneNumber;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Organizations\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Presenters\StaffEnrollmentPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The "Подробности" panel: everything about a child in this organization, from one application or
 * enrollment (docs/product/enrollment-contacts-and-emails.md, т. 9.1). Rights are checked before
 * anything is loaded; another organization's records are 404 (tenant scope and RLS).
 *
 * - contacts, the parent's note, age and school: only with enrollment.contacts, contacts and note
 *   only inside the window (т. 3.1), each time with an audit entry that holds identifiers only;
 * - history (who, when, what the parent was told; internal notes): only with enrollment.manage;
 * - "При нас": the child's other groups and open applications here, for everyone who sees enrollments.
 */
final class StaffEnrollmentDetailsController extends Controller
{
    private const HISTORY_LIMIT = 20;

    public function __construct(
        private readonly TenantContext $context,
        private readonly ContactWindow $window,
        private readonly SessionApplicability $sessions,
        private readonly AuditRecorder $audit,
    ) {}

    public function application(Request $request, string $application): JsonResponse
    {
        $access = $this->access($request);
        $record = EnrollmentApplication::query()
            ->with(['child', 'group.offering.activity', 'session', 'enrollment'])
            ->findOrFail($application);
        $contact = $access['contacts'] && $this->window->application($record);
        $enrollmentId = $record->status === EnrollmentApplicationStatus::Approved ? $record->enrollment?->id : null;

        $body = [
            'kind' => 'application',
            'application' => StaffEnrollmentPresenter::application($record, null, $contact) + [
                'schedule' => $record->group?->weeklySlots(),
                'queue_position' => $this->queuePosition($record),
                'enrollment_id' => $enrollmentId,
            ],
            ...$this->childAndParent($record, $access['contacts'], $contact),
            'records' => $this->records($record->child_id, $record->id, $enrollmentId),
            'history' => $access['manage'] ? $this->history([$record->id], $enrollmentId === null ? [] : [$enrollmentId]) : null,
        ];
        if ($contact) {
            $this->recordView($request, 'enrollment_application', $record->id);
        }

        return response()->json($body);
    }

    public function enrollment(Request $request, string $enrollment): JsonResponse
    {
        $access = $this->access($request);
        $record = Enrollment::query()
            ->with([
                'child', 'group.offering.activity', 'application', 'session', 'transferredFrom.group',
                'pauses' => fn ($query) => $query->whereNull('cancelled_at')->orderBy('starts_on'),
                'sessionExceptions' => fn ($query) => $query->where('kind', 'excluded')->with('session'),
                'transferredTo' => fn ($query) => $query->where('status', '!=', EnrollmentStatus::Cancelled->value)->with('group'),
            ])
            ->findOrFail($enrollment);
        $application = $record->application;
        $contact = $access['contacts'] && $this->window->enrollment($record);

        $body = [
            'kind' => 'enrollment',
            'enrollment' => $this->presentEnrollment($record, $access['manage']),
            ...$this->childAndParent($application, $access['contacts'], $contact, $record->child),
            'records' => $this->records($record->child_id, $record->application_id, $record->id),
            'history' => $access['manage'] ? $this->history([$record->application_id], [$record->id]) : null,
        ];
        if ($contact) {
            $this->recordView($request, 'enrollment', $record->id);
        }

        return response()->json($body);
    }

    /**
     * Who the parent is and how to reach them. The parent's account is read only through a record
     * this organization can see.
     *
     * @return array{child: array<string, mixed>, contact_status: string, contact: array<string, mixed>|null, parent_note: string|null}
     */
    private function childAndParent(?EnrollmentApplication $application, bool $contacts, bool $inWindow, ?Child $child = null): array
    {
        $child ??= $application?->child;
        $presented = StaffEnrollmentPresenter::child($child);
        if ($contacts) {
            $presented += ['age' => $this->age($child), 'school_name' => $child?->school_name, 'institution_group' => $child?->institution_group];
        }
        $parent = $inWindow ? $application?->parent : null;

        return [
            'child' => $presented,
            'contact_status' => ! $contacts ? 'not_allowed' : ($inWindow ? 'shown' : 'outside_window'),
            'contact' => $parent === null ? null : [
                'source' => 'account',
                'name' => $parent->name,
                'phone' => $parent->phone,
                'phone_display' => $parent->phone === null ? null : PhoneNumber::display($parent->phone),
                'email' => $parent->email,
                'email_verified' => $parent->hasVerifiedEmail(),
            ],
            'parent_note' => $inWindow ? $application?->parent_note : null,
        ];
    }

    /** @return array<string, mixed> */
    private function presentEnrollment(Enrollment $enrollment, bool $manage): array
    {
        $today = CarbonImmutable::now(SessionApplicability::timezone($enrollment))->toDateString();
        $application = $enrollment->application;
        $activity = $enrollment->group?->offering?->activity;
        $pauses = $enrollment->pauses->filter(fn (EnrollmentPause $pause): bool => $pause->ends_on->toDateString() >= $today)->values();
        $next = $enrollment->transferredTo;
        $previous = $enrollment->transferredFrom;

        return [
            'id' => $enrollment->id,
            'status' => $enrollment->status->value,
            'scope' => $enrollment->scope->value,
            'version' => $enrollment->version,
            'activity' => ['id' => $activity?->id, 'title' => $activity?->title],
            'group' => ['id' => $enrollment->group_id, 'name' => $enrollment->group?->name],
            'schedule' => $enrollment->group?->weeklySlots(),
            'starts_on' => $enrollment->starts_on->format('Y-m-d'),
            'ends_on' => $enrollment->ends_on?->format('Y-m-d'),
            'session_starts_at' => $enrollment->session?->starts_at?->toIso8601String(),
            'price' => [
                'amount_cents' => $application?->amount_cents_snapshot,
                'currency' => $application?->currency_snapshot,
                'unit' => $application?->unit_snapshot,
            ],
            'next_session_at' => $this->nextSession($enrollment),
            'paused_today' => $pauses->contains(fn (EnrollmentPause $pause): bool => $pause->starts_on->toDateString() <= $today),
            // The category can say "health": only for those who manage enrollments (as in the roster).
            'pauses' => $pauses->map(fn (EnrollmentPause $pause): array => [
                'id' => $pause->id,
                'starts_on' => $pause->starts_on->format('Y-m-d'),
                'ends_on' => $pause->ends_on->format('Y-m-d'),
                'reason_category' => $manage ? $pause->reason_category : null,
            ])->all(),
            'skipped_sessions' => $enrollment->sessionExceptions
                ->filter(fn (EnrollmentSessionException $exception): bool => $exception->session !== null && $exception->session->starts_at->isFuture())
                ->sortBy(fn (EnrollmentSessionException $exception): string => $exception->session?->starts_at->toIso8601String() ?? '')
                ->map(fn (EnrollmentSessionException $exception): array => [
                    'session_id' => $exception->session_id,
                    'starts_at' => $exception->session?->starts_at->toIso8601String(),
                ])->values()->all(),
            'moves_to' => $next === null ? null : [
                'group_id' => $next->group_id, 'group_name' => $next->group?->name, 'starts_on' => $next->starts_on->format('Y-m-d'),
            ],
            'transferred_from' => $previous === null ? null : [
                'group_id' => $previous->group_id, 'group_name' => $previous->group?->name, 'last_day' => $previous->ends_on?->format('Y-m-d'),
            ],
        ];
    }

    /**
     * "При нас": the child's other enrollments (current, ahead, ended up to 90 days ago) and open
     * applications in this organization. Tenant scope and RLS keep other organizations out.
     *
     * @return list<array<string, mixed>>
     */
    private function records(string $childId, ?string $exceptApplication, ?string $exceptEnrollment): array
    {
        $oldestEnd = ContactWindow::oldestEnd();
        $enrollments = Enrollment::query()->where('child_id', $childId)
            ->when($exceptEnrollment, fn ($query, string $id) => $query->whereKeyNot($id))
            ->where(fn ($query) => $query
                ->whereIn('status', [EnrollmentStatus::Active->value, EnrollmentStatus::Paused->value])
                ->orWhere(fn ($ended) => $ended->where('status', EnrollmentStatus::Ended->value)
                    ->where(fn ($day) => $day->whereNull('ends_on')->orWhereDate('ends_on', '>=', $oldestEnd))))
            ->with([
                'group.offering.activity', 'session',
                'pauses' => fn ($query) => $query->whereNull('cancelled_at')->orderBy('starts_on'),
                'transferredTo' => fn ($query) => $query->where('status', '!=', EnrollmentStatus::Cancelled->value)->with('group'),
            ])
            ->orderBy('starts_on')
            ->get();
        $applications = EnrollmentApplication::query()->where('child_id', $childId)
            ->when($exceptApplication, fn ($query, string $id) => $query->whereKeyNot($id))
            ->whereIn('status', [EnrollmentApplicationStatus::Submitted->value, EnrollmentApplicationStatus::Waitlisted->value])
            ->with(['group.offering.activity', 'session'])
            ->oldest()
            ->get();

        return [
            ...$enrollments->map(function (Enrollment $enrollment): array {
                $today = CarbonImmutable::now(SessionApplicability::timezone($enrollment))->toDateString();
                $pauses = $enrollment->pauses->filter(fn (EnrollmentPause $pause): bool => $pause->ends_on->toDateString() >= $today);
                $current = $pauses->first(fn (EnrollmentPause $pause): bool => $pause->starts_on->toDateString() <= $today);
                $coming = $pauses->first(fn (EnrollmentPause $pause): bool => $pause->starts_on->toDateString() > $today);
                $next = $enrollment->transferredTo;

                return [
                    'kind' => 'enrollment',
                    'id' => $enrollment->id,
                    'status' => $enrollment->status->value,
                    'scope' => $enrollment->scope->value,
                    'activity_title' => $enrollment->group?->offering?->activity?->title,
                    'group_id' => $enrollment->group_id,
                    'group_name' => $enrollment->group?->name,
                    'schedule' => $enrollment->group?->weeklySlots(),
                    'starts_on' => $enrollment->starts_on->format('Y-m-d'),
                    'ends_on' => $enrollment->ends_on?->format('Y-m-d'),
                    'session_starts_at' => $enrollment->session?->starts_at?->toIso8601String(),
                    'paused_until' => $current?->ends_on->format('Y-m-d'),
                    'next_pause' => $coming === null ? null : ['starts_on' => $coming->starts_on->format('Y-m-d'), 'ends_on' => $coming->ends_on->format('Y-m-d')],
                    'moves_to' => $next === null ? null : ['group_name' => $next->group?->name, 'starts_on' => $next->starts_on->format('Y-m-d')],
                    'next_session_at' => $this->nextSession($enrollment),
                ];
            })->all(),
            ...$applications->map(fn (EnrollmentApplication $application): array => [
                'kind' => 'application',
                'id' => $application->id,
                'status' => $application->status->value,
                'scope' => $application->scope->value,
                'activity_title' => $application->group?->offering?->activity?->title,
                'group_id' => $application->group_id,
                'group_name' => $application->group?->name,
                'schedule' => $application->group?->weeklySlots(),
                'submitted_at' => $application->created_at?->toIso8601String(),
                'session_starts_at' => $application->session?->starts_at?->toIso8601String(),
                'queue_position' => $this->queuePosition($application),
            ])->all(),
        ];
    }

    /**
     * What happened, newest first: the events of the record, with the transition each one names for
     * who did it and what the parent was told. Events from before transition_id have no actor.
     *
     * @param  list<string>  $applicationIds
     * @param  list<string>  $enrollmentIds
     * @return list<array<string, mixed>>
     */
    private function history(array $applicationIds, array $enrollmentIds): array
    {
        $events = EnrollmentEvent::query()
            ->where(fn ($query) => $query->whereIn('application_id', $applicationIds)->orWhereIn('enrollment_id', $enrollmentIds))
            ->orderByDesc('occurred_at')
            ->limit(self::HISTORY_LIMIT)
            ->get()
            // Events of one step share a second: the submission is always the older one.
            ->sortBy([
                fn (EnrollmentEvent $a, EnrollmentEvent $b): int => $b->occurred_at <=> $a->occurred_at,
                fn (EnrollmentEvent $a, EnrollmentEvent $b): int => ($a->type === 'enrollment.application_submitted' ? 1 : 0) <=> ($b->type === 'enrollment.application_submitted' ? 1 : 0),
            ])
            ->values();
        $payloadIds = fn (string $key): array => $events->map(fn (EnrollmentEvent $event): mixed => $event->payload[$key] ?? null)
            ->filter(fn (mixed $id): bool => is_string($id))->unique()->values()->all();

        $transitions = EnrollmentTransition::query()->whereKey($payloadIds('transition_id'))->get()->keyBy('id');
        $parents = EnrollmentApplication::query()->whereKey($events->pluck('application_id')->filter()->unique()->values()->all())
            ->pluck('parent_user_id', 'id');
        $actorIds = $transitions->pluck('actor_user_id')->filter()->unique()->values()->all();
        $names = User::query()->whereKey($actorIds)->pluck('name', 'id');
        $sessions = Session::query()->whereKey($payloadIds('session_id'))->get()->keyBy('id');
        $previous = Enrollment::query()->with('group')->whereKey($payloadIds('transferred_from_id'))->get()->keyBy('id');

        return $events->map(function (EnrollmentEvent $event) use ($transitions, $parents, $names, $sessions, $previous): array {
            $payload = $event->payload;
            $transition = $transitions->get($payload['transition_id'] ?? '');
            $parentId = $parents->get($event->application_id ?? '');
            $actorId = $transition?->actor_user_id;
            $byParent = $actorId !== null && $actorId === $parentId;
            // A decision taken with the parent as actor is the group's automatic approval.
            $automatic = $byParent && in_array($event->type, ['enrollment.application_approved', 'enrollment.application_waitlisted'], true);
            $actor = match (true) {
                $automatic => ['kind' => 'automatic', 'name' => null],
                $byParent, $transition === null && $event->type === 'enrollment.application_submitted' => ['kind' => 'parent', 'name' => null],
                $actorId !== null => ['kind' => 'team', 'name' => $names->get($actorId)],
                default => null,
            };
            $session = $sessions->get($payload['session_id'] ?? '');
            $from = $previous->get($payload['transferred_from_id'] ?? '');

            return [
                'id' => $event->id,
                'type' => $event->type,
                'occurred_at' => $event->occurred_at->toIso8601String(),
                'actor' => $actor,
                'public_reason' => $transition?->public_reason,
                'internal_note' => $transition?->internal_note,
                // Named fields only: the payload itself is never passed through.
                'details' => array_filter([
                    'status' => $event->type === 'enrollment.application_submitted' ? ($payload['status'] ?? null) : null,
                    'starts_on' => $payload['starts_on'] ?? null,
                    'ends_on' => $payload['ends_on'] ?? null,
                    'reason_category' => $payload['reason_category'] ?? null,
                    'resume_on' => $payload['resume_on'] ?? null,
                    'cancelled' => $payload['cancelled'] ?? null,
                    'last_day' => $payload['last_day'] ?? null,
                    'previous_last_day' => $payload['previous_last_day'] ?? null,
                    'effective_date' => $payload['effective_date'] ?? null,
                    'from_group_name' => $from?->group?->name,
                    'session_starts_at' => $session?->starts_at->toIso8601String(),
                    'outcome' => $payload['outcome'] ?? null,
                ], fn (mixed $value): bool => $value !== null),
            ];
        })->all();
    }

    /** The next session that counts for the child (not skipped, not in a pause), if any. */
    private function nextSession(Enrollment $enrollment): ?string
    {
        if ($enrollment->status === EnrollmentStatus::Cancelled) {
            return null;
        }
        $next = $this->sessions->sessionsFor($enrollment)->first(fn (Session $session): bool => $session->starts_at->isFuture());

        return $next?->starts_at->toIso8601String();
    }

    /** The place in the group's waiting list, in the order of the applications (as the list shows it). */
    private function queuePosition(EnrollmentApplication $application): ?int
    {
        if ($application->status !== EnrollmentApplicationStatus::Waitlisted) {
            return null;
        }

        return EnrollmentApplication::query()
            ->where('group_id', $application->group_id)
            ->where('status', EnrollmentApplicationStatus::Waitlisted->value)
            ->where(fn ($query) => $query->where('created_at', '<', $application->created_at)
                ->orWhere(fn ($same) => $same->where('created_at', $application->created_at)->where('id', '<', $application->id)))
            ->count() + 1;
    }

    private function age(?Child $child): ?int
    {
        $birth = $child?->birth_date;

        return $birth === null ? null : max(0, (int) floor($birth->diffInYears(CarbonImmutable::now('Europe/Sofia')->startOfDay())));
    }

    /** Identifiers and the viewer's role only — never the contact itself. */
    private function recordView(Request $request, string $type, string $id): void
    {
        $role = OrganizationMembership::query()->withoutGlobalScopes()
            ->where('organization_id', $this->context->organizationId())
            ->where('user_id', $request->user()?->getAuthIdentifier())
            ->where('status', 'active')
            ->value('role');
        $this->audit->record(AuditAction::EnrollmentContactViewed, new AuditableReference($type, $id), ['role' => $role]);
    }

    /**
     * Rights before any record is read: viewing enrollments is required; contacts and history are extra.
     *
     * @return array{contacts: bool, manage: bool}
     */
    private function access(Request $request): array
    {
        $organization = Organization::query()->findOrFail($this->context->organizationId());
        $gate = Gate::forUser($request->user());
        $gate->authorize('enrollment.view', $organization);

        return [
            'contacts' => $gate->allows('enrollment.contacts', $organization),
            'manage' => $gate->allows('enrollment.manage', $organization),
        ];
    }
}
