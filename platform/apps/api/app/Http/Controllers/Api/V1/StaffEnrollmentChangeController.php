<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\Models\Group;
use App\Domain\Catalog\Models\PriceOption;
use App\Domain\Enrollment\Actions\ChangeEnrollment;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\EnrollmentPause;
use App\Domain\Enrollment\Services\EnrollmentCapacity;
use App\Domain\Enrollment\Services\SessionApplicability;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Transfer, pause, end and skip for the organization's team (docs/product/staff-enrollment.md, т. 9).
 * Only owner, administrator and coordinator (enrollment.manage); another organization's records are 404.
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

    public function __construct(private readonly TenantContext $context) {}

    public function transferOptions(Request $request, string $enrollment, EnrollmentCapacity $capacity): JsonResponse
    {
        $this->authorizeManage($request);
        $record = Enrollment::query()->with('group.offering')->findOrFail($enrollment);
        $validated = $request->validate(['date' => ['sometimes', 'date_format:Y-m-d']], self::MESSAGES);
        $activityId = $record->group?->offering?->activity_id;
        // Places are counted for the day the child would move (default: today).
        $timezone = SessionApplicability::timezone($record);
        $at = isset($validated['date'])
            ? CarbonImmutable::createFromFormat('!Y-m-d', $validated['date'], $timezone) ?: CarbonImmutable::now($timezone)
            : CarbonImmutable::now($timezone);

        $groups = Group::query()->where('status', 'published')->whereKeyNot($record->group_id)
            ->whereHas('offering', fn ($query) => $query->where('activity_id', $activityId))
            ->with(['priceOptions' => fn ($query) => $query->where('is_available', true)
                ->whereIn('enrollment_mode', ChangeEnrollment::FULL_PERIOD_MODES)->orderBy('amount_cents')])
            ->orderBy('name')
            ->get();

        return response()->json([
            'groups' => $groups->map(fn (Group $group): array => [
                'id' => $group->id,
                'name' => $group->name,
                'capacity_max' => $group->capacity_max,
                'remaining' => $capacity->remainingForGroup($group, $at),
                'prices' => $group->priceOptions->map(fn (PriceOption $price): array => [
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
        $validated = $request->validate([
            'target_group_id' => ['required', 'uuid'],
            'price_option_id' => ['required', 'uuid'],
            'effective_date' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'max:500'],
            'version' => ['required', 'integer', 'min:1'],
        ], self::MESSAGES);

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

    private function authorizeManage(Request $request): void
    {
        $organization = Organization::query()->findOrFail($this->context->organizationId());
        Gate::forUser($request->user())->authorize('enrollment.manage', $organization);
    }
}
