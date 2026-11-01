<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\Actions\ApplyScheduleChange;
use App\Domain\Catalog\Actions\PreviewScheduleChange;
use App\Domain\Catalog\Services\AccessScope;
use App\Domain\Catalog\Services\TeamAssignments;
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
 * checked first, so that without it nobody learns whether a session exists (т. 7.4). Single and bulk changes use the same two routes.
 */
final class ScheduleChangeController extends Controller
{
    /** The actions supported by the shared preview/apply routes. */
    private const ACTIONS = ['move', 'cancel', 'restore', 'change_location', 'holiday', 'add', 'substitute'];

    /** The warnings that are confirmed by their key (т. 4.8). */
    private const WARNINGS = ['instructor', 'shared_location', 'exclusive_location', 'overbooked', 'no_lead', 'substitute_away'];

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

        if ($request->input('action') === 'substitute') {
            abort_unless($this->scope->allowsEverywhere($user, 'team.assign'), 403, 'Заместник назначават собственикът, администраторът и координаторът.');
        }

        return $user;
    }

    /** The form of the body (т. 7.4, step 3): what can be told without the database. */
    private function body(Request $request): ScheduleChangeRequest
    {
        $substitute = $request->input('action') === 'substitute';
        $sessions = $substitute ? 'Изберете от 1 до 50 занимания.' : 'Изберете от 1 до 300 занимания.';
        $reason = 'Напишете причина (до 2000 знака).';
        $warning = 'Има ново предупреждение. Прегледайте го и потвърдете отново.';
        $inputAction = $request->input('action');
        $action = is_string($inputAction) ? $inputAction : '';
        $single = in_array($action, ['move', 'restore'], true);
        $needsSessions = $action !== 'add' && ($action !== 'holiday' || ! $request->is('*/preview'));
        $validated = $request->validate([
            'action' => ['required', 'string', Rule::in(self::ACTIONS)],
            'sessions' => [$needsSessions ? 'required' : 'sometimes', 'array', 'min:1', $single ? 'max:1' : ($substitute ? 'max:50' : 'max:300')],
            'sessions.*' => ['required', 'array'],
            'sessions.*.id' => ['required', 'uuid', 'distinct:ignore_case'],
            'sessions.*.version' => ['required', 'integer', 'min:1'],
            'date' => ['required_if:action,move,add', 'date_format:Y-m-d'],
            'from' => ['required_if:action,move,add', 'date_format:H:i'],
            'to' => ['required_if:action,move,add', 'date_format:H:i', 'after:from'],
            'location_id' => [Rule::requiredIf($action === 'change_location' && ! $request->exists('location_id')), 'nullable', 'uuid'],
            'group_id' => ['required_if:action,add', 'uuid'],
            'days' => ['required_if:action,holiday', 'array', 'min:1', 'max:31'],
            'days.*' => ['required', 'date_format:Y-m-d', 'distinct'],
            'locations' => ['sometimes', 'array', 'max:300'],
            'locations.*' => ['required', 'uuid', 'distinct:ignore_case'],
            'activities' => ['sometimes', 'array', 'max:300'],
            'activities.*' => ['required', 'uuid', 'distinct:ignore_case'],
            'notify_parents' => ['prohibited_if:action,substitute', 'sometimes', 'boolean'],
            'reason' => ['prohibited_if:action,substitute', 'nullable', 'string', 'max:2000'],
            'reason_to_parents' => ['prohibited_if:action,substitute', 'sometimes', 'boolean'],
            'replaces_profile_id' => ['required_if:action,substitute', 'uuid'],
            'substitute_profile_id' => ['present_if:action,substitute', 'nullable', 'uuid'],
            'request' => ['sometimes', 'array'],
            'request.id' => ['required_with:request', 'uuid'],
            'request.version' => ['required_with:request', 'integer', 'min:1'],
            'confirmed_warnings' => ['sometimes', 'array', 'max:90000'],
            'confirmed_warnings.*' => ['required', 'array'],
            'confirmed_warnings.*.type' => ['required', 'string', Rule::in(self::WARNINGS)],
            'confirmed_warnings.*.session_id' => ['required', 'uuid'],
            'confirmed_warnings.*.other_session_id' => ['nullable', 'uuid'],
        ], [
            'replaces_profile_id.*' => 'Изберете кой няма да може.',
            'substitute_profile_id.*' => 'Изберете заместник или „Без заместник“.',
            'request.*' => 'Заредете отново заявката.',
            'request.*.*' => 'Заредете отново заявката.',
            'action.*' => 'Изберете действие.',
            'date.*' => 'Изберете ден от днес до една година напред.',
            'from.*' => 'Изберете начален час.',
            'to.*' => 'Краят трябва да е след началото.',
            'location_id.*' => 'Мястото не е намерено.',
            'sessions.required' => $sessions,
            'sessions.array' => $sessions,
            'sessions.min' => $sessions,
            // "Премести" and "Възстанови" are for one; bulk actions accept at most 300 (т. 7.1).
            'sessions.max' => $single ? 'Изберете едно занимание.' : $sessions,
            'group_id.*' => 'Заниманието не е намерено.',
            'days.*' => 'Изберете от 1 до 31 дни от днес до една година напред.',
            'days.*.*' => 'Изберете от 1 до 31 дни от днес до една година напред.',
            'locations.*' => 'Мястото не е намерено.',
            'activities.*' => 'Дейността не е намерена.',
            'notify_parents.*' => 'Изберете дали да изпратим писмо на родителите.',
            'sessions.*.*' => 'Заниманието не е намерено.',
            'reason.*' => $reason,
            'reason_to_parents.boolean' => 'Изберете дали причината отива при родителите.',
            'confirmed_warnings.*' => $warning,
            'confirmed_warnings.*.*' => $warning,
        ]);

        $text = is_string($validated['reason'] ?? null) ? trim($validated['reason']) : '';
        $action = (string) $validated['action'];
        // A reason is required to cancel, and is for the office; to restore it may be left out (т. 4.1).
        if ($text === '' && in_array($action, ['move', 'cancel', 'holiday', 'change_location'], true)) {
            throw ValidationException::withMessages(['reason' => $reason]);
        }
        $list = [];
        foreach ((array) ($validated['sessions'] ?? []) as $session) {
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

        if ($substitute && is_string($validated['substitute_profile_id'] ?? null)) {
            /** @var User $actor */
            $actor = $request->user();
            app(TeamAssignments::class)->assertNotSelf($actor, [Str::lower($validated['substitute_profile_id'])]);
        }

        return new ScheduleChangeRequest(
            replacesProfileId: $substitute ? Str::lower($validated['replaces_profile_id']) : null,
            substituteProfileId: $substitute && is_string($validated['substitute_profile_id'] ?? null) ? Str::lower($validated['substitute_profile_id']) : null,
            requestId: $substitute && isset($validated['request']['id']) ? Str::lower($validated['request']['id']) : null,
            requestVersion: $substitute && isset($validated['request']['version']) ? (int) $validated['request']['version'] : null,
            action: $action,
            sessions: $list,
            reason: $substitute || $text === '' ? null : $text,
            reasonToParents: ! $substitute && ($action === 'holiday' || $request->boolean('reason_to_parents', $action === 'move')),
            // A change of one session always goes to the families (decision of 29.10.2026 and decision 4).
            notifyParents: ! $substitute && (in_array($action, ['move', 'cancel', 'restore'], true) || $request->boolean('notify_parents', true)),
            confirmedWarnings: $confirmed,
            date: in_array($action, ['move', 'add'], true) ? $validated['date'] : null,
            from: in_array($action, ['move', 'add'], true) ? $validated['from'] : null,
            to: in_array($action, ['move', 'add'], true) ? $validated['to'] : null,
            locationProvided: in_array($action, ['move', 'add', 'change_location'], true) && array_key_exists('location_id', $validated),
            locationId: in_array($action, ['move', 'add', 'change_location'], true) && is_string($validated['location_id'] ?? null) ? Str::lower($validated['location_id']) : null,
            groupId: $action === 'add' ? Str::lower($validated['group_id']) : null,
            days: $action === 'holiday' ? array_values($validated['days']) : [],
            locations: $action === 'holiday' ? array_map(Str::lower(...), $validated['locations'] ?? []) : [],
            activities: $action === 'holiday' ? array_map(Str::lower(...), $validated['activities'] ?? []) : [],
        );
    }
}
