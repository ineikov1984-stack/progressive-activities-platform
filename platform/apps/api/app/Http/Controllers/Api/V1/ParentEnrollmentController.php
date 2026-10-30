<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Enrollment\Actions\ChangeEnrollment;
use App\Domain\Enrollment\Actions\RecordVisitOutcome;
use App\Domain\Enrollment\Actions\SubmitEnrollmentApplication;
use App\Domain\Enrollment\Enums\EnrollmentApplicationStatus;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\EnrollmentApplication;
use App\Domain\Enrollment\Models\EnrollmentEvent;
use App\Domain\Enrollment\Models\EnrollmentTransition;
use App\Domain\Organizations\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final class ParentEnrollmentController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(Request $request): JsonResponse
    {
        $applications = EnrollmentApplication::query()->withoutGlobalScopes()
            ->where('parent_user_id', $request->user()->id)
            // Eager loading is limited to these owned application IDs; database RLS remains active.
            ->with(['enrollment' => fn ($query) => $query->withoutGlobalScopes()])
            ->latest()->get();

        return response()->json(['applications' => $applications->map($this->present(...))->all()]);
    }

    public function store(Request $request, SubmitEnrollmentApplication $submit): JsonResponse
    {
        $idempotencyKey = trim((string) $request->header('Idempotency-Key'));
        if ($idempotencyKey === '' || mb_strlen($idempotencyKey) > 120) {
            throw ValidationException::withMessages(['idempotency_key' => 'Изпратете валиден ключ за заявката.']);
        }

        $payload = $request->validate([
            'child_id' => ['required', 'uuid'],
            'group_id' => ['required_without:visit_offer_id', 'uuid'],
            'price_option_id' => ['required_without:visit_offer_id', 'uuid'],
            'visit_offer_id' => ['sometimes', 'nullable', 'uuid'],
            'session_id' => ['required_with:visit_offer_id', 'nullable', 'uuid'],
            // For a price: the `payment_terms.version` the parent read on the activity's page.
            'accepted_terms_version' => ['required', 'string', 'max:100'],
            // The child starts before the 14 days to withdraw are over, at the parent's request (payment-policies.md, т. 3.6).
            'early_start' => ['sometimes', 'boolean:strict'],
            // Seen only by the organization's team; health details have their own place later.
            'parent_note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ], ['parent_note.max' => 'Бележката е до 500 знака.']);
        $application = $submit->handle($request->user(), $payload, $idempotencyKey);

        return response()->json(
            ['application' => $this->present($application)],
            $application->wasRecentlyCreated ? Response::HTTP_CREATED : Response::HTTP_OK,
        );
    }

    public function withdraw(Request $request, string $application): JsonResponse
    {
        $validated = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $record = EnrollmentApplication::query()->withoutGlobalScopes()
            ->where('parent_user_id', $request->user()->id)->findOrFail($application);
        $this->context->select($record->organization_id);

        try {
            DB::transaction(function () use ($record, $request, $validated): void {
                $this->setDatabaseTenant();
                $current = EnrollmentApplication::query()->lockForUpdate()->findOrFail($record->id);

                if (! in_array($current->status, [
                    EnrollmentApplicationStatus::Submitted,
                    EnrollmentApplicationStatus::Waitlisted,
                ], true)) {
                    throw ValidationException::withMessages(['status' => 'Тази заявка вече не може да бъде оттеглена.']);
                }

                if ($current->version !== $validated['version']) {
                    abort(Response::HTTP_CONFLICT, 'Заявката е променена междувременно. Презаредете и опитайте отново.');
                }

                $fromStatus = $current->status->value;
                $current->update([
                    'status' => EnrollmentApplicationStatus::Withdrawn,
                    'version' => $current->version + 1,
                ]);
                $transition = EnrollmentTransition::query()->create([
                    'application_id' => $current->id,
                    'actor_user_id' => $request->user()->id,
                    'from_status' => $fromStatus,
                    'to_status' => EnrollmentApplicationStatus::Withdrawn->value,
                ]);
                // The team is told that the parent withdrew (docs/product/enrollment-contacts-and-emails.md, т. 6.2).
                EnrollmentEvent::query()->create([
                    'type' => 'enrollment.application_withdrawn',
                    'application_id' => $current->id,
                    'payload' => ['status' => EnrollmentApplicationStatus::Withdrawn->value, 'transition_id' => $transition->id],
                    'occurred_at' => now(),
                ]);
            });

            return response()->json(['application' => $this->present($record->refresh())]);
        } finally {
            $this->context->clear();
        }
    }

    public function endFrom(Request $request, string $enrollment, ChangeEnrollment $change): JsonResponse
    {
        $validated = $request->validate([
            'effective_date' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        // Same rules as for the team (docs/product/staff-enrollment.md, т. 9.4); the parent sends no version.
        return $this->withOwnedEnrollment($request, $enrollment, fn (Enrollment $record): JsonResponse => response()->json([
            'enrollment' => $this->presentEnrollment($change->end($request->user(), $record, $validated['effective_date'], $validated['reason'], null)),
        ]));
    }

    public function visitOutcome(Request $request, string $enrollment, RecordVisitOutcome $recordOutcome): JsonResponse
    {
        $validated = $request->validate([
            'outcome' => ['required', 'in:attended,cancelled_in_time,late_cancelled,no_show'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        return $this->withOwnedEnrollment($request, $enrollment, function (Enrollment $record) use ($request, $recordOutcome, $validated): JsonResponse {
            $transition = $recordOutcome->handle($request->user(), $record, $validated['outcome'], $validated['reason'] ?? null);

            return response()->json(['enrollment' => $this->presentEnrollment($record->refresh()), 'outcome' => $transition->metadata['outcome']]);
        });
    }

    public function staffVisitOutcome(Request $request, string $enrollment, RecordVisitOutcome $recordOutcome): JsonResponse
    {
        $validated = $request->validate([
            'outcome' => ['required', 'in:attended,cancelled_in_time,late_cancelled,no_show'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);
        $record = Enrollment::query()->findOrFail($enrollment);
        $transition = $recordOutcome->handle($request->user(), $record, $validated['outcome'], $validated['reason'] ?? null);

        return response()->json(['enrollment' => $this->presentEnrollment($record->refresh()), 'outcome' => $transition->metadata['outcome']]);
    }

    public function excludeSession(Request $request, string $enrollment, ChangeEnrollment $change): JsonResponse
    {
        $validated = $request->validate([
            'session_id' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        return $this->withOwnedEnrollment($request, $enrollment, function (Enrollment $record) use ($request, $change, $validated): JsonResponse {
            $change->skipSession($request->user(), $record, $validated['session_id'], $validated['reason'], null);

            return response()->json(['enrollment' => $this->presentEnrollment($record->refresh())]);
        });
    }

    /** @return array<string, mixed> */
    private function present(EnrollmentApplication $application): array
    {
        return [
            'id' => $application->id,
            'enrollment_id' => $application->relationLoaded('enrollment') ? $application->enrollment?->id : null,
            'organization_id' => $application->organization_id,
            'parent_user_id' => $application->parent_user_id,
            'child_id' => $application->child_id,
            'group_id' => $application->group_id,
            'price_option_id' => $application->price_option_id,
            'visit_offer_id' => $application->visit_offer_id,
            'visit_offer_title_snapshot' => $application->visit_offer_title_snapshot,
            'fee_reason_snapshot' => $application->fee_reason_snapshot,
            'cancellation_policy_snapshot' => $application->cancellation_policy_snapshot,
            'session_id' => $application->session_id,
            'scope' => $application->scope->value,
            'source' => $application->source->value,
            'status' => $application->status->value,
            'accepted_terms_version' => $application->accepted_terms_version,
            'accepted_terms_at' => $application->accepted_terms_at?->toIso8601String(),
            // The parent's own copy of the terms (who on the team accepted a later copy is not shown).
            'payment_terms' => $application->payment_terms_snapshot === null ? null : [
                'name' => $application->payment_terms_snapshot['name'] ?? null,
                'billing_kind' => $application->payment_terms_snapshot['billing_kind'] ?? null,
                'preset' => $application->payment_terms_snapshot['preset'] ?? null,
                'schema_version' => $application->payment_terms_snapshot['schema_version'] ?? null,
                'rules' => $application->payment_terms_snapshot['rules'] ?? null,
                'early_start' => $application->payment_terms_snapshot['early_start'] ?? false,
            ],
            'price' => [
                'amount_cents' => $application->amount_cents_snapshot,
                'currency' => $application->currency_snapshot,
                'unit' => $application->unit_snapshot,
            ],
            'public_reason' => $application->public_reason,
            'parent_note' => $application->parent_note,
            'version' => $application->version,
        ];
    }

    /**
     * Runs a change on the parent's own enrollment in one transaction, so the tenant set for
     * PostgreSQL RLS (transaction-local) covers the lookup and the whole change.
     *
     * @param  callable(Enrollment): JsonResponse  $callback
     */
    private function withOwnedEnrollment(Request $request, string $enrollment, callable $callback): JsonResponse
    {
        return DB::transaction(function () use ($request, $enrollment, $callback): JsonResponse {
            try {
                if (DB::getDriverName() === 'pgsql') {
                    DB::select("SELECT set_config('app.current_user_id', ?, true)", [$request->user()->id]);
                    $tenant = DB::selectOne('SELECT public.enrollment_owned_tenant(?::uuid, ?::uuid) AS id', [$enrollment, $request->user()->id])?->id;
                    abort_if($tenant === null, 404);
                    $this->context->select($tenant);
                    $this->setDatabaseTenant();
                }

                return $callback($this->ownedEnrollment($request, $enrollment));
            } finally {
                $this->context->clear();
            }
        });
    }

    private function ownedEnrollment(Request $request, string $enrollment): Enrollment
    {
        $record = Enrollment::query()->withoutGlobalScopes()
            ->whereHas('application', fn ($query) => $query->withoutGlobalScopes()
                ->where('parent_user_id', $request->user()->id))
            ->findOrFail($enrollment);
        $this->context->select($record->organization_id);
        $this->setDatabaseTenant();

        return $record;
    }

    /** @return array<string, mixed> */
    private function presentEnrollment(Enrollment $enrollment): array
    {
        return [
            'id' => $enrollment->id,
            'child_id' => $enrollment->child_id,
            'group_id' => $enrollment->group_id,
            'session_id' => $enrollment->session_id,
            'scope' => $enrollment->scope->value,
            'status' => $enrollment->status->value,
            'starts_on' => $enrollment->starts_on->format('Y-m-d'),
            'ends_on' => $enrollment->ends_on?->format('Y-m-d'),
            'version' => $enrollment->version,
        ];
    }

    private function setDatabaseTenant(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::select("SELECT set_config('app.current_organization_id', ?, true)", [$this->context->organizationId()]);
        }
    }
}
