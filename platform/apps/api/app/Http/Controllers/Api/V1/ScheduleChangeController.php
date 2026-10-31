<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\Actions\ApplyScheduleChange;
use App\Domain\Catalog\Actions\PreviewScheduleChange;
use App\Domain\Catalog\Services\AccessScope;
use App\Domain\Catalog\ValueObjects\ScheduleChangeRequest;
use App\Domain\Identity\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Changes of the schedule (docs/product/schedule-changes.md, т. 7): the preview and the change, from one body. Only a
 * person of the team with "schedule.manage" for the whole organization — the platform operator has the right by role
 * but does not change the schedule (decision 8); an added right with a narrower scope gives nothing. The right is
 * checked first, so that without it nobody learns whether a session exists (т. 7.4). This stage: "Отмени" and
 * "Възстанови" of one session; "Премести" and many sessions come with the next steps.
 */
final class ScheduleChangeController extends Controller
{
    /** The actions of this stage. */
    private const ACTIONS = ['cancel', 'restore'];

    /** The warnings that are confirmed by their key (т. 4.8). */
    private const WARNINGS = ['instructor', 'shared_location', 'exclusive_location', 'overbooked', 'no_lead'];

    public function __construct(private readonly AccessScope $scope) {}

    /** `POST /schedule/changes/preview` — reads only, writes nothing, nothing in the audit log (т. 7.2). */
    public function preview(Request $request, PreviewScheduleChange $preview): JsonResponse
    {
        $this->changer($request);

        return response()->json($preview->handle($this->body($request)));
    }

    /** `POST /schedule/changes` — with the header `Idempotency-Key` (т. 7.3). */
    public function store(Request $request, ApplyScheduleChange $apply): JsonResponse
    {
        $user = $this->changer($request);
        $body = $this->body($request);
        $key = trim((string) $request->header('Idempotency-Key'));
        if ($key === '' || mb_strlen($key) > 120) {
            throw ValidationException::withMessages(['idempotency_key' => 'Нещо се обърка. Презаредете страницата и опитайте отново.']);
        }
        $result = $apply->handle($user, $body, $key);

        return response()->json($result['body'], $result['status']);
    }

    private function changer(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless(
            $this->scope->access($user)->role?->isTeam() === true && $this->scope->allowsEverywhere($user, 'schedule.manage'),
            403,
            'Нямате право да променяте графика.',
        );

        return $user;
    }

    /** The form of the body (т. 7.4, step 3): what can be told without the database. */
    private function body(Request $request): ScheduleChangeRequest
    {
        $sessions = 'Изберете от 1 до 300 занимания.';
        $reason = 'Напишете причина (до 2000 знака).';
        $warning = 'Има ново предупреждение. Прегледайте го и потвърдете отново.';
        $validated = $request->validate([
            'action' => ['required', 'string', Rule::in(self::ACTIONS)],
            'sessions' => ['required', 'array', 'min:1', 'max:1'],
            'sessions.*' => ['required', 'array'],
            'sessions.*.id' => ['required', 'uuid'],
            'sessions.*.version' => ['required', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'reason_to_parents' => ['sometimes', 'boolean'],
            'confirmed_warnings' => ['sometimes', 'array', 'max:50'],
            'confirmed_warnings.*' => ['required', 'array'],
            'confirmed_warnings.*.type' => ['required', 'string', Rule::in(self::WARNINGS)],
            'confirmed_warnings.*.session_id' => ['required', 'uuid'],
            'confirmed_warnings.*.other_session_id' => ['nullable', 'uuid'],
        ], [
            'action.*' => 'Изберете действие.',
            'sessions.required' => $sessions,
            'sessions.array' => $sessions,
            'sessions.min' => $sessions,
            // Many sessions at once come with the next step; "Премести" and "Възстанови" are always for one (т. 7.1).
            'sessions.max' => 'Изберете едно занимание.',
            'sessions.*.*' => 'Заниманието не е намерено.',
            'reason.*' => $reason,
            'reason_to_parents.boolean' => 'Изберете дали причината отива при родителите.',
            'confirmed_warnings.*' => $warning,
            'confirmed_warnings.*.*' => $warning,
        ]);

        $text = is_string($validated['reason'] ?? null) ? trim($validated['reason']) : '';
        $action = (string) $validated['action'];
        // A reason is required to cancel, and is for the office; to restore it may be left out (т. 4.1).
        if ($text === '' && $action === 'cancel') {
            throw ValidationException::withMessages(['reason' => $reason]);
        }
        $list = [];
        foreach ((array) $validated['sessions'] as $session) {
            $list[] = ['id' => Str::lower((string) $session['id']), 'version' => (int) $session['version']];
        }
        $confirmed = [];
        foreach ((array) ($validated['confirmed_warnings'] ?? []) as $key) {
            $other = $key['other_session_id'] ?? null;
            $confirmed[] = [
                'type' => (string) $key['type'],
                'session_id' => Str::lower((string) $key['session_id']),
                'other_session_id' => is_string($other) ? Str::lower($other) : null,
            ];
        }

        return new ScheduleChangeRequest(
            action: $action,
            sessions: $list,
            reason: $text === '' ? null : $text,
            reasonToParents: $request->boolean('reason_to_parents'),
            // A change of one session always goes to the families (decision of 29.10.2026 and decision 4).
            notifyParents: true,
            confirmedWarnings: $confirmed,
        );
    }
}
