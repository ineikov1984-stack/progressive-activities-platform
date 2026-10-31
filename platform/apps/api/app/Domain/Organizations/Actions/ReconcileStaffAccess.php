<?php

namespace App\Domain\Organizations\Actions;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\ValueObjects\AuditableReference;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Enums\AbilityKind;
use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Enums\StaffScope;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\StaffGrant;
use App\Domain\Organizations\Models\StaffMember;
use App\Domain\Organizations\Models\StaffScopeGroup;
use App\Domain\Organizations\Services\StaffRights;
use App\Domain\Organizations\Services\TeamAbilities;
use App\Domain\Organizations\Services\TeamRoles;
use App\Domain\Organizations\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;

/**
 * Brings a person's rights in line with a new role (docs/product/team-rights.md, т. 4.6) — called by the change of role,
 * a new invitation with another role, the acceptance of an invitation, and the archive (no role at all):
 * 1. the added rights the role already gives are revoked ("role_changed");
 * 2. a scope the role does not allow goes back to the role's default;
 * 3. when the scope is no longer "groups", the selected groups are removed;
 * 4. when the scope is not "all", the rights for the whole organization only are revoked ("scope_changed");
 * 5. the label of the template goes unless the role is a viewer with selected groups and both of its rights;
 * 6. someone other than the owner may not take finance away this way — 403 and nothing is written (т. 5); the archive
 *    may, the person leaves;
 * 7. every change goes to the audit, identifiers and keys only.
 * The same role again changes nothing.
 */
