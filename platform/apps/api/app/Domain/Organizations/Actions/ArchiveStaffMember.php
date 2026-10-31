<?php

namespace App\Domain\Organizations\Actions;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\ValueObjects\AuditableReference;
use App\Domain\Catalog\Services\StaffTeaching;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Organizations\Models\StaffMember;
use App\Domain\Organizations\Services\TeamRoles;
use App\Domain\Organizations\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Archives a person of the team (docs/product/team.md, т. 6): they leave the lists and the choices, their login to the
 * organization stops, nothing is deleted. Not while they lead or assist a group or teach a coming session.
 */
final readonly class ArchiveStaffMember
{
    public function __construct(
        private TenantContext $context,
        private StaffTeaching $teaching,
        private TeamRoles $roles,
        private AuditRecorder $audit,
    ) {}

    public function handle(User $actor, StaffMember $member, int $version): StaffMember
    {
        abort_unless($member->getAttribute('organization_id') === $this->context->organizationId(), 404);
        $organization = Organization::query()->findOrFail($this->context->organizationId());
        Gate::forUser($actor)->authorize('manageTeam', $organization);

        return DB::transaction(function () use ($actor, $member, $version, $organization): StaffMember {
            // The organization first: two owners archiving each other at the same moment go one after the other.
            Organization::query()->whereKey($organization->id)->lockForUpdate()->firstOrFail();
            $member = StaffMember::query()->whereKey($member->id)->lockForUpdate()->firstOrFail();
            abort_unless($member->version === $version, 409, 'Staff member version conflict.');
            if ($member->archived_at !== null) {
                throw ValidationException::withMessages(['member' => 'Човекът вече е архивиран.']);
            }
            $membership = $member->user_id === null ? null : OrganizationMembership::query()->withoutGlobalScopes()
                ->where('organization_id', $organization->id)->where('user_id', $member->user_id)->lockForUpdate()->first();
            $this->assertMayStop($actor, $member, $membership, $organization);

            $profile = $this->teaching->profilesFor([$member->id])[$member->id] ?? null;
            $blocker = $profile === null ? null : $this->teaching->archiveBlocker($profile);
            if ($blocker !== null) {
                throw ValidationException::withMessages(['member' => $blocker]);
            }

            $member->archived_at = now()->toImmutable();
            $member->version++;
            $member->save();
            if ($membership !== null && $membership->getAttribute('status') === 'active') {
                $membership->forceFill(['status' => 'suspended'])->save();
            }
            $this->audit->record(AuditAction::TeamMemberArchived, new AuditableReference('staff_member', $member->id), [
                'login_stopped' => $membership !== null,
            ]);

            return $member;
        });
    }

    /** The rules of a role change (т. 4) hold for stopping a login too. */
    private function assertMayStop(User $actor, StaffMember $member, ?OrganizationMembership $membership, Organization $organization): void
    {
        if ($member->user_id === $actor->id) {
            throw ValidationException::withMessages(['member' => 'Не можете да архивирате себе си.']);
        }
        if ($membership === null) {
            return;
        }
        $actorRole = OrganizationMembership::query()->withoutGlobalScopes()->where('organization_id', $organization->id)
            ->where('user_id', $actor->id)->where('status', 'active')->value('role');
        $role = (string) $membership->getAttribute('role');
        $protected = [OrganizationRole::OrganizationOwner->value, OrganizationRole::OrganizationAdmin->value, OrganizationRole::PlatformAdmin->value];
        if ($actorRole !== OrganizationRole::OrganizationOwner->value && in_array($role, $protected, true)) {
            throw new AuthorizationException('Нямате право да архивирате този човек.');
        }
        if ($this->roles->isLastActiveOwner($organization, $member->user_id)) {
            throw ValidationException::withMessages(['member' => 'Организацията трябва да има поне един собственик.']);
        }
    }
}
