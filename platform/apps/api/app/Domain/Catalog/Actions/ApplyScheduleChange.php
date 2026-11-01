<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\ValueObjects\AuditableReference;
use App\Domain\Catalog\Models\Group;
use App\Domain\Catalog\Models\ScheduleChange;
use App\Domain\Catalog\Models\Session;
use App\Domain\Catalog\Services\ScheduleChangePlanner;
use App\Domain\Catalog\Services\ScheduleSubstituteItems;
use App\Domain\Catalog\Services\TeacherRecipients;
use App\Domain\Catalog\ValueObjects\PlannedSessionChange;
use App\Domain\Catalog\ValueObjects\ScheduleChangeRequest;
use App\Domain\Enrollment\Services\ScheduleChangeEffects;
use App\Domain\Enrollment\ValueObjects\ScheduleEffects;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A change of the schedule (docs/product/schedule-changes.md, т. 4, 7.3): in one transaction the sessions, the history
 * of the change and of each session, what it does to the enrollments, the events of the letters and one row of the
 * audit log — or nothing at all. The order (т. 4.1а, 7.4): the sessions are read to know their groups; the groups are
 * locked by id; then the key for a repeat is looked up, so that a second request with the same key waits for the first
 * and gets its answer instead of a 409; only then 404, the applications and enrollments, the sessions, 409 and 422.
 * The audit log has identifiers and numbers, never the reason (it may be personal: "Треньорът е болен").
 *
 * @phpstan-import-type Warning from ScheduleChangePlanner
 */