final readonly class ReconcileStaffAccess
{
    public function __construct(
        private TenantContext $context,
        private TeamRoles $roles,
        private StaffRights $rights,
        private AuditRecorder $audit,
    ) {}

    /**
     * What the role would take away, without writing anything — for the screen before "Запази" (`GET …/access?role=`).
     *
     * @return array{revoke: list<array{id: string, ability: string, reason: string}>, clear_scope: bool, remove_groups: list<string>, clear_template: bool, loses_finance: bool}
     */
    public function plan(StaffMember $member, ?OrganizationRole $role): array
    {
        return $this->compute($member, $role)['plan'];
    }

    /**
     * In the caller's transaction, after its locks (organization → person → membership).
     *
     * @return array{revoke: list<array{id: string, ability: string, reason: string}>, clear_scope: bool, remove_groups: list<string>, clear_template: bool, loses_finance: bool}
     */
    public function handle(User $actor, StaffMember $member, ?OrganizationRole $role): array
    {
        abort_unless($member->getAttribute('organization_id') === $this->context->organizationId(), 404);
        ['plan' => $plan, 'grants' => $grants, 'groups' => $groups] = $this->compute($member, $role);
        if ($role !== null && $plan['loses_finance']
            && $this->roles->actorRole($actor, Organization::query()->findOrFail($this->context->organizationId())) !== OrganizationRole::OrganizationOwner) {
            throw new AuthorizationException('Това би отнело достъпа до финансите — може само собственикът.');
        }

        $now = now()->toImmutable();
        $subject = new AuditableReference('staff_member', (string) $member->id);
        foreach ($plan['revoke'] as $revoked) {
            $grants[$revoked['id']]->forceFill(['revoked_at' => $now, 'revoked_by' => $actor->id, 'revoked_reason' => $revoked['reason']])->save();
            $this->audit->record(AuditAction::TeamAccessGrantRevoked, $subject, [
                'grant_id' => $revoked['id'], 'ability' => $revoked['ability'], 'reason' => $revoked['reason'],
            ]);
        }
        foreach ($plan['remove_groups'] as $id) {
            $row = $groups[$id];
            $row->forceFill(['removed_at' => $now, 'removed_by' => $actor->id])->save();
            $this->audit->record(AuditAction::TeamAccessGroupRemoved, $subject, [
                'scope_group_id' => $id, 'group_id' => $row->group_id, 'activity_id' => $row->activity_id,
            ]);
        }
        if ($plan['clear_scope']) {
            $this->audit->record(AuditAction::TeamAccessScopeChanged, $subject, ['from' => $member->access_scope, 'to' => null]);
            $member->access_scope = null;
        }
        if ($plan['clear_template']) {
            $this->audit->record(AuditAction::TeamAccessTemplateChanged, $subject, ['from' => $member->access_template, 'to' => null]);
            $member->access_template = null;
        }
        if ($plan['revoke'] !== [] || $plan['remove_groups'] !== [] || $plan['clear_scope'] || $plan['clear_template']) {
            // A card of rights opened before this gets a conflict (т. 4.1).
            $member->version++;
            $member->save();
            $this->rights->forget();
        }

        return $plan;
    }

    /**
     * @return array{plan: array{revoke: list<array{id: string, ability: string, reason: string}>, clear_scope: bool, remove_groups: list<string>, clear_template: bool, loses_finance: bool}, grants: Collection<string, StaffGrant>, groups: Collection<string, StaffScopeGroup>}
     */
    private function compute(StaffMember $member, ?OrganizationRole $role): array
    {
        $now = now();
        /** @var Collection<string, StaffGrant> $grants */
        $grants = StaffGrant::query()->where('staff_member_id', $member->id)->whereNull('revoked_at')
            ->where(fn ($open) => $open->whereNull('expires_at')->orWhere('expires_at', '>', $now))
            ->orderBy('granted_at')->orderBy('id')->get()->keyBy(fn (StaffGrant $grant): string => (string) $grant->id);
        /** @var Collection<string, StaffScopeGroup> $groups */
        $groups = StaffScopeGroup::query()->where('staff_member_id', $member->id)->whereNull('removed_at')
            ->orderBy('added_at')->orderBy('id')->get()->keyBy(fn (StaffScopeGroup $row): string => (string) $row->id);

        $revoke = [];
        if ($role === null) {
            foreach ($grants as $id => $grant) {
                $revoke[$id] = 'archived';
            }

            return ['plan' => [
                'revoke' => $this->revocations($grants, $revoke),
                'clear_scope' => $member->access_scope !== null,
                'remove_groups' => array_keys($groups->all()),
                'clear_template' => $member->access_template !== null,
                'loses_finance' => false,
            ], 'grants' => $grants, 'groups' => $groups];
        }

        $gives = array_map(TeamAbilities::canonical(...), $role->abilities());
        foreach ($grants as $id => $grant) {
            if (in_array($grant->ability, $gives, true)) {
                $revoke[$id] = 'role_changed';
            }
        }
        $chosen = $member->access_scope === null ? null : StaffScope::tryFrom($member->access_scope);
        $clearScope = $member->access_scope !== null && ($chosen === null || ! in_array($chosen, $role->allowedScopes(), true));
        $scope = ($clearScope ? null : $chosen) ?? $role->defaultScope();
        $lostFinance = false;
        if ($scope !== StaffScope::All) {
            foreach ($grants as $id => $grant) {
                if (! isset($revoke[$id]) && TeamAbilities::kind($grant->ability) === AbilityKind::WholeOrganization) {
                    $revoke[$id] = 'scope_changed';
                    $lostFinance = $lostFinance || TeamAbilities::isFinance($grant->ability);
                }
            }
        }
        $kept = $grants->reject(fn (StaffGrant $grant, string $id): bool => isset($revoke[$id]))->pluck('ability')->all();
        $clearTemplate = $member->access_template !== null && ($role !== OrganizationRole::Viewer || $scope !== StaffScope::Groups
            || array_diff(TeamAbilities::SCHOOL_PARTNER, $kept) !== []);

        return ['plan' => [
            'revoke' => $this->revocations($grants, $revoke),
            'clear_scope' => $clearScope,
            'remove_groups' => $scope === StaffScope::Groups ? [] : array_keys($groups->all()),
            'clear_template' => $clearTemplate,
            'loses_finance' => $lostFinance,
        ], 'grants' => $grants, 'groups' => $groups];
    }

    /**
     * @param  Collection<string, StaffGrant>  $grants
     * @param  array<string, string>  $reasons
     * @return list<array{id: string, ability: string, reason: string}>
     */
    private function revocations(Collection $grants, array $reasons): array
    {
        $result = [];
        foreach ($reasons as $id => $reason) {
            $result[] = ['id' => (string) $id, 'ability' => $grants[$id]->ability, 'reason' => $reason];
        }

        return $result;
    }
}
