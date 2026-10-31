<?php

namespace Tests\Feature\Organizations;

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Catalog\Models\Group;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Actions\ReconcileStaffAccess;
use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\StaffGrant;
use App\Domain\Organizations\Models\StaffMember;
use App\Domain\Organizations\Models\StaffScopeGroup;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\TeamRightsFixture;
use Tests\TestCase;

/**
 * A new role, or none at all, brings the person's rights in line (docs/product/team-rights.md, т. 4.6): what the role
 * gives is revoked, a scope it does not allow goes back to its default, the selected groups close without the scope
 * "groups", the rights for the whole organization only go without the scope "all", the template's label goes; taking
 * finance away this way is the owner's; the archive takes everything; the same role again changes nothing.
 */
class ReconcileStaffAccessTest extends TestCase
{
    use RefreshDatabase;
    use TeamRightsFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-02 10:00:00');
    }

    public function test_a_new_role_revokes_what_it_gives_resets_the_scope_closes_the_groups_and_drops_the_label(): void
    {
        $f = $this->rightsFixture();
        [$partner, $person] = $this->schoolPartner($f);
        $this->grant($f, $person, 'contacts.view');
        $this->actingAs($f['owner']);

        $plan = $this->inTenant($f['organization'], fn (): array => DB::transaction(
            fn (): array => app(ReconcileStaffAccess::class)->handle($f['owner'], $this->locked($person), OrganizationRole::Coordinator),
        ));

        $this->assertEqualsCanonicalizing(['enrollment.enroll', 'attendance.mark', 'contacts.view'], array_column($plan['revoke'], 'ability'));
        $this->assertSame(['role_changed'], array_values(array_unique(array_column($plan['revoke'], 'reason'))));
        $this->assertTrue($plan['clear_scope']);
        $this->assertCount(1, $plan['remove_groups']);
        $this->assertTrue($plan['clear_template']);
        $this->inTenant($f['organization'], function () use ($f, $person): void {
            $fresh = StaffMember::query()->findOrFail($person->id);
            $this->assertNull($fresh->access_scope);
            $this->assertNull($fresh->access_template);
            $this->assertSame(2, $fresh->version);
            $this->assertSame(0, StaffGrant::query()->whereNull('revoked_at')->count());
            $this->assertSame(3, StaffGrant::query()->where('revoked_reason', 'role_changed')->where('revoked_by', $f['owner']->id)->count());
            $this->assertSame(0, StaffScopeGroup::query()->whereNull('removed_at')->count());
            $this->assertSame(1, StaffScopeGroup::query()->whereNotNull('removed_at')->where('removed_by', $f['owner']->id)->count());
            $this->assertSame(3, AuditEvent::query()->where('action', 'team.access_grant_revoked')->where('subject_id', $person->id)->count());
            $this->assertSame(1, AuditEvent::query()->where('action', 'team.access_group_removed')->count());
            $this->assertSame(1, AuditEvent::query()->where('action', 'team.access_scope_changed')->count());
            $this->assertSame(1, AuditEvent::query()->where('action', 'team.access_template_changed')->count());
        });

        // The same role again changes nothing.
        $again = $this->inTenant($f['organization'], fn (): array => app(ReconcileStaffAccess::class)
            ->handle($f['owner'], $this->locked($person), OrganizationRole::Coordinator));
        $this->assertSame([], $again['revoke']);
        $this->assertSame([], $again['remove_groups']);
        $this->assertFalse($again['clear_scope'] || $again['clear_template']);
        $this->inTenant($f['organization'], fn () => $this->assertSame(2, StaffMember::query()->findOrFail($person->id)->version));
    }

    public function test_a_role_that_allows_the_scope_keeps_it_with_its_groups_and_the_rights_it_does_not_give(): void
    {
        $f = $this->rightsFixture();
        [$partner, $person] = $this->schoolPartner($f);

        // A teacher may have selected groups too; marking attendance stays added, the template's label goes.
        $plan = $this->inTenant($f['organization'], fn (): array => app(ReconcileStaffAccess::class)
            ->handle($f['owner'], $this->locked($person), OrganizationRole::Instructor));

        $this->assertSame([], $plan['revoke']);
        $this->assertFalse($plan['clear_scope']);
        $this->assertSame([], $plan['remove_groups']);
        $this->assertTrue($plan['clear_template']);
        $this->inTenant($f['organization'], function () use ($person): void {
            $this->assertSame('groups', StaffMember::query()->findOrFail($person->id)->access_scope);
            $this->assertSame(2, StaffGrant::query()->whereNull('revoked_at')->count());
        });
    }

    public function test_taking_finance_away_with_a_narrower_scope_is_the_owners(): void
    {
        $f = $this->rightsFixture();
        $viewer = $this->memberOf($f['organization'], 'viewer');
        $admin = $this->memberOf($f['organization'], 'organization_admin');
        // A viewer has the whole organization by default; a teacher has their own groups.
        $person = $this->personFor($f['organization'], $viewer);
        $finance = $this->grant($f, $person, 'finance.view');
        $settings = $this->grant($f, $person, 'catalog.manage');

        $refused = false;
        try {
            $this->inTenant($f['organization'], fn () => app(ReconcileStaffAccess::class)->handle($admin, $this->locked($person), OrganizationRole::Instructor));
        } catch (AuthorizationException $exception) {
            $refused = true;
            $this->assertSame('Това би отнело достъпа до финансите — може само собственикът.', $exception->getMessage());
        }
        $this->assertTrue($refused);
        $this->inTenant($f['organization'], fn () => $this->assertSame(2, StaffGrant::query()->whereNull('revoked_at')->count()));

        $plan = $this->inTenant($f['organization'], fn (): array => app(ReconcileStaffAccess::class)
            ->handle($f['owner'], $this->locked($person), OrganizationRole::Instructor));
        $this->assertTrue($plan['loses_finance']);
        $this->inTenant($f['organization'], function () use ($finance, $settings): void {
            $this->assertSame('scope_changed', StaffGrant::query()->findOrFail($finance->id)->revoked_reason);
            $this->assertSame('scope_changed', StaffGrant::query()->findOrFail($settings->id)->revoked_reason);
        });
    }

    public function test_a_right_the_new_role_gives_is_not_a_loss_of_finance(): void
    {
        $f = $this->rightsFixture();
        $viewer = $this->memberOf($f['organization'], 'viewer');
        $admin = $this->memberOf($f['organization'], 'organization_admin');
        $person = $this->personFor($f['organization'], $viewer);
        $finance = $this->grant($f, $person, 'finance.view');

        $plan = $this->inTenant($f['organization'], fn (): array => app(ReconcileStaffAccess::class)
            ->handle($admin, $this->locked($person), OrganizationRole::Finance));

        $this->assertFalse($plan['loses_finance']);
        $this->inTenant($f['organization'], fn () => $this->assertSame('role_changed', StaffGrant::query()->findOrFail($finance->id)->revoked_reason));
    }

    public function test_the_archive_takes_every_active_right_and_group_and_the_preview_writes_nothing(): void
    {
        $f = $this->rightsFixture();
        [$partner, $person] = $this->schoolPartner($f);
        $expired = $this->grant($f, $person, 'contacts.view', '2026-11-01 22:00:00');
        $admin = $this->memberOf($f['organization'], 'organization_admin');

        $preview = $this->inTenant($f['organization'], fn (): array => app(ReconcileStaffAccess::class)->plan($this->locked($person), null));
        $this->assertCount(2, $preview['revoke']);
        $this->inTenant($f['organization'], fn () => $this->assertSame(3, StaffGrant::query()->whereNull('revoked_at')->count()));

        $plan = $this->inTenant($f['organization'], fn (): array => app(ReconcileStaffAccess::class)->handle($admin, $this->locked($person), null));

        $this->assertSame(['archived'], array_values(array_unique(array_column($plan['revoke'], 'reason'))));
        $this->inTenant($f['organization'], function () use ($person, $expired): void {
            $fresh = StaffMember::query()->findOrFail($person->id);
            $this->assertNull($fresh->access_scope);
            $this->assertNull($fresh->access_template);
            $this->assertSame(0, StaffScopeGroup::query()->whereNull('removed_at')->count());
            // An expired right keeps its history as it is.
            $this->assertNull(StaffGrant::query()->findOrFail($expired->id)->revoked_at);
            $this->assertSame(2, StaffGrant::query()->where('revoked_reason', 'archived')->count());
        });
    }

    /**
     * "Партньор от училище": a viewer with the group "Вторник" and the rights to enroll and to mark attendance.
     *
     * @param  array{organization: Organization, owner: User, tuesday: Group}  $f
     * @return array{User, StaffMember}
     */
    private function schoolPartner(array $f): array
    {
        $partner = $this->memberOf($f['organization'], 'viewer');
        $person = $this->personFor($f['organization'], $partner);
        $this->inTenant($f['organization'], function () use ($f, $person): void {
            $person->forceFill(['access_scope' => 'groups', 'access_template' => 'school_partner'])->save();
            StaffScopeGroup::query()->create([
                'staff_member_id' => $person->id, 'group_id' => $f['tuesday']->id, 'added_by' => $f['owner']->id, 'added_at' => now(),
            ]);
        });
        $this->grant($f, $person, 'enrollment.enroll', '2027-06-30 21:00:00');
        $this->grant($f, $person, 'attendance.mark');

        return [$partner, $person];
    }

    /** @param array{organization: Organization, owner: User} $f */
    private function grant(array $f, StaffMember $person, string $ability, ?string $expiresAt = null): StaffGrant
    {
        return $this->inTenant($f['organization'], fn (): StaffGrant => StaffGrant::query()->create([
            'staff_member_id' => $person->id, 'ability' => $ability, 'granted_by' => $f['owner']->id,
            'granted_at' => now()->subDays(3), 'expires_at' => $expiresAt,
        ]));
    }

    /** Inside the organization: the person as the caller holds them. */
    private function locked(StaffMember $person): StaffMember
    {
        return StaffMember::query()->whereKey($person->id)->lockForUpdate()->firstOrFail();
    }
}
