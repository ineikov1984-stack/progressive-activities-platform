<?php

namespace Tests\Feature\Substitute;

use App\Domain\Catalog\Actions\DeclineSubstituteRequest;
use App\Domain\Catalog\Actions\SubmitSubstituteRequest;
use App\Domain\Catalog\Models\InstructorProfile;
use App\Domain\Catalog\Services\OfficeSubstituteRequests;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Organizations\Models\StaffGrant;
use App\Domain\Organizations\Models\StaffMember;
use App\Domain\Organizations\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\VisitOfferFixture;
use Tests\TestCase;

class OfficeSubstituteRequestsTest extends TestCase
{
    use RefreshDatabase, VisitOfferFixture;

    private function fixture(): array
    {
        $this->travelTo('2026-11-01 10:00:00');
        [$org, , $group, $session] = $this->visitFixture();
        $teacher = $this->member('instructor');
        $profile = InstructorProfile::query()->create(['user_id' => $teacher->id, 'public_name' => 'Teacher']);
        $session->instructors()->attach($profile->id);
        $office = $this->member('coordinator');
        $this->actingAs($office);

        return [$office, $teacher, $profile, $group, $session->refresh()];
    }

    private function member(string $role): User
    {
        $user = User::factory()->create();
        OrganizationMembership::query()->create(['organization_id' => app(TenantContext::class)->organizationId(), 'user_id' => $user->id, 'role' => $role, 'status' => 'active']);

        return $user;
    }

    private function submit($teacher, array $sessions)
    {
        return app(SubmitSubstituteRequest::class)->execute($teacher, array_map(fn ($s) => ['id' => $s->id, 'version' => $s->version], $sessions), 'personal', 'Private note', null, (string) Str::uuid())['request'];
    }

    private function second($group, $profile)
    {
        $session = $group->sessions()->create(['starts_at' => '2026-11-04 14:00:00', 'ends_at' => '2026-11-04 15:00:00', 'status' => 'scheduled']);
        $session->instructors()->attach($profile->id);

        return $session->refresh();
    }

    private function assertStatus(int $expected, callable $work): void
    {
        try {
            $work();
            $this->fail('Expected access or conflict error.');
        } catch (HttpException $e) {
            $this->assertSame($expected, $e->getStatusCode());
        }
    }

    public function test_rights_and_platform_denial_precede_resource_lookup(): void
    {
        [$office] = $this->fixture();
        foreach (['organization_owner', 'organization_admin', 'coordinator'] as $role) {
            $this->assertSame([], app(OfficeSubstituteRequests::class)->listing($this->member($role))['requests']);
        }
        foreach (['instructor', 'finance', 'viewer', 'platform_admin'] as $role) {
            $actor = $this->member($role);
            $this->assertStatus(403, fn () => app(OfficeSubstituteRequests::class)->listing($actor));
            $this->assertStatus(403, fn () => app(OfficeSubstituteRequests::class)->detail($actor, (string) Str::uuid()));
            $this->assertStatus(403, fn () => app(DeclineSubstituteRequest::class)->execute($actor, (string) Str::uuid(), 1));
        }
        $actor = $this->member('instructor');
        $person = StaffMember::query()->create(['full_name' => 'Granted', 'user_id' => $actor->id]);
        $person->forceFill(['access_scope' => 'all'])->save();
        foreach (['schedule.manage', 'team.assign'] as $ability) {
            StaffGrant::query()->create(['staff_member_id' => $person->id, 'ability' => $ability, 'granted_by' => $office->id, 'granted_at' => now()]);
            if ($ability === 'schedule.manage') {
                $this->assertStatus(403, fn () => app(OfficeSubstituteRequests::class)->listing($actor));
            }
        }
        $this->assertSame([], app(OfficeSubstituteRequests::class)->listing($actor)['requests']);
        $person->forceFill(['access_scope' => 'groups'])->save();
        $this->assertStatus(403, fn () => app(OfficeSubstituteRequests::class)->detail($actor, (string) Str::uuid()));
    }

    public function test_foreign_request_hidden_in_list_detail_and_decline(): void
    {
        [$office, $teacher, , , $session] = $this->fixture();
        $request = $this->submit($teacher, [$session]);
        $this->visitFixture();
        $foreignOffice = $this->member('coordinator');
        $this->assertSame([], app(OfficeSubstituteRequests::class)->listing($foreignOffice)['requests']);
        $this->assertStatus(404, fn () => app(OfficeSubstituteRequests::class)->detail($foreignOffice, $request->id));
        $this->assertStatus(404, fn () => app(DeclineSubstituteRequest::class)->execute($foreignOffice, $request->id, 1));
    }

    public function test_list_projects_expiry_without_writes_and_orders_by_first_open_session(): void
    {
        [$office, $teacher, $profile, $group, $session] = $this->fixture();
        $future = $this->second($group, $profile);
        $later = $this->submit($teacher, [$future]);
        $first = $this->submit($teacher, [$session]);
        $reader = app(OfficeSubstituteRequests::class);
        $this->assertSame([$first->id, $later->id], array_column($reader->listing($office)['requests'], 'id'));
        $this->travelTo('2026-11-03 14:30:00');
        $rows = $reader->listing($office);
        $this->assertSame([$later->id], array_column($rows['requests'], 'id'));
        $this->assertSame(1, $rows['requests'][0]['sessions'][0]['version']);
        $this->assertSame('Teacher', $rows['requests'][0]['requester']['name']);
        $this->assertSame(now()->toIso8601String(), $rows['now']);
        $this->assertDatabaseHas('substitute_request_sessions', ['request_id' => $first->id, 'status' => 'open']);
        $this->assertSame(1, $first->refresh()->version);
    }

