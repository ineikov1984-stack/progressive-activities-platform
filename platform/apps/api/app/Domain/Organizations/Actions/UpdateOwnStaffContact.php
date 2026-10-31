<?php

namespace App\Domain\Organizations\Actions;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\ValueObjects\AuditableReference;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Organizations\Models\StaffMember;
use App\Domain\Organizations\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * "Моят профил": the name and phone a person changes themselves go into their record of the team in the active
 * organization too, so "Екип" shows the new phone (docs/product/team.md, т. 9; DECISIONS 31.10.2026 „Моят профил и
 * заместник“). Only that organization: another organization of the same user keeps its own record, as each team keeps
 * its own data and the person changes it there, from that organization. An emptied phone leaves the team's phone as it
 * is — the team needs one. The audit holds the names of the fields only, never their values.
 */
final readonly class UpdateOwnStaffContact
{
    public function __construct(private TenantContext $context, private AuditRecorder $audit) {}

    /** The user is an active member of the organization (ResolveTenant's check); a malformed identifier is not. */
    public function isMember(User $user, string $organizationId): bool
    {
        if (! Str::isUuid($organizationId)) {
            return false;
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::select("SELECT set_config('app.current_user_id', ?, true)", [(string) $user->id]);
        }

        return OrganizationMembership::query()->withoutGlobalScopes()
            ->where('organization_id', $organizationId)->where('user_id', $user->id)->where('status', 'active')->exists();
    }

    /**
     * Call inside the caller's transaction, after `isMember`.
     *
     * @param  array{name?: string, phone?: string|null}  $changes
     * @return list<string> the fields of the team's record that changed
     */
    public function handle(User $user, string $organizationId, array $changes): array
    {
        $this->context->select($organizationId);
        try {
            if (DB::getDriverName() === 'pgsql') {
                DB::select("SELECT set_config('app.current_organization_id', ?, true)", [$organizationId]);
            }
            $member = StaffMember::query()->where('user_id', $user->id)->whereNull('archived_at')->lockForUpdate()->first();
            if ($member === null) {
                return [];
            }
            if (array_key_exists('name', $changes)) {
                $member->full_name = $changes['name'];
            }
            if (($changes['phone'] ?? null) !== null) {
                $member->phone = $changes['phone'];
            }
            $fields = array_keys($member->getDirty());
            if ($fields === []) {
                return [];
            }
            $member->version++;
            $member->save();
            $this->audit->record(AuditAction::TeamMemberUpdated, new AuditableReference('staff_member', (string) $member->id), ['fields' => $fields]);

            return $fields;
        } finally {
            $this->context->clear();
        }
    }
}
