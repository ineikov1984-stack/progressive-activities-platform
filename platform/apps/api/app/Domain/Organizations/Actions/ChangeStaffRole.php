<?php

namespace App\Domain\Organizations\Actions;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\ValueObjects\AuditableReference;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Organizations\Models\StaffMember;
use App\Domain\Organizations\Services\TeamRoles;
use App\Domain\Organizations\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Changes the role of a person who logs in (docs/product/team.md, т. 4): nobody changes their own role, the last
 * active owner stays, an administrator gives and changes only coordinator, teacher, accountant and viewer. Every change
 * goes to the audit, from and to.
 */
final readonly class ChangeStaffRole
{
    public function __construct(private TenantContext $context, private TeamRoles $roles, private AuditRecorder $audit) {}

    /** @param  array<string, mixed>  $payload */
    public function handle(User $actor, StaffMember $member, array $payload): StaffMember
    {
        abort_unless($member->getAttribute('organization_id') === $this->context->organizationId(), 404);
        $organization = Organization::query()->findOrFail($this->context->organizationId());
        Gate::forUser($actor)->authorize('manageTeam', $organization);
        $data = Validator::make($payload, [
            'role' => ['required', Rule::enum(OrganizationRole::class)],
            'version' => ['required', 'integer', 'min:1'],
        ], [
            'role.required' => 'Изберете роля.',
            'role.enum' => 'Изберете роля от списъка.',
        ])->validate();
        $to = OrganizationRole::from((string) $data['role']);

        return DB::transaction(function () use ($actor, $member, $organization, $to, $data): StaffMember {
            // The organization first: two owners changing each other at the same moment go one after the other.
            Organization::query()->whereKey($organization->id)->lockForUpdate()->firstOrFail();
            $member = StaffMember::query()->whereKey($member->id)->lockForUpdate()->firstOrFail();
            abort_unless($member->version === (int) $data['version'], 409, 'Staff member version conflict.');
            if ($member->archived_at !== null) {
                throw ValidationException::withMessages(['member' => 'Човекът е архивиран.']);
            }
            $membership = $member->user_id === null ? null : OrganizationMembership::query()->withoutGlobalScopes()
                ->where('organization_id', $organization->id)->where('user_id', $member->user_id)->lockForUpdate()->first();
            $from = $membership === null ? null : OrganizationRole::tryFrom((string) $membership->getAttribute('role'));
            if ($membership === null || $from === null) {
                throw ValidationException::withMessages(['role' => 'Роля има само човек, който влиза в системата. Първо го поканете.']);
            }
            if ($member->user_id === $actor->id) {
                throw ValidationException::withMessages(['role' => 'Не можете да смените собствената си роля.']);
            }
            $this->roles->assertMayChange($actor, $organization, $from, $to);
            if ($from === $to) {
                return $member;
            }
            if ($this->roles->isLastActiveOwner($organization, $member->user_id)) {
                throw ValidationException::withMessages(['role' => 'Организацията трябва да има поне един собственик.']);
            }

            $membership->forceFill(['role' => $to->value])->save();
            $member->version++;
            $member->save();
            $this->audit->record(AuditAction::TeamRoleChanged, new AuditableReference('staff_member', $member->id), [
                'membership_id' => $membership->id, 'from' => $from->value, 'to' => $to->value,
            ]);

            return $member;
        });
    }
}
