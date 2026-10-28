<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\Models\Session;
use App\Domain\Enrollment\Actions\ChangeEnrollment;
use App\Domain\Enrollment\Actions\RecordVisitOutcome;
use App\Domain\Enrollment\Actions\SubmitEnrollmentApplication;
use App\Domain\Enrollment\Enums\EnrollmentApplicationStatus;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\EnrollmentApplication;
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
            'accepted_terms_version' => ['required', 'string', 'max:100'],
        ]);
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
                EnrollmentTransition::query()->create([
                    'application_id' => $current->id,
                    'actor_user_id' => $request->user()->id,
                    'from_status' => $fromStatus,
                    'to_status' => EnrollmentApplicationStatus::Withdrawn->value,
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
            'effective_date' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);
        $record = $this->ownedEnrollment($request, $enrollment);

        try {
            $updated = $change->endFrom(
                $request->user(), $record, $validated['effective_date'], $validated['reason'],
            );

            return response()->json(['enrollment' => $this->presentEnrollment($updated)]);
        } finally {
            $this->context->clear();
        }
    }

    public function visitOutcome(Request $request, string $enrollment, RecordVisitOutcome $recordOutcome): JsonResponse
    {
        $validated = $request->validate([
            'outcome' => ['required', 'in:attended,cancelled_in_time,late_cancelled,no_show'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        return DB::transaction(function () use ($request, $enrollment, $recordOutcome, $validated): JsonResponse {
            try {
                if (DB::getDriverName() === 'pgsql') {
                    DB::select("SELECT set_config('app.current_user_id', ?, true)", [$request->user()->id]);
                    $tenant = DB::selectOne('SELECT public.enrollment_owned_tenant(?::uuid, ?::uuid) AS id', [$enrollment, $request->user()->id])?->id;
                    abort_if($tenant === null, 404);
                    $this->context->select($tenant);
                    $this->setDatabaseTenant();
                }
                $record = $this->ownedEnrollment($request, $enrollment);
                $transition = $recordOutcome->handle($request->user(), $record, $validated['outcome'], $validated['reason'] ?? null);

                return response()->json(['enrollment' => $this->presentEnrollment($record->refresh()), 'outcome' => $transition->metadata['outcome']]);
            } finally {
                $this->context->clear();
            }
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
            'reason' => ['required', 'string', 'max:1000'],
        ]);
        $record = $this->ownedEnrollment($request, $enrollment);

        try {
            $session = Session::query()->findOrFail($validated['session_id']);
            $change->excludeSession($request->user(), $record, $session, $validated['reason']);

            return response()->json(['enrollment' => $this->presentEnrollment($record->refresh())]);
        } finally {
            $this->context->clear();
        }
    }

    /** @return array<string, mixed> */
    private function present(EnrollmentApplication $application): array
    {
        return [
            'id' => $application->id,
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
            'price' => [
                'amount_cents' => $application->amount_cents_snapshot,
                'currency' => $application->currency_snapshot,
                'unit' => $application->unit_snapshot,
            ],
            'public_reason' => $application->public_reason,
            'version' => $application->version,
        ];
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
