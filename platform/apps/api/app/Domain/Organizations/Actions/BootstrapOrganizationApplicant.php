<?php

namespace App\Domain\Organizations\Actions;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\ValueObjects\AuditableReference;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Enums\OrganizationStatus;
use App\Domain\Organizations\Models\Invitation;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class BootstrapOrganizationApplicant
{
    public function __construct(
        private AuditRecorder $auditRecorder,
        private TenantContext $tenantContext,
    ) {}

    /** @return array{organization: Organization, token: string} */
    public function execute(User $operator, string $name, string $slug, string $ownerEmail): array
    {
        return DB::transaction(function () use ($operator, $name, $slug, $ownerEmail): array {
            $organization = Organization::query()->withoutGlobalScopes()->create([
                'name' => $name,
                'slug' => $slug,
                'status' => OrganizationStatus::Applicant->value,
            ]);
            $organization->refresh();
            $email = Str::lower(trim($ownerEmail));
            $token = Str::random(64);

            Invitation::query()->withoutGlobalScopes()->create([
                'organization_id' => $organization->id,
                'email' => $email,
                'role' => OrganizationRole::OrganizationOwner->value,
                'token_hash' => hash('sha256', $token),
                'active_key' => hash('sha256', $organization->id.'|'.$email),
                'expires_at' => now()->addMinutes((int) config('invitations.expires_after_minutes')),
                'invited_by' => $operator->id,
            ]);

            $this->tenantContext->select($organization);
            try {
                $this->auditRecorder->record(
                    AuditAction::OrganizationApplicantCreated,
                    new AuditableReference('organization', $organization->id),
                );
            } finally {
                $this->tenantContext->clear();
            }

            return ['organization' => $organization, 'token' => $token];
        });
    }
}
