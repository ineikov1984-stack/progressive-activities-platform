<?php

namespace App\Domain\Attendance\Actions;

use App\Domain\Attendance\Models\AttendanceMark;
use App\Domain\Attendance\Models\AttendanceMarkChange;
use App\Domain\Attendance\Models\AttendanceSession;
use App\Domain\Attendance\Services\AttendanceAccess;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\ValueObjects\AuditableReference;
use App\Domain\Catalog\Models\Session;
use App\Domain\Catalog\Services\SessionCutoff;
use App\Domain\Enrollment\Services\SessionRoster;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Tenancy\TenantContext;
use App\Http\Exceptions\RetryAttendanceWrite;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** One session lock serializes marks, versions and replays; writes are batched in enrollment order. */
final readonly class RecordAttendance
{
    public function __construct(private AttendanceAccess $access, private SessionRoster $roster, private TenantContext $context, private AuditRecorder $audit) {}

    /** @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function handle(User $actor, Session $session, array $body, string $key): array
    {
        $this->authorize($actor, $session);
        $data = Validator::make($body, [
            'action' => ['required', 'in:mark,all_present'],
            'marks' => ['required_if:action,mark', 'array', 'min:1', 'max:100'],
            'marks.*.enrollment_id' => ['required', 'uuid'], 'marks.*.status' => ['present', 'nullable', 'in:present,absent'],
            'marks.*.version' => ['required', 'integer', 'min:0'],
        ], [
            'action.required' => 'Изберете действие.', 'action.in' => 'Изберете валидно действие.',
            'marks.required_if' => 'Изберете от 1 до 100 деца.', 'marks.array' => 'Изберете от 1 до 100 деца.',
            'marks.min' => 'Изберете от 1 до 100 деца.', 'marks.max' => 'Изберете от 1 до 100 деца.',
            'marks.*.enrollment_id.required' => 'Изберете дете от списъка.', 'marks.*.enrollment_id.uuid' => 'Изберете дете от списъка.',
            'marks.*.status.present' => 'Изберете „Присъства“, „Отсъства“ или махнете отметката.',
            'marks.*.status.in' => 'Изберете „Присъства“, „Отсъства“ или махнете отметката.',
            'marks.*.version.required' => 'Заредете списъка отново и опитайте пак.',
            'marks.*.version.integer' => 'Заредете списъка отново и опитайте пак.', 'marks.*.version.min' => 'Заредете списъка отново и опитайте пак.',
        ])->validate();
        if ($key === '' || mb_strlen($key) > 120) {
            $this->invalid('idempotency_key', 'Липсва ключ за повторение. Презаредете страницата.');
        }
        $normalized = ['action' => $data['action'], 'reason' => is_string($body['reason'] ?? null) ? trim($body['reason']) : ($body['reason'] ?? null)];
        if ($data['action'] === 'mark') {
            $marks = [];
            foreach ($data['marks'] as $mark) {
                $id = strtolower($mark['enrollment_id']);
                if (isset($marks[$id])) {
                    $this->invalid('marks', 'Изберете всяко дете само веднъж.');
                }
                $marks[$id] = ['enrollment_id' => $id, 'status' => $mark['status'], 'version' => (int) $mark['version']];
            }
            ksort($marks);
            $normalized['marks'] = array_values($marks);
        }
        // Bind an organization's actor key to this session as well as the normalized body.
        $hash = hash('sha256', json_encode(['session_id' => (string) $session->id, 'body' => $normalized], JSON_THROW_ON_ERROR));
        try {
            return DB::transaction(fn (): array => $this->apply($actor, $session, $normalized, $key, $hash));
        } catch (QueryException $exception) {
            $state = (string) ($exception->errorInfo[0] ?? $exception->getCode());
            if (in_array($state, ['55P03', '40P01'], true)) {
                return ['status' => 409, 'retry' => true, 'message' => 'Някой друг отбелязва или променя графика в момента. Опитайте отново.'];
            }
            if ($state === '23505' || ($state === '23000' && str_contains($exception->getMessage(), 'UNIQUE'))) {
                return ['status' => 409, 'conflicts' => array_column($normalized['marks'] ?? [], 'enrollment_id'), 'message' => 'Междувременно детето е отбелязано от някой друг. Вижте новото състояние.'];
            }
            throw $exception;
        } catch (DeadlockException $exception) {
            // ResolveTenant owns the outer transaction: let it roll back before rendering retry.
            throw new RetryAttendanceWrite($exception);
        }
    }

    private function authorize(User $actor, Session $session): void
    {
        abort_unless($this->access->canView($actor, $session), 404, 'Заниманието не е намерено.');
        abort_unless($this->access->mayMark($actor, $session), 403, 'Нямате право да отбелязвате присъствия в тази група.');
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function apply(User $actor, Session $session, array $data, string $key, string $hash): array
    {
        if (DB::getDriverName() === 'pgsql') {
            $timeout = max(1, (int) config('platform.attendance_lock_timeout_ms', 5000));
            DB::statement("SET LOCAL lock_timeout = '{$timeout}ms'");
        }
        $current = Session::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
        $this->authorize($actor, $current);
        $replay = AttendanceMarkChange::query()->join('attendance_marks', function ($join): void {
            $join->on('attendance_marks.id', '=', 'attendance_mark_changes.mark_id')->on('attendance_marks.organization_id', '=', 'attendance_mark_changes.organization_id');
        })->where('attendance_mark_changes.actor_user_id', $actor->id)->where('attendance_mark_changes.request_key', $key)
            ->orderBy('attendance_marks.enrollment_id')->toBase()->get(['attendance_mark_changes.*', 'attendance_marks.enrollment_id']);
        if ($replay->isNotEmpty()) {
            if ($replay->contains(fn ($row): bool => $row->request_hash !== $hash)) {
                $this->invalid('idempotency_key', 'Тази заявка вече е изпратена с други данни. Презаредете страницата.');
            }

            return ['status' => 200, 'applied' => $replay->map(fn ($row): array => ['enrollment_id' => $row->enrollment_id, 'from' => $row->from_status, 'to' => $row->to_status, 'version' => (int) $row->version])->all()];
        }
        $late = ! $this->access->withinDeadline($current);
        abort_unless($this->access->canMark($actor, $current), 403, 'Срокът за отбелязване изтече. Поправка правят собственикът или администраторът, с причина.');
        $reason = $late ? $data['reason'] : null;
        if ($late && (! is_string($reason) || $reason === '' || mb_strlen($reason) > 500)) {
            $this->invalid('reason', 'Напишете причина за поправката (до 500 знака).');
        }
        if (! SessionCutoff::passed($current)) {
            $this->invalid('session', 'Отбелязването започва 15 минути преди началото.');
        }
        if ($current->status !== 'scheduled') {
            $this->invalid('session', 'Заниманието е отменено — присъствия не се отбелязват.');
        }
        $roster = collect($this->roster->forSessions(collect([$current]))[(string) $current->id] ?? [])->keyBy('enrollment_id');
        $existing = AttendanceMark::query()->where('session_id', $current->id)->lockForUpdate()->get()->keyBy('enrollment_id');
        $state = AttendanceSession::query()->where('session_id', $current->id)->lockForUpdate()->first();
        if ($state?->not_held_at !== null) {
            $this->invalid('session', 'Заниманието не се проведе — присъствия не се отбелязват.');
        }
        $marks = $data['marks'] ?? [];
        if ($data['action'] === 'all_present') {
            foreach ($roster as $row) {
                /** @var AttendanceMark|null $mark */
                $mark = $existing->get($row['enrollment_id']);
                if ($row['state'] === 'paused' || $row['skip'] !== null || $row['late_cancelled'] || $mark?->status !== null) {
                    continue;
                }
                $marks[] = ['enrollment_id' => $row['enrollment_id'], 'status' => 'present', 'version' => $mark->version ?? 0];
            }
        }
        usort($marks, fn (array $a, array $b): int => $a['enrollment_id'] <=> $b['enrollment_id']);
        $updates = [];
        $history = [];
        $applied = [];
        $conflicts = [];
        $now = now()->utc()->toDateTimeString();
        foreach ($marks as $requested) {
            $id = $requested['enrollment_id'];
            $row = $roster->get($id);
            if ($row === null) {
                $this->invalid('marks', 'Детето не е в списъка за това занимание.');
            }
            if ($row['state'] === 'paused' && $requested['status'] === 'absent') {
                $this->invalid('marks', 'Детето е в пауза — може да се отбележи само „Присъства“.');
            }
            if ($late && $requested['status'] === null) {
                $this->invalid('marks', 'След срока не може да се махне отметка — изберете „Присъства“ или „Отсъства“.');
            }
            /** @var AttendanceMark|null $old */
            $old = $existing->get($id);
            $from = $old?->status;
            $fromValue = $from?->value;
            if ($fromValue === $requested['status']) {
                continue;
            }
            if (($old->version ?? 0) !== $requested['version']) {
                $conflicts[] = $id;

                continue;
            }
            $version = ($old->version ?? 0) + 1;
            $markId = $old->id ?? (string) Str::uuid();
            $updates[] = ['id' => $markId, 'organization_id' => $this->context->organizationId(), 'session_id' => (string) $current->id, 'enrollment_id' => $id,
                'status' => $requested['status'], 'version' => $version, 'marked_by' => (string) $actor->id, 'marked_at' => $now, 'created_at' => $old->created_at ?? $now, 'updated_at' => $now];
            $history[] = ['id' => (string) Str::uuid(), 'organization_id' => $this->context->organizationId(), 'mark_id' => $markId,
                'from_status' => $fromValue, 'to_status' => $requested['status'], 'version' => $version, 'kind' => $late ? 'late' : $data['action'], 'reason' => $reason,
                'actor_user_id' => (string) $actor->id, 'request_key' => $key, 'request_hash' => $hash, 'created_at' => $now];
            $applied[] = ['enrollment_id' => $id, 'from' => $fromValue, 'to' => $requested['status'], 'version' => $version];
        }
        if ($conflicts !== []) {
            return ['status' => 409, 'conflicts' => $conflicts, 'message' => 'Междувременно детето е отбелязано от някой друг. Вижте новото състояние.'];
        }
        if ($updates !== []) {
            DB::table('attendance_marks')->upsert($updates, ['session_id', 'enrollment_id'], ['status', 'version', 'marked_by', 'marked_at', 'updated_at']);
            DB::table('attendance_mark_changes')->insert($history);
            if ($late) {
                $this->audit->record(AuditAction::AttendanceCorrectedLate, new AuditableReference('session', (string) $current->id), [
                    'session_id' => (string) $current->id, 'group_id' => (string) $current->group_id, 'kind' => $data['action'],
                    'changes' => array_map(fn (array $change): array => ['enrollment_id' => $change['enrollment_id'], 'from' => $change['from'], 'to' => $change['to']], $applied),
                ]);
            }
        }

        return ['status' => 200, 'applied' => $applied];
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
