<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Actions\SubmitOrganizationApplication;
use App\Domain\Organizations\Enums\OrganizationStatus;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

final class OrganizationApplicationController extends Controller
{
    public function show(Request $request, TenantContext $context): JsonResponse
    {
        $organization = $this->organization($context);
        Gate::forUser($request->user())->authorize('viewApplication', $organization);

        return response()->json(['application' => $this->present($organization)]);
    }

    public function update(Request $request, TenantContext $context): JsonResponse
    {
        $organization = $this->organization($context);
        Gate::forUser($request->user())->authorize('updateApplication', $organization);

        if ($organization->status !== OrganizationStatus::Applicant) {
            return response()->json([
                'message' => 'Само заявление в чернова може да бъде редактирано.',
                'application' => $this->present($organization),
            ], Response::HTTP_CONFLICT);
        }

        $validated = $request->validate([
            'review_version' => ['required', 'integer', 'min:1'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'organization_type' => ['sometimes', 'nullable', 'string', 'max:48'],
            'registration_identifier' => ['sometimes', 'nullable', 'string', 'max:64'],
            'public_contact_email' => ['sometimes', 'nullable', 'email', 'max:254'],
        ]);

        $expectedVersion = (int) $validated['review_version'];
        unset($validated['review_version']);

        $updated = Organization::query()
            ->whereKey($organization->id)
            ->where('status', OrganizationStatus::Applicant->value)
            ->where('review_version', $expectedVersion)
            ->update([
                ...$validated,
                'review_version' => $expectedVersion + 1,
                'updated_at' => now(),
            ]);

        $current = Organization::query()->findOrFail($organization->id);

        if ($updated !== 1) {
            return response()->json([
                'message' => 'Заявлението е променено. Заредете актуалната версия и опитайте отново.',
                'application' => $this->present($current),
            ], Response::HTTP_CONFLICT);
        }

        return response()->json(['application' => $this->present($current)]);
    }

    public function submit(
        Request $request,
        TenantContext $context,
        SubmitOrganizationApplication $submitApplication,
    ): JsonResponse {
        $validated = $request->validate([
            'review_version' => ['required', 'integer', 'min:1'],
        ]);
        $user = $request->user();
        abort_unless($user instanceof User, Response::HTTP_UNAUTHORIZED);

        $organization = $submitApplication->execute(
            $user,
            $this->organization($context),
            (int) $validated['review_version'],
        );

        return response()->json(['application' => $this->present($organization)]);
    }

    private function organization(TenantContext $context): Organization
    {
        return Organization::query()->findOrFail($context->organizationId());
    }

    /** @return array<string, mixed> */
    private function present(Organization $organization): array
    {
        return [
            'id' => $organization->id,
            'name' => $organization->name,
            'status' => $organization->status->value,
            'review_version' => $organization->review_version,
            'organization_type' => $organization->organization_type,
            'registration_identifier' => $organization->registration_identifier,
            'public_contact_email' => $organization->public_contact_email,
            'public_review_reason' => $organization->public_review_reason,
            'next_action' => $organization->next_action,
            'submitted_at' => $organization->submitted_at?->toAtomString(),
            'reviewed_at' => $organization->reviewed_at?->toAtomString(),
        ];
    }
}
