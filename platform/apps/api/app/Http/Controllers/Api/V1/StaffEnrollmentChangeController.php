<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\Models\Group;
use App\Domain\Catalog\Models\PriceOption;
use App\Domain\Catalog\Services\AccessScope;
use App\Domain\Enrollment\Actions\ChangeEnrollment;
use App\Domain\Enrollment\Enums\EnrollmentApplicationStatus;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\EnrollmentApplication;
use App\Domain\Enrollment\Models\EnrollmentPause;
use App\Domain\Enrollment\Services\EnrollmentCapacity;
use App\Domain\Enrollment\Services\SessionApplicability;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Transfer, pause, end and skip for the organization's team (docs/product/staff-enrollment.md, т. 9).
 * Only owner, administrator and coordinator (enrollment.enroll); another organization's records are 404.
 */
final class StaffEnrollmentChangeController extends Controller
{
    /** Messages for the form rules; the screen sends valid values, so these are a safety net. */
    private const MESSAGES = [
        'required' => 'Попълнете полето.',
        'uuid' => 'Изберете от списъка.',
        'date_format' => 'Изберете дата.',
        'string' => 'Напишете текст.',
        'max' => 'Текстът е твърде дълъг.',
        'integer' => 'Презаредете страницата и опитайте отново.',
        'min' => 'Презаредете страницата и опитайте отново.',
    ];

    public function __construct(private readonly TenantContext $context, private readonly AccessScope $scope) {}

    public function transferOptions(Request $request, string $enrollment, EnrollmentCapacity $capacity): JsonResponse
    {
        $this->authorizeManage($request);
        $record = Enrollment::query()->with('group.offering')->findOrFail($enrollment);
        $this->requireGroup($request, $record->group_id);
        // Only the groups where the user enrolls (docs/product/team-rights.md, т. 9).
        $allowed = $this->scope->groups($this->user($request), 'enrollment.enroll');
        $validated = $request->validate(['date' => ['sometimes', 'date_format:Y-m-d']], self::MESSAGES);
        $activityId = $record->group?->offering?->activity_id;
        // Places are counted for the day the child would move (default: today).
        $timezone = SessionApplicability::timezone($record);
        $at = isset($validated['date'])
            ? CarbonImmutable::createFromFormat('!Y-m-d', $validated['date'], $timezone) ?: CarbonImmutable::now($timezone)
            : CarbonImmutable::now($timezone);

        $groups = Group::query()->where('status', 'published')->whereKeyNot($record->group_id)
            ->when($allowed !== null, fn ($query) => $query->whereKey($allowed ?? []))
            ->whereHas('offering', fn ($query) => $query->where('activity_id', $activityId))
            ->with(['priceOptions', 'offering.priceOptions'])
            ->orderBy('name')
            ->get();

        // Children waiting for a place in each target group: a move takes a freed place before them.
        $waiting = EnrollmentApplication::query()->where('status', EnrollmentApplicationStatus::Waitlisted->value)
            ->whereIn('group_id', $groups->modelKeys())
            ->selectRaw('group_id, count(*) as aggregate')->groupBy('group_id')->pluck('aggregate', 'group_id');

        return response()->json([
            'groups' => $groups->map(fn (Group $group): array => [
                'id' => $group->id,
                'name' => $group->name,
                'capacity_max' => $group->capacity_max,
                'schedule' => $group->weeklySlots(),
                'remaining' => $capacity->remainingForGroup($group, $at),
                // The next session days with the places a move from that day would find.
                'starts' => $capacity->moveInDays($group),
                'waitlist_count' => (int) ($waiting[$group->id] ?? 0),
                // The prices the group takes (its own and the activity's for its ways of enrolling), for a period.
                'prices' => $group->pricesForEnrollment()->where('is_available', true)
                    ->whereIn('enrollment_mode', ChangeEnrollment::FULL_PERIOD_MODES)->sortBy('amount_cents')->map(fn (PriceOption $price): array => [
                        'id' => $price->id,
                        'enrollment_mode' => $price->enrollment_mode,
                        'unit' => $price->unit,
                        'amount_cents' => $price->amount_cents,
                        'currency' => $price->currency,
                    ])->values()->all(),
            ])->values()->all(),
        ]);
    }

    public function transfer(Request $request, string $enrollment, ChangeEnrollment $changes): JsonResponse
    {
        $this->authorizeManage($request);
        $record = Enrollment::query()->findOrFail($enrollment);
        $this->requireGroup($request, $record->group_id);
        $validated = $request->validate([
            'target_group_id' => ['required', 'uuid'],
            'price_option_id' => ['required', 'uuid'],
            'effective_date' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'max:500'],
            'version' => ['required', 'integer', 'min:1'],
        ], self::MESSAGES);

        // A move enrolls in the other group too.
        $this->requireGroup($request, (string) $validated['target_group_id']);
        $moved = $changes->transfer(
            $request->user(), $record, $validated['target_group_id'], $validated['price_option_id'],
            $validated['effective_date'], $validated['reason'], (int) $validated['version'],
        );

