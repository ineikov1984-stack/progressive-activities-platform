<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Organizations\Actions\InviteMember;
use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Models\Invitation;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class InvitationController extends Controller
{
    public function index(Request $request, TenantContext $context): JsonResponse
    {
        $organization = Organization::query()->findOrFail($context->organizationId());
        Gate::forUser($request->user())->authorize('invite', $organization);

        $invitations = Invitation::query()
            ->latest()
            ->get()
            ->map(fn (Invitation $invitation): array => $this->presentInvitation($invitation));

        return response()->json(['invitations' => $invitations]);
    }

    public function store(Request $request, TenantContext $context, InviteMember $inviteMember): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email:rfc', 'max:254'],
            'role' => ['required', Rule::enum(OrganizationRole::class)],
        ]);

        $organization = Organization::query()->findOrFail($context->organizationId());
        $result = $inviteMember->invite(
            $request->user(),
            $organization,
            $validated['email'],
            OrganizationRole::from($validated['role']),
        );

        return response()->json([
            'invitation' => $this->presentInvitation($result['invitation']),
            'token' => $result['token'],
        ], Response::HTTP_CREATED);
    }

    public function accept(Request $request, InviteMember $inviteMember): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'size:64'],
        ]);
        $organizationId = $request->session()->get('active_organization_id');

        if (! is_string($organizationId) || $organizationId === '') {
            return response()->json(
                ['message' => 'Изберете организация, за да продължите.'],
                Response::HTTP_CONFLICT,
            );
        }

        $membership = $inviteMember->accept($request->user(), $organizationId, $validated['token']);

        return response()->json([
            'membership' => [
                'id' => $membership->id,
                'organization_id' => $membership->organization_id,
                'role' => $membership->role,
                'status' => $membership->status,
            ],
        ]);
    }

    /** @return array{id: string, organization_id: string, email: string, role: string, status: string, expires_at: string} */
    private function presentInvitation(Invitation $invitation): array
    {
        $status = match (true) {
            $invitation->accepted_at !== null => 'accepted',
            $invitation->expires_at->isPast() => 'expired',
            default => 'pending',
        };

        return [
            'id' => $invitation->id,
            'organization_id' => $invitation->organization_id,
            'email' => $invitation->email,
            'role' => $invitation->role->value,
            'status' => $status,
            'expires_at' => $invitation->expires_at->toIso8601String(),
        ];
    }
}
