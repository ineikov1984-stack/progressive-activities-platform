<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Actions\BootstrapOrganizationApplicant;
use App\Domain\Organizations\Actions\ReviewOrganizationApplication;
use App\Domain\Organizations\Enums\OrganizationStatus;
use App\Domain\Organizations\Models\Organization;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

final class OperatorOrganizationApplicationController extends Controller
{
    public function index(): JsonResponse
    {
        $applications = Organization::query()->withoutGlobalScopes()
            ->whereIn('status', [
                OrganizationStatus::Applicant->value,
                OrganizationStatus::Review->value,
                OrganizationStatus::Approved->value,
            ])
            ->orderBy('updated_at')
            ->get()
            ->map(fn (Organization $organization): array => $this->present($organization));

        return response()->json(['applications' => $applications]);
    }

    public function store(Request $request, BootstrapOrganizationApplicant $bootstrap): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'alpha_dash', 'max:255', 'unique:organizations,slug'],
            'owner_email' => ['required', 'email', 'max:254'],
        ]);
        $operator = $request->user();
        abort_unless($operator instanceof User, Response::HTTP_UNAUTHORIZED);

        $result = $bootstrap->execute(
            $operator,
            $validated['name'],
            $validated['slug'],
            $validated['owner_email'],
        );

        return response()->json([
            'application' => $this->present($result['organization']),
            'owner_invitation_token' => $result['token'],
        ], Response::HTTP_CREATED);
    }

    public function review(
        Request $request,
        string $organization,
        ReviewOrganizationApplication $review,
    ): JsonResponse {
        $validated = $request->validate([
            'review_version' => ['required', 'integer', 'min:1'],
            'to_status' => ['required', Rule::enum(OrganizationStatus::class)],
            'public_reason' => ['nullable', 'string', 'max:2000'],
            'next_action' => ['nullable', 'string', 'max:2000'],
            'internal_note' => ['nullable', 'string', 'max:5000'],
        ]);
        $operator = $request->user();
        abort_unless($operator instanceof User, Response::HTTP_UNAUTHORIZED);
        $application = Organization::query()->withoutGlobalScopes()->findOrFail($organization);

        $application = $review->execute(
            $operator,
            $application,
            OrganizationStatus::from($validated['to_status']),
            (int) $validated['review_version'],
            $validated['public_reason'] ?? null,
            $validated['next_action'] ?? null,
            $validated['internal_note'] ?? null,
        );

        return response()->json(['application' => $this->present($application)]);
    }

    /** @return array<string, mixed> */
    private function present(Organization $organization): array
    {
        return [
            'id' => $organization->id,
            'name' => $organization->name,
            'slug' => $organization->slug,
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
