<?php

namespace Tests\Feature\Organizations;

use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Organizations\Models\StaffGrant;
use App\Domain\Organizations\Services\StaffRights;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\TeamRightsFixture;
use Tests\TestCase;

class StaffRightsHoldersTest extends TestCase
{
    use RefreshDatabase;
    use TeamRightsFixture;

    public function test_holders_match_individual_rights_for_every_role_scope_and_grant_state(): void
    {
        $this->travelTo('2026-11-02 10:00:00');
        $f = $this->rightsFixture();
        $users = [$f['owner']];
        foreach (OrganizationRole::cases() as $role) {
            foreach (['missing', 'archived', 'all', 'own', 'groups', 'expired', 'revoked', 'suspended'] as $state) {
                $user = $this->memberOf($f['organization'], $role->value, $state === 'suspended' ? 'suspended' : 'active');
                $users[] = $user;
                if ($state === 'missing') {
                    continue;
                }
                $person = $this->personFor($f['organization'], $user);
                $this->inTenant($f['organization'], function () use ($person, $state, $f): void {
                    $person->forceFill(['access_scope' => in_array($state, ['own', 'groups'], true) ? $state : 'all', 'archived_at' => $state === 'archived' ? now() : null])->save();
                    foreach (['schedule.manage', 'team.assign', 'catalog.publish', 'enrollment.enroll'] as $ability) {
                        StaffGrant::query()->create([
                            'staff_member_id' => $person->id, 'ability' => $ability,
                            'granted_by' => $f['owner']->id, 'granted_at' => now()->subDay(),
                            'expires_at' => $state === 'expired' ? now() : null,
                            'revoked_at' => $state === 'revoked' ? now() : null,
                            'revoked_by' => $state === 'revoked' ? $f['owner']->id : null,
                            'revoked_reason' => $state === 'revoked' ? 'removed' : null,
                        ]);
                    }
                });
            }
        }
        $foreign = $this->rightsFixture();
        $foreignUser = $this->memberOf($foreign['organization'], 'organization_owner');
        OrganizationMembership::query()->withoutGlobalScopes()->create(['organization_id' => $f['organization']->id, 'user_id' => $foreignUser->id, 'role' => 'viewer', 'status' => 'active']);
        $users[] = $foreignUser;
        $foreignPerson = $this->personFor($foreign['organization'], $foreignUser);
        $this->inTenant($foreign['organization'], function () use ($foreignPerson, $foreign): void {
            foreach (['schedule.manage', 'team.assign'] as $ability) {
                StaffGrant::query()->create([
                    'staff_member_id' => $foreignPerson->id, 'ability' => $ability,
                    'granted_by' => $foreign['owner']->id, 'granted_at' => now(),
                ]);
            }
        });
        $this->inTenant($f['organization'], function () use ($users): void {
            $rights = app(StaffRights::class);
            foreach ([['schedule.manage', 'team.assign'], ['catalog.manage'], ['enrollment.manage'], ['enrollment.view'], []] as $abilities) {
                $expected = [];
                foreach ($users as $user) {
                    $access = $rights->for($user);
                    if ($access->role?->isTeam() && array_all($abilities, $access->allowsAcrossOrganization(...))) {
                        $expected[] = (string) $user->id;
                    }
                }
                sort($expected, SORT_STRING);
                $this->assertSame($expected, $rights->holdersEverywhere($abilities));
            }
        });
        $this->assertSame([], app(StaffRights::class)->holdersEverywhere(['schedule.manage']));
    }

    public function test_listing_uses_two_queries_for_three_and_thirty_members_without_filtering_unverified_emails(): void
    {
        $f = $this->rightsFixture();
        foreach ([3, 30] as $count) {
            for ($i = $count === 3 ? 1 : 3; $i < $count; $i++) {
                $user = $this->memberOf($f['organization'], 'coordinator');
                $user->forceFill(['email_verified_at' => null])->save();
                $this->personFor($f['organization'], $user);
            }
            $this->inTenant($f['organization'], function () use ($count): void {
                DB::enableQueryLog();
                DB::flushQueryLog();
                try {
                    $holders = app(StaffRights::class)->holdersEverywhere(['schedule.manage', 'team.assign']);
                    $queries = DB::getQueryLog();
                } finally {
                    DB::disableQueryLog();
                }
                $this->assertCount($count, $holders);
                $this->assertCount(2, $queries);
            });
        }
    }
}