    public function test_list_query_count_is_constant_for_three_and_thirty_requests(): void
    {
        [$office, $teacher, $profile, $group, $session] = $this->fixture();
        $this->submit($teacher, [$session]);
        for ($i = 1; $i < 30; $i++) {
            $this->submit($teacher, [$this->second($group, $profile)]);
            if ($i === 2) {
                DB::enableQueryLog();
                DB::flushQueryLog();
                $this->assertCount(3, app(OfficeSubstituteRequests::class)->listing($office)['requests']);
                $count = count(DB::getQueryLog());
                DB::disableQueryLog();
            }
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->assertCount(30, app(OfficeSubstituteRequests::class)->listing($office)['requests']);
        $this->assertSame($count, count(DB::getQueryLog()));
        DB::disableQueryLog();
    }

    public function test_decline_normalizes_started_items_records_identifiers_and_detail_keeps_history(): void
    {
        [$office, $teacher, $profile, $group, $session] = $this->fixture();
        $future = $this->second($group, $profile);
        $request = $this->submit($teacher, [$session, $future]);
        $this->travelTo('2026-11-03 14:30:00');
        $result = app(DeclineSubstituteRequest::class)->execute($office, $request->id, 1);
        $this->assertSame(2, $result->version);
        $this->assertNotNull($result->closed_at);
        $this->assertSame(1, $session->refresh()->version);
        $this->assertDatabaseHas('substitute_request_sessions', ['session_id' => $session->id, 'status' => 'expired', 'closed_reason' => 'started']);
        $this->assertDatabaseHas('substitute_request_sessions', ['session_id' => $future->id, 'status' => 'declined', 'decided_by_user_id' => $office->id]);
        $detail = app(OfficeSubstituteRequests::class)->detail($office, $request->id);
        $this->assertSame(['expired', 'declined'], array_column($detail['sessions'], 'state'));
        $this->assertSame($office->id, $detail['sessions'][1]['decided_by']['id']);
        $this->assertTrue($detail['sessions'][1]['now']['teaching']);
        $this->assertSame(1, $detail['sessions'][1]['version']);
        $this->assertSame(now()->toIso8601String(), $detail['now']);
        $payload = json_decode(DB::table('enrollment_events')->where('type', 'substitute.declined')->value('payload'), true);
        $this->assertSame(['substitute_request_id' => $request->id, 'requester_user_id' => $teacher->id, 'user_id' => $teacher->id, 'session_ids' => [$future->id]], $payload);
        $metadata = json_decode(DB::table('audit_events')->where('action', 'schedule.substitute_request_declined')->value('metadata'), true);
        $this->assertSame(['sessions_declined' => 1, 'sessions_expired' => 1], $metadata);
        $this->assertSame([], app(OfficeSubstituteRequests::class)->listing($office)['requests']);
    }

    public function test_stale_decline_rolls_back_and_no_open_persists_expiry_before_validation(): void
    {
        [$office, $teacher, , , $session] = $this->fixture();
        $request = $this->submit($teacher, [$session]);
        $this->assertStatus(409, fn () => app(DeclineSubstituteRequest::class)->execute($office, $request->id, 2));
        $this->assertDatabaseHas('substitute_request_sessions', ['session_id' => $session->id, 'status' => 'open']);
        $this->travelTo('2026-11-04 12:00:00');
        $this->assertStatus(409, fn () => app(DeclineSubstituteRequest::class)->execute($office, $request->id, 2));
        $this->assertDatabaseHas('substitute_request_sessions', ['session_id' => $session->id, 'status' => 'open']);
        $this->assertSame(1, $request->refresh()->version);
        try {
            app(DeclineSubstituteRequest::class)->execute($office, $request->id, 1);
            $this->fail();
        } catch (HttpResponseException $e) {
            $this->assertSame(422, $e->getResponse()->getStatusCode());
            $body = $e->getResponse()->getData(true);
            $this->assertSame('expired', $body['request']['sessions'][0]['state']);
            $this->assertSame(2, $request->refresh()->version);
            $this->assertNotNull($request->closed_at);
        }
        $this->assertDatabaseMissing('enrollment_events', ['type' => 'substitute.declined']);
    }

    public function test_decline_rolls_back_items_request_event_and_audit_together(): void
    {
        [$office, $teacher, , , $session] = $this->fixture();
        $request = $this->submit($teacher, [$session]);
        try {
            DB::transaction(function () use ($office, $request): void {
                app(DeclineSubstituteRequest::class)->execute($office, $request->id, 1);
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException $e) {
            $this->assertSame('rollback', $e->getMessage());
        }
        $this->assertSame(1, $request->refresh()->version);
        $this->assertNull($request->closed_at);
        $this->assertDatabaseHas('substitute_request_sessions', ['request_id' => $request->id, 'status' => 'open']);
        $this->assertDatabaseMissing('enrollment_events', ['type' => 'substitute.declined']);
        $this->assertDatabaseMissing('audit_events', ['action' => 'schedule.substitute_request_declined']);
    }
}
