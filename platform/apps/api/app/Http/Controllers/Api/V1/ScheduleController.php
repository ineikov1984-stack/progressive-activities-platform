<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\Models\Session;
use App\Domain\Catalog\Services\AccessScope;
use App\Domain\Catalog\Services\ScheduleReader;
use App\Domain\Enrollment\Services\EnrollmentCapacity;
use App\Domain\Enrollment\Services\SessionRoster;
use App\Domain\Identity\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Presenters\SchedulePresenter;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * "График" (docs/product/schedule.md, т. 2, 6): the sessions a person of the team sees, with their teachers and places,
 * and the panel of one session. Which sessions — `AccessScope::scheduleGroups`; the children — only with
 * "enrollment.view" in the group or in one's own group; another organization's session, or one outside the person's
 * sessions, is not found. Read only: nothing is written to the audit log.
 */
final class ScheduleController extends Controller
{
    /** Six weeks — the month's grid (т. 3.2). */
    private const MAX_DAYS = 42;

    private const MAX_CHOICES = 50;

    public function __construct(
        private readonly AccessScope $scope,
        private readonly ScheduleReader $reader,
        private readonly EnrollmentCapacity $capacity,
        private readonly SessionRoster $roster,
    ) {}

    /**
     * The sessions from `from` to `to` — Sofia's days, both included: `[from 00:00, to + 1 00:00)` in Sofia. The filters
     * take several values each; a value outside the person's sessions finds nothing and is no error, so that nothing
     * tells what exists. `child` only with "enrollment.view", and only through the groups where the person has it.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $validated = $request->validate($this->rules(), $this->messages());
        $from = (string) $validated['from'];
        $to = (string) $validated['to'];
        if (intdiv(CarbonImmutable::parse($to, 'UTC')->getTimestamp() - CarbonImmutable::parse($from, 'UTC')->getTimestamp(), 86400) + 1 > self::MAX_DAYS) {
            throw ValidationException::withMessages(['to' => 'Изберете период до '.self::MAX_DAYS.' дни.']);
        }
        $child = isset($validated['child']) ? Str::lower((string) $validated['child']) : null;
        // The children table has no RLS: who does not see enrollments does not search by a child at all.
        abort_if($child !== null && ! $this->scope->access($user)->has('enrollment.view'), 403, 'Нямате достъп до децата в графика.');

        $board = $this->reader->board(
            $this->scope->scheduleGroups($user),
            CarbonImmutable::parse($from, ScheduleReader::TIMEZONE)->startOfDay(),
            CarbonImmutable::parse($to, ScheduleReader::TIMEZONE)->startOfDay()->addDay(),
            [
                'activity' => self::choices($validated, 'activity'),
                'group' => self::choices($validated, 'group'),
                'instructor' => self::choices($validated, 'instructor'),
                'location' => self::choices($validated, 'location'),
            ],
        );
        $sessions = $board['sessions'];
        if ($child !== null) {
            $with = $this->roster->sessionsWithChild($child, $this->scope->groups($user, 'enrollment.view'), new Collection($sessions));
            $sessions = array_values(array_filter($sessions, fn (Session $session): bool => in_array((string) $session->id, $with, true)));
        }
        $groupIds = array_values(array_unique(array_map(fn (Session $session): string => (string) $session->group_id, $sessions)));

        return response()->json(SchedulePresenter::board(
            $sessions,
            $board['teachers'],
            $board['groups'],
            $board['dictionary'],
            $this->capacity->takenForSessions(new Collection($sessions)),
            $this->capacity->waitingForGroups($groupIds),
            // Where the person only stands in, the panel decides by the session (`scheduleChildrenAt`).
            $this->scope->scheduleChildren($user),
            $this->manages($user),
        ));
    }

    /** The panel of a session (т. 6.2). */
    public function show(Request $request, string $session): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $visible = $this->scope->scheduleGroups($user);
        $panel = $this->reader->panel($visible, Str::lower($session));
        if ($panel === null) {
            abort(404, 'Заниманието не е намерено.');
        }
        $record = $panel['session'];
        $groupId = (string) $record->group_id;
        // The card of a child (т. 4.1) is seen in the groups of `scheduleChildren`: not where the person only stands in for
        // the day, so those rows have no `child_id`.
        $cards = $this->scope->scheduleChildren($user);
        $children = $this->scope->scheduleChildrenAt($user, $visible, $record)
            ? $this->roster->children($record, $cards === null || in_array($groupId, $cards, true))
            : null;

        return response()->json(SchedulePresenter::panel(
            $panel,
            $this->capacity->takenForSessions(new Collection([$record])),
            $this->capacity->waitingForGroups([$groupId]),
            $children,
            $this->scope->allowsEverywhere($user, 'schedule.manage'),
            $this->manages($user),
        ));
    }

    /**
     * Who changes the schedule (docs/product/schedule-changes.md, т. 4.1, 7.5): a person of the team with "schedule.manage"
     * for the whole organization. The platform operator has the right by role, sees why a session changed, but has no
     * buttons (decision 8).
     */
    private function manages(User $user): bool
    {
        return $this->scope->access($user)->role?->isTeam() === true && $this->scope->allowsEverywhere($user, 'schedule.manage');
    }

    /** @return array<string, list<string>> */
    private function rules(): array
    {
        $choices = ['sometimes', 'array', 'max:'.self::MAX_CHOICES];

        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'activity' => $choices,
            'activity.*' => ['required', 'uuid'],
            'group' => $choices,
            'group.*' => ['required', 'uuid'],
            'instructor' => $choices,
            'instructor.*' => ['required', 'uuid'],
            'location' => $choices,
            'location.*' => ['required', 'uuid'],
            'child' => ['sometimes', 'nullable', 'uuid'],
        ];
    }

    /** @return array<string, string> */
    private function messages(): array
    {
        return [
            'from.required' => 'Изберете от коя дата.',
            'from.date_format' => 'Изберете от коя дата.',
            'to.required' => 'Изберете до коя дата.',
            'to.date_format' => 'Изберете до коя дата.',
            'to.after_or_equal' => 'Крайната дата е преди началната.',
            'max' => 'Изберете най-много :max.',
            'array' => 'Изборът не е намерен.',
            'required' => 'Изборът не е намерен.',
            'uuid' => 'Изборът не е намерен.',
            'child.uuid' => 'Детето не е намерено.',
        ];
    }

    /**
     * The chosen identifiers of a filter, in lower case: a UUID is found in any case.
     *
     * @param  array<array-key, mixed>  $validated
     * @return list<string>
     */
    private static function choices(array $validated, string $key): array
    {
        $values = $validated[$key] ?? [];
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_unique(array_map(fn (mixed $id): string => Str::lower((string) $id), $values)));
    }
}
