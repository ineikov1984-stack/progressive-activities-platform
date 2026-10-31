<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\ValueObjects\AuditableReference;
use App\Domain\Catalog\Models\Group;
use App\Domain\Catalog\Models\ScheduleChange;
use App\Domain\Catalog\Models\Session;
use App\Domain\Catalog\Services\ScheduleChangePlanner;
use App\Domain\Catalog\Services\TeacherRecipients;
use App\Domain\Catalog\ValueObjects\PlannedSessionChange;
use App\Domain\Catalog\ValueObjects\ScheduleChangeRequest;
use App\Domain\Enrollment\Services\ScheduleChangeEffects;
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
        private ScheduleChangeEffects $effects,
        private TeacherRecipients $teachers,
        private AuditRecorder $audit,
        private TenantContext $context,
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
            if ($state === '55P03') {
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
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }
        $now = CarbonImmutable::now();
        $found = $this->planner->found($request);
        $groupIds = ScheduleChangePlanner::groupIds($found);
        Group::query()->whereKey($groupIds)->orderBy('id')->lockForUpdate()->get(['id']);
        $replay = $this->replay($actor, $idempotencyKey, $hash);
        if ($replay !== null) {
            return ['status' => 200, 'body' => $replay];
        }
        $this->planner->assertFound($request, $found);
        $this->effects->lock($request->sessionIds(), $groupIds);
        $planned = $this->planner->plan($request, true, $now);
        $effects = $this->effects->assess(array_map(
            fn (PlannedSessionChange $change): array => ['before' => $change->before, 'after' => $change->after], $planned,
        ), $now);
        $confirmed = $this->assertConfirmed($request, [...$this->planner->warnings($planned), ...$effects->warnings]);

        $changeId = (string) Str::uuid7();
        $organizationId = $this->context->organizationId();
        $letters = $this->teachers->letters($planned);
        foreach ($planned as $change) {
            Session::query()->whereKey($change->sessionId())->update([
                'status' => $change->after->status,
                // The latest reason, for the panel (т. 3.3).
                'change_reason' => $request->reason,
                'version' => $change->after->version,
            ]);
            if ($change->syncTeachers !== null) {
                $change->before->instructors()->sync($change->syncTeachers);
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
                ], $planned),
            ],
            'letters' => [
                'families' => $effects->letters(),
                'teachers' => $this->teachers->withConfirmedAddress(array_map('strval', array_keys($letters))),
                'without_verified_email' => $effects->withoutConfirmedAddress(),
            ],
        ];
        ScheduleChange::query()->create([
            'id' => $changeId,
            'action' => $request->action,
            'reason' => $request->reason,
            'reason_to_parents' => $request->reasonToParents,
            'notify_parents' => $request->notifyParents,
            'criteria' => null,
            'session_count' => count($planned),
            'idempotency_key' => $idempotencyKey,
            'request_hash' => $hash,
            'result' => $result,
            'actor_user_id' => (string) $actor->id,
            'created_at' => $now,
        ]);
        DB::table('session_changes')->insert(array_map(fn (PlannedSessionChange $change): array => [
            'id' => (string) Str::uuid7(),
            'organization_id' => $organizationId,
            'schedule_change_id' => $changeId,
            'session_id' => $change->sessionId(),
            'from_status' => $change->before->status,
            'to_status' => $change->after->status,
            'from_starts_at' => $change->before->starts_at->utc(),
            'from_ends_at' => $change->before->ends_at->utc(),
            'to_starts_at' => $change->after->starts_at->utc(),
            'to_ends_at' => $change->after->ends_at->utc(),
            'from_location_id' => $change->before->location_id === null ? null : (string) $change->before->location_id,
            'to_location_id' => $change->after->location_id === null ? null : (string) $change->after->location_id,
            'confirmed_overlaps' => json_encode($confirmed[$change->sessionId()] ?? [], JSON_THROW_ON_ERROR),
            'created_at' => $now,
        ], $planned));

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
            'notify_parents' => $request->notifyParents,
            'reason_to_parents' => $request->reasonToParents,
            ...($single === null ? [] : [
                'session_id' => $single->sessionId(),
                'from_starts_at' => $single->before->starts_at->utc()->toIso8601String(),
                'to_starts_at' => $single->after->starts_at->utc()->toIso8601String(),
                'from_location_id' => $single->before->location_id,
                'to_location_id' => $single->after->location_id,
            ]),
            'visits_cancelled' => $effects->count('visit_cancelled'),
            'applications_rejected' => $effects->count('application_rejected'),
            'skips_dropped' => 0,
            'starts_changed' => count($effects->starts),
            'families' => count($effects->families()),
        ]);

        return ['status' => 201, 'body' => $result];
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
