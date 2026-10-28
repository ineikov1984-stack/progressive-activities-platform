<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Enrollment\Actions\SubmitEnrollmentApplication;
use App\Domain\Enrollment\Enums\EnrollmentApplicationStatus;
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
            'group_id' => ['required', 'uuid'],
            'price_option_id' => ['required', 'uuid'],
            'session_id' => ['sometimes', 'nullable', 'uuid'],
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

    private function setDatabaseTenant(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::select("SELECT set_config('app.current_organization_id', ?, true)", [$this->context->organizationId()]);
        }
    }
}
