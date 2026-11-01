<?php

namespace App\Domain\Attendance\Actions;

use App\Domain\Attendance\Models\AttendanceSession;
use App\Domain\Attendance\Models\AttendanceSessionChange;
use App\Domain\Attendance\Services\AttendanceAccess;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\ValueObjects\AuditableReference;
use App\Domain\Catalog\Models\Session;
use App\Domain\Catalog\Services\SessionCutoff;
use App\Domain\Identity\Models\User;
use App\Http\Exceptions\RetryAttendanceWrite;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Topic and held-state changes share the same session lock as attendance marks. */
final readonly class RecordAttendanceSession
{
    public function __construct(private AttendanceAccess $access, private AuditRecorder $audit) {}

    /** @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function handle(User $actor, Session $session, array $body): array
    {
        $topic = ($body['action'] ?? null) === 'topic';
        $this->authorize($actor, $session, $topic);
        foreach (['topic', 'reason'] as $key) {
            if (is_string($body[$key] ?? null)) {
                $body[$key] = trim($body[$key]);
            }
        }
        $rules = ['action' => ['required', 'in:topic,not_held,held'], ...($topic
            ? ['topic' => ['present', 'nullable', 'string', 'max:200']]
            : ['reason' => ['required', 'string', 'min:1', 'max:500']])];
        $reasonMessage = ($body['action'] ?? null) === 'held'
            ? 'Напишете защо заниманието се проведе (до 500 знака).'
            : 'Напишете защо заниманието не се проведе (до 500 знака).';
        $data = Validator::make($body, $rules, [
            'action.required' => 'Изберете действие.', 'action.in' => 'Изберете валидно действие.',
            'topic.present' => 'Въведете тема или оставете полето празно.', 'topic.string' => 'Въведете тема (до 200 знака).', 'topic.max' => 'Темата е до 200 знака.',
            'reason.required' => $reasonMessage, 'reason.string' => $reasonMessage, 'reason.min' => $reasonMessage, 'reason.max' => $reasonMessage,
        ])->validate();
        if ($topic && $data['topic'] === '') {
            $data['topic'] = null;
        }
        try {
            return DB::transaction(fn (): array => $this->apply($actor, $session, $data));
        } catch (DeadlockException $exception) {
            throw new RetryAttendanceWrite($exception);
        } catch (QueryException $exception) {
            if (in_array((string) ($exception->errorInfo[0] ?? $exception->getCode()), ['55P03', '40P01'], true)) {
                return ['status' => 409, 'retry' => true, 'message' => 'Някой друг отбелязва или променя графика в момента. Опитайте отново.'];
            }
            throw $exception;
        }
    }

    private function authorize(User $actor, Session $session, bool $topic): void
    {
        abort_unless($this->access->canView($actor, $session), 404, 'Заниманието не е намерено.');
        abort_unless($topic ? $this->access->mayMark($actor, $session) : $this->access->mayRecordNotHeld($actor, $session), 403,
            $topic ? 'Нямате право да отбелязвате присъствия в тази група.' : 'Само офисът отбелязва, че занимание не се е провело.');
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function apply(User $actor, Session $session, array $data): array
    {
        if (DB::getDriverName() === 'pgsql') {
            $timeout = max(1, (int) config('platform.attendance_lock_timeout_ms', 5000));
            DB::statement("SET LOCAL lock_timeout = '{$timeout}ms'");
        }
        $current = Session::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
        $topic = $data['action'] === 'topic';
        $this->authorize($actor, $current, $topic);
        $state = AttendanceSession::query()->where('session_id', $current->id)->lockForUpdate()->first();
        $notHeld = $state?->not_held_at !== null;
        if ($topic ? ($state?->topic === $data['topic']) : ($notHeld === ($data['action'] === 'not_held'))) {
            return ['status' => 200, 'applied' => []];
        }
        abort_unless($this->access->canMark($actor, $current), 403, 'Срокът за отбелязване изтече. Поправка правят собственикът или администраторът, с причина.');
        if (! SessionCutoff::passed($current)) {
            $this->invalid('Отбелязването започва 15 минути преди началото.');
        }
        if ($current->status !== 'scheduled') {
            $this->invalid('Заниманието е отменено — присъствия не се отбелязват.');
        }
        if ($topic && $notHeld) {
            $this->invalid('Заниманието не се проведе — присъствия не се отбелязват.');
        }
        $values = ['version' => ($state->version ?? 0) + 1];
        if ($topic) {
            $values['topic'] = $data['topic'];
        } else {
            $values = [...$values, 'not_held_at' => $data['action'] === 'not_held' ? now()->utc() : null,
                'not_held_by' => $data['action'] === 'not_held' ? $actor->id : null, 'not_held_reason' => $data['action'] === 'not_held' ? $data['reason'] : null];
        }
        if ($state === null) {
            $state = AttendanceSession::query()->create(['session_id' => $current->id, ...$values]);
        } else {
            $state->update($values);
        }
        if (! $topic) {
            AttendanceSessionChange::query()->create(['attendance_session_id' => $state->id, 'version' => $state->version,
                'action' => $data['action'], 'reason' => $data['reason'], 'actor_user_id' => $actor->id]);
            $this->audit->record($data['action'] === 'not_held' ? AuditAction::AttendanceSessionNotHeld : AuditAction::AttendanceSessionHeld,
                new AuditableReference('session', (string) $current->id), ['session_id' => (string) $current->id, 'group_id' => (string) $current->group_id]);
        }

        return ['status' => 200, 'applied' => []];
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['session' => $message]);
    }
}