final readonly class ApplyScheduleChange
{
    public function __construct(
        private ScheduleChangePlanner $planner,
        private PreviewScheduleChange $preview,
        private ScheduleChangeEffects $effects,
        private TeacherRecipients $teachers,
        private AuditRecorder $audit,
        private TenantContext $context,
        private ScheduleSubstituteItems $substituteItems,
    ) {}

    /** @return array{status: int, body: array<string, mixed>} 201 — the change; 200 — the stored answer of a repeat */
    public function handle(User $actor, ScheduleChangeRequest $request, string $idempotencyKey): array
    {
        $hash = $request->hash();
        try {
            return DB::transaction(fn (): array => $this->apply($actor, $request, $idempotencyKey, $hash));
        } catch (QueryException $exception) {
            $state = (string) ($exception->errorInfo[0] ?? $exception->getCode());
            // Another change holds these groups longer than the wait.
            if (in_array($state, ['55P03', '40P01'], true)) {
                abort(409, 'Някой друг променя тези групи в момента. Опитайте отново след малко.');
            }
            // The last guard: the same key at the same time for other groups. The change is undone; the stored one answers.
            $message = $exception->getMessage();
            if (str_contains($message, 'schedule_changes_actor_key_unique') || str_contains($message, 'schedule_changes.idempotency_key')) {
                $replay = $this->replay($actor, $idempotencyKey, $hash);
                if ($replay !== null) {
                    return ['status' => 200, 'body' => $replay];
                }
            }

            throw $exception;
        }
    }

    /** @return array{status: int, body: array<string, mixed>} */
    private function apply(User $actor, ScheduleChangeRequest $request, string $idempotencyKey, string $hash): array
    {
        if (DB::getDriverName() === 'pgsql') {
            // A whole number of milliseconds, never text from outside.
            $timeout = max(1, (int) config('platform.schedule_lock_timeout_ms', 5000));
            DB::statement("SET LOCAL lock_timeout = '{$timeout}ms'");
        }
        $found = $this->planner->found($request);
        $baseIds = array_values(array_unique([...$request->sessionIds(), ...array_map('strval', $found->modelKeys())]));
        $touchesRequests = in_array($request->action, ['substitute', 'move', 'cancel', 'holiday'], true);
        $groupIds = $this->planner->groupsToLock($request, $found);
        if ($touchesRequests) {
            $groupIds = $this->substituteItems->groups($baseIds, $groupIds, $request->requestId);
        }
        Group::query()->whereKey($groupIds)->orderBy('id')->lockForUpdate()->get(['id']);
        $replay = $this->replay($actor, $idempotencyKey, $hash);
        if ($replay !== null) {
            return ['status' => 200, 'body' => $replay];
        }
        $this->planner->assertFound($request, $found);
        $sessionIds = array_values(array_unique([...$request->sessionIds(), ...array_map('strval', $found->modelKeys())]));
        $items = $touchesRequests ? $this->substituteItems->lock($sessionIds, $request->requestId) : collect();
        if ($request->action !== 'substitute') {
            $this->effects->lock($sessionIds, $groupIds);
        }
        $lockIds = array_values(array_unique([...$sessionIds, ...Session::query()->whereIn('id', $items->pluck('session_id'))->whereIn('group_id', $groupIds)->pluck('id')->all()]));
        // The sessions last; the time is taken once they are held, so a session that began while this change waited is
        // "already started" (422), and the history, the letters and the answer all have this moment.
        $lockedSessions = Session::query()->whereKey($lockIds)->orderBy('id')->lockForUpdate()->with('group')->get()->keyBy('id');
        $now = CarbonImmutable::now();
        $expiredRequests = $touchesRequests ? $this->substituteItems->normalize($items, $groupIds, $lockedSessions, $now) : [];
        $selection = null;
        if ($request->action === 'holiday') {
            $selection = $this->planner->found($request);
            $this->planner->assertDays($request);
            if (! $this->planner->holidayMatches($request, $now, $selection)) {
                throw new HttpResponseException(response()->json([
                    'message' => 'Заниманията в тези дни са променени — прегледайте отново.',
                    'preview' => $this->preview->handle($request, true),
                ], 409));
            }
        }
        $planned = $this->planner->plan($request, true, $now, false, $selection);
        $effects = $request->action === 'substitute' ? ScheduleEffects::none() : $this->effects->assess(array_map(
            fn (PlannedSessionChange $change): array => ['before' => $change->before, 'after' => $change->after, 'effect' => $change->effect()], $planned,
        ), $now, $request->notifyParents);
        $confirmed = $this->assertConfirmed($request, [...$this->planner->warnings($planned), ...$effects->warnings]);

        $changeId = (string) Str::uuid7();
        $organizationId = $this->context->organizationId();
        $letters = $this->teachers->letters($planned);
        $verifiedTeachers = $this->teachers->withConfirmedAddress(array_map('strval', array_keys($letters)));
        $ids = array_map(fn (PlannedSessionChange $change): string => $change->sessionId(), $planned);
        if (in_array($request->action, ['cancel', 'holiday', 'change_location'], true)) {
            Session::query()->whereKey($ids)->update([
                ...($request->action === 'change_location' ? ['location_id' => $request->locationId] : ['status' => 'cancelled']),
                'change_reason' => $request->reason, 'version' => DB::raw('version + 1'),
            ]);
        } elseif ($request->action === 'substitute') {
            $this->writeSubstituteTeachers($planned, $organizationId, $now);
        } else {
            foreach ($planned as $change) {
                if (! $change->before->exists) {
                    $change->after->change_reason = $request->reason;
                    $change->after->save();
                } else {
                    Session::query()->whereKey($change->sessionId())->update([
                        'status' => $change->after->status, 'starts_at' => $change->after->starts_at->utc(), 'ends_at' => $change->after->ends_at->utc(),
                        'original_starts_at' => $change->after->original_starts_at?->utc(), 'location_id' => $change->after->location_id,
                        'change_reason' => $request->action === 'substitute' ? $change->before->change_reason : $request->reason, 'version' => $change->after->version,
                        ...($request->action === 'substitute' ? ['instructors_source' => $change->after->instructors_source] : []),
                    ]);
                }
                if ($change->syncTeachers !== null) {
                    $change->after->instructors()->sync($change->syncTeachers);
                }
            }
        }

        $result = [
            'change' => [
                'id' => $changeId,
                'action' => $request->action,
                'created_at' => $now->utc()->toIso8601String(),
                'sessions' => array_map(fn (PlannedSessionChange $change): array => [
                    'id' => $change->sessionId(),
                    'version' => $change->after->version,
                    'status' => $change->after->status,
                    'starts_at' => $change->after->starts_at->utc()->toIso8601String(),
                    'ends_at' => $change->after->ends_at->utc()->toIso8601String(),
                    'location_id' => $change->after->location_id === null ? null : (string) $change->after->location_id,
                    ...($request->action === 'substitute' ? ['teachers' => array_map(fn ($id, $row) => ['profile_id' => $id, 'role' => $row['role'], 'replaces' => $row['replaces_instructor_profile_id'] ?? null], array_keys($change->syncTeachers ?? []), array_values($change->syncTeachers ?? []))] : []),
                ], $planned),
            ],
            'letters' => [
                'families' => $effects->letters(),
                'teachers' => $verifiedTeachers,
                'without_verified_email' => $request->action === 'substitute' ? count($letters) - $verifiedTeachers : $effects->withoutConfirmedAddress(),
            ],
        ];
        ScheduleChange::query()->create([
            'id' => $changeId,
            'action' => $request->action,
            'reason' => $request->reason,
            'reason_to_parents' => $request->reasonToParents,
            'notify_parents' => $request->notifyParents,
            'criteria' => $request->action === 'holiday' ? ['days' => $request->days, 'locations' => $request->locations, 'activities' => $request->activities] : null,
            'session_count' => count($planned),
            'idempotency_key' => $idempotencyKey,
            'request_hash' => $hash,
            'result' => $result,
            'actor_user_id' => (string) $actor->id,
            'created_at' => $now,
        ]);
        $confirmed = $this->carryConfirmed($ids, $confirmed, $request->action);
        DB::table('session_changes')->insert(array_map(fn (PlannedSessionChange $change): array => [
            'id' => (string) Str::uuid7(),
            'organization_id' => $organizationId,
            'schedule_change_id' => $changeId,
            'session_id' => $change->sessionId(),
            'from_status' => $change->before->exists ? $change->before->status : null,
            'to_status' => $change->after->status,
            'from_starts_at' => $change->before->exists ? $change->before->starts_at->utc() : null,
            'from_ends_at' => $change->before->exists ? $change->before->ends_at->utc() : null,
            'to_starts_at' => $change->after->starts_at->utc(),
            'to_ends_at' => $change->after->ends_at->utc(),
            'from_location_id' => ! $change->before->exists || $change->before->location_id === null ? null : (string) $change->before->location_id,
            'to_location_id' => $change->after->location_id === null ? null : (string) $change->after->location_id,
            'replaced_profile_id' => $change->replacedProfileId, 'removed_profile_id' => $change->removedProfileId, 'added_profile_id' => $change->addedProfileId,
            'confirmed_overlaps' => json_encode($confirmed[$change->sessionId()] ?? [], JSON_THROW_ON_ERROR),
            'created_at' => $now,
        ], $planned));

        if ($touchesRequests) {
            $this->substituteItems->apply($actor, $planned, $changeId, $expiredRequests, $now);
        }

        $days = [];
        foreach ($planned as $change) {
            $timezone = $change->before->group?->schedule_pattern['timezone'] ?? null;
            $days[$change->sessionId()] = $change->before->starts_at
                ->setTimezone(is_string($timezone) && $timezone !== '' ? $timezone : ScheduleChangePlanner::TIMEZONE)->toDateString();
        }
        $this->effects->apply($actor, $changeId, $effects, $request->reasonToParents ? $request->reason : null, $days, $letters, $now);

        $single = count($planned) === 1 ? $planned[0] : null;
        $this->audit->record(AuditAction::ScheduleChangeApplied, new AuditableReference('schedule_change', $changeId), [
            'action' => $request->action,
            'session_count' => count($planned),
            ...($request->action === 'substitute' ? ['request_id' => $request->requestId, 'replaced_profile_id' => $request->replacesProfileId, 'removed_profile_id' => $single?->removedProfileId, 'added_profile_id' => $request->substituteProfileId] : []),
            'notify_parents' => $request->notifyParents,
            'reason_to_parents' => $request->reasonToParents,
            ...($single === null ? [] : [
                'session_id' => $single->sessionId(),
                'from_starts_at' => $single->before->exists ? $single->before->starts_at->utc()->toIso8601String() : null,
                'to_starts_at' => $single->after->starts_at->utc()->toIso8601String(),
                'from_location_id' => $single->before->exists ? $single->before->location_id : null,
                'to_location_id' => $single->after->location_id,
            ]),
            'visits_cancelled' => $effects->count('visit_cancelled'),
            'applications_rejected' => $effects->count('application_rejected'),
            'skips_dropped' => array_sum(array_map('count', $effects->droppedSkips)),
            'starts_changed' => count($effects->starts),
            'families' => count($effects->families()),
        ]);

        return ['status' => 201, 'body' => $result];
    }

    /** @param list<PlannedSessionChange> $planned */
    private function writeSubstituteTeachers(array $planned, string $organizationId, CarbonImmutable $now): void
    {
        $removed = array_values(array_filter($planned, fn ($p) => $p->removedProfileId !== null));
        if ($removed !== []) {
            DB::table('activity_session_instructors')->where('organization_id', $organizationId)->where(function ($q) use ($removed): void {
                foreach ($removed as $change) {
                    $q->orWhere(fn ($pair) => $pair->where('session_id', $change->sessionId())->where('instructor_profile_id', $change->removedProfileId));
                }
            })->delete();
        }
        $incoming = [];
        $sources = [];
        foreach ($planned as $change) {
            $sources[$change->after->instructors_source][] = $change->sessionId();
            if ($change->addedProfileId !== null) {
                $incoming[] = ['organization_id' => $organizationId, 'session_id' => $change->sessionId(), 'instructor_profile_id' => $change->addedProfileId,
                    ...($change->syncTeachers[$change->addedProfileId] ?? []), 'created_at' => $now, 'updated_at' => $now];
            }
        }
        if ($incoming !== []) {
            DB::table('activity_session_instructors')->insert($incoming);
        }
        foreach ($sources as $source => $ids) {
            Session::query()->whereKey($ids)->update(['instructors_source' => $source, 'version' => DB::raw('version + 1')]);
        }
    }

    /**
     * @param  list<string>  $ids
     * @param  array<string, list<array{type:string,session_id:string,other_session_id:string|null}>>  $confirmed
     * @return array<string, list<array{type:string,session_id:string,other_session_id:string|null}>>
     */
    private function carryConfirmed(array $ids, array $confirmed, string $action): array
    {
        $rows = DB::table('session_changes as history')->join('schedule_changes as changes', 'changes.id', '=', 'history.schedule_change_id')
            ->where('history.organization_id', $this->context->organizationId())->where('changes.organization_id', $this->context->organizationId())->whereIn('history.session_id', $ids)->orderByDesc('history.created_at')->orderByDesc('history.id')
            ->get(['history.session_id', 'history.confirmed_overlaps', 'changes.action'])->unique('session_id');
        foreach ($rows as $row) {
            if ($action !== 'substitute' && $row->action !== 'substitute') {
                continue;
            }
            $keys = [];
            foreach ([...json_decode($row->confirmed_overlaps, true, 512, JSON_THROW_ON_ERROR), ...($confirmed[$row->session_id] ?? [])] as $key) {
                $keys[$key['type'].':'.$key['session_id'].':'.($key['other_session_id'] ?? '')] = $key;
            }
            $confirmed[$row->session_id] = array_values($keys);
        }

        return $confirmed;
    }

    /**
     * The stored answer of a change with this key by this person, when the body is the same — also when the versions
     * have moved on since: the first request moved them (т. 7.3). Another body under the same key is refused.
     *
     * @return array<string, mixed>|null
     */
    private function replay(User $actor, string $idempotencyKey, string $hash): ?array
    {
        $change = ScheduleChange::query()->where('actor_user_id', $actor->id)->where('idempotency_key', $idempotencyKey)->first();
        if ($change === null) {
            return null;
        }
        if (! hash_equals($change->request_hash, $hash)) {
            throw ValidationException::withMessages(['idempotency_key' => 'Тази заявка вече е изпратена с други данни. Заредете графика отново.']);
        }

        return $change->result;
    }

    /**
     * Every warning that asks for a confirmation must be among the keys the person saw and confirmed (т. 4.8) — worked
     * out again here, under the locks, so a confirmation of one overlap never covers another that came meanwhile.
     *
     * @param  list<Warning>  $warnings
     * @return array<string, list<array{type: string, session_id: string, other_session_id: string|null}>> session => confirmed keys
     */
    private function assertConfirmed(ScheduleChangeRequest $request, array $warnings): array
    {
        $confirmed = [];
        $missing = false;
        foreach ($warnings as $warning) {
            if (! $warning['needs_confirmation']) {
                continue;
            }
            $key = ['type' => $warning['type'], 'session_id' => $warning['session_id'], 'other_session_id' => $warning['other_session_id']];
            if ($request->confirms($key)) {
                $confirmed[$warning['session_id']][] = $key;
            } else {
                $missing = true;
            }
        }
        if ($missing) {
            $message = 'Има ново предупреждение. Прегледайте го и потвърдете отново.';

            throw new HttpResponseException(response()->json([
                'message' => $message,
                'errors' => ['confirmed_warnings' => [$message]],
                'warnings' => $warnings,
            ], 422));
        }

        return $confirmed;
    }
}