        return response()->json(['enrollment' => $this->presentEnrollment($moved)]);
    }

    public function pause(Request $request, string $enrollment, ChangeEnrollment $changes): JsonResponse
    {
        $this->authorizeManage($request);
        $record = Enrollment::query()->findOrFail($enrollment);
        $this->requireGroup($request, $record->group_id);
        $validated = $request->validate([
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d'],
            'reason_category' => ['required', Rule::in(ChangeEnrollment::PAUSE_REASONS)],
            'note' => ['sometimes', 'nullable', 'string', 'max:300'],
            'version' => ['required', 'integer', 'min:1'],
        ], [...self::MESSAGES, 'reason_category.in' => 'Изберете причина.']);

        $pause = $changes->pause(
            $request->user(), $record, $validated['starts_on'], $validated['ends_on'],
            $validated['reason_category'], $validated['note'] ?? null, (int) $validated['version'],
        );

        return response()->json(['pause' => $this->presentPause($pause)], Response::HTTP_CREATED);
    }

    public function endPause(Request $request, string $enrollment, string $pause, ChangeEnrollment $changes): JsonResponse
    {
        $this->authorizeManage($request);
        $record = Enrollment::query()->findOrFail($enrollment);
        $this->requireGroup($request, $record->group_id);
        $validated = $request->validate([
            'resume_on' => ['required', 'date_format:Y-m-d'],
            'version' => ['required', 'integer', 'min:1'],
        ], self::MESSAGES);

        $ended = $changes->endPause($request->user(), $record, $pause, $validated['resume_on'], (int) $validated['version']);

        return response()->json(['pause' => $this->presentPause($ended)]);
    }

    public function end(Request $request, string $enrollment, ChangeEnrollment $changes): JsonResponse
    {
        $this->authorizeManage($request);
        $record = Enrollment::query()->findOrFail($enrollment);
        $this->requireGroup($request, $record->group_id);
        $validated = $request->validate([
            'last_day' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'max:500'],
            'version' => ['required', 'integer', 'min:1'],
        ], self::MESSAGES);

        $ended = $changes->end($request->user(), $record, $validated['last_day'], $validated['reason'], (int) $validated['version']);

        return response()->json(['enrollment' => $this->presentEnrollment($ended)]);
    }

    public function reopen(Request $request, string $enrollment, ChangeEnrollment $changes): JsonResponse
    {
        $this->authorizeManage($request);
        $record = Enrollment::query()->findOrFail($enrollment);
        $this->requireGroup($request, $record->group_id);
        $validated = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
        ], self::MESSAGES);

        $reopened = $changes->reopen($request->user(), $record, (int) $validated['version']);

        return response()->json(['enrollment' => $this->presentEnrollment($reopened)]);
    }

    public function skipSession(Request $request, string $enrollment, ChangeEnrollment $changes): JsonResponse
    {
        $this->authorizeManage($request);
        $record = Enrollment::query()->findOrFail($enrollment);
        $this->requireGroup($request, $record->group_id);
        $validated = $request->validate([
            'session_id' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'max:500'],
            'version' => ['required', 'integer', 'min:1'],
        ], self::MESSAGES);

        $changes->skipSession($request->user(), $record, $validated['session_id'], $validated['reason'], (int) $validated['version']);

        return response()->json(['enrollment' => $this->presentEnrollment($record->refresh())]);
    }

    public function restoreSession(Request $request, string $enrollment, string $session, ChangeEnrollment $changes): JsonResponse
    {
        $this->authorizeManage($request);
        $record = Enrollment::query()->findOrFail($enrollment);
        $this->requireGroup($request, $record->group_id);
        $validated = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
        ], self::MESSAGES);

        $changes->restoreSession($request->user(), $record, $session, (int) $validated['version']);

        return response()->json(['enrollment' => $this->presentEnrollment($record->refresh())]);
    }

    /** @return array<string, mixed> */
    private function presentEnrollment(Enrollment $enrollment): array
    {
        return [
            'id' => $enrollment->id,
            'group_id' => $enrollment->group_id,
            'status' => $enrollment->status->value,
            'starts_on' => $enrollment->starts_on->format('Y-m-d'),
            'ends_on' => $enrollment->ends_on?->format('Y-m-d'),
            'version' => $enrollment->version,
        ];
    }

    /** @return array<string, mixed> */
    private function presentPause(EnrollmentPause $pause): array
    {
        return [
            'id' => $pause->id,
            'starts_on' => $pause->starts_on->format('Y-m-d'),
            'ends_on' => $pause->ends_on->format('Y-m-d'),
            'reason_category' => $pause->reason_category,
            'cancelled' => $pause->cancelled_at !== null,
        ];
    }

    /** Enrolling somewhere — otherwise 403 before anything is read, as before. */
    private function authorizeManage(Request $request): void
    {
        if (! $this->scope->access($this->user($request))->has('enrollment.enroll')) {
            throw new AuthorizationException;
        }
    }

    /** Enrolling in the record's group: the scope (docs/product/team-rights.md, т. 4.4, 9). */
    private function requireGroup(Request $request, string $groupId): void
    {
        abort_unless($this->scope->allows($this->user($request), 'enrollment.enroll', $groupId), 403, 'Нямате достъп до тази група.');
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
