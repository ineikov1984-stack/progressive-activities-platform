<?php

namespace Tests\Feature\Substitute;

use App\Domain\Catalog\Actions\SubmitSubstituteRequest;
use App\Domain\Catalog\Actions\WithdrawSubstituteRequest;
use App\Domain\Catalog\Models\InstructorProfile;
use App\Domain\Catalog\Services\GroupTeachers;
use App\Domain\Catalog\Services\SubstituteRequests;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Organizations\Models\StaffMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\VisitOfferFixture;
use Tests\TestCase;

class SubstituteActionsTest extends TestCase
{
    use RefreshDatabase, VisitOfferFixture;

    private function fixture(): array
    {
        $this->travelTo('2026-11-01 10:00:00');
        [$org, , $group, $session] = $this->visitFixture();
        $actor = User::factory()->create();
        OrganizationMembership::query()->create(['organization_id' => $org->id, 'user_id' => $actor->id, 'role' => 'instructor', 'status' => 'active']);
        $profile = InstructorProfile::query()->create(['user_id' => $actor->id, 'public_name' => 'Иван']);
        $session->instructors()->attach($profile->id, ['role' => 'assistant']);
        $this->actingAs($actor);

        return [$actor, $profile, $group, $session->refresh()];
    }

    private function submit($actor, array $sessions, ?string $key = null, ?string $note = null): array
    {
        return app(SubmitSubstituteRequest::class)->execute($actor, array_map(fn ($s) => ['id' => $s->id, 'version' => $s->version], $sessions), 'personal', $note, null, $key ?? (string) Str::uuid());
    }

    private function second($group, $profile)
    {
        $s = $group->sessions()->create(['starts_at' => '2026-11-04 14:00:00', 'ends_at' => '2026-11-04 15:00:00', 'status' => 'scheduled']);
        $s->instructors()->attach($profile->id);

        return $s->refresh();
    }

    public function test_normalized_replay_precedes_current_version_and_time_and_has_no_side_effects(): void
    {
        [$actor,$profile,$group,$s] = $this->fixture();
        $second = $this->second($group, $profile);
        $r = $this->submit($actor, [$s, $second], 'key arbitrary', '  private text  ');
        $this->assertFalse($r['replayed']);
        $this->assertSame('private text', $r['request']->note);
        $this->assertSame(1, $s->refresh()->version);
        $s->increment('version');
        $this->travelTo('2026-11-05 12:00:00');
        $again = app(SubmitSubstituteRequest::class)->execute($actor, [['id' => strtoupper($second->id), 'version' => 1], ['id' => strtoupper($s->id), 'version' => 1]], 'personal', 'private text', null, 'key arbitrary');
        $this->assertTrue($again['replayed']);
        $this->assertSame($r['request']->id, $again['request']->id);
        $this->assertDatabaseCount('substitute_requests', 1);
        $this->assertDatabaseCount('enrollment_events', 1);
        $this->assertStringNotContainsString('private text', DB::table('audit_events')->value('metadata'));
    }

    public function test_duplicate_or_changed_idempotency_body_rejected(): void
    {
        [$actor,,,$s] = $this->fixture();
        $this->submit($actor, [$s], 'key');
        foreach (['key', 'another'] as $key) {
            try {
                $this->submit($actor, [$s], $key, 'changed');
                $this->fail();
            } catch (ValidationException) {
                $this->assertDatabaseCount('substitute_requests', 1);
            }
        }
    }

    public function test_stale_session_conflicts_and_non_owned_session_hidden(): void
    {
        [$actor,$p,,$s] = $this->fixture();
        $s->increment('version');
        try {
            app(SubmitSubstituteRequest::class)->execute($actor, [['id' => $s->id, 'version' => 1]], 'other', null, null, 'key');
            $this->fail();
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $s->instructors()->detach($p->id);
        try {
            $this->submit($actor, [$s->refresh()]);
            $this->fail();
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }

    public function test_read_expiry_does_not_write_and_withdraw_normalizes_started_items(): void
    {
        [$actor,$p,$g,$s] = $this->fixture();
        $second = $this->second($g, $p);
        $r = $this->submit($actor, [$s, $second])['request'];
        $this->travelTo('2026-11-03 14:30:00');
        $read = app(SubstituteRequests::class)->mine($actor);
        $this->assertSame('expired', $read['requests'][0]['sessions'][0]['state']);
        $this->assertDatabaseHas('substitute_request_sessions', ['session_id' => $s->id, 'status' => 'open']);
        $r = app(WithdrawSubstituteRequest::class)->execute($actor, $r->id, 1);
        $this->assertSame(2, $r->version);
        $this->assertNotNull($r->closed_at);
        $this->assertDatabaseHas('substitute_request_sessions', ['session_id' => $s->id, 'status' => 'expired', 'closed_reason' => 'started']);
        $this->assertDatabaseHas('substitute_request_sessions', ['session_id' => $second->id, 'status' => 'withdrawn']);
        $this->assertDatabaseCount('enrollment_events', 1);
    }

    public function test_assignment_changes_close_only_own_group_and_two_group_request_closes_last(): void
    {
        [$actor,$p,$g,$s] = $this->fixture();
        $other = $g->offering->groups()->create(['name' => 'Other', 'status' => 'published', 'capacity_max' => 10]);
        $second = $this->second($other, $p);
        $r = $this->submit($actor, [$s, $second])['request'];
        DB::transaction(fn () => app(GroupTeachers::class)->apply($g));
        $this->assertDatabaseHas('substitute_request_sessions', ['session_id' => $s->id, 'status' => 'expired', 'closed_reason' => 'not_teaching']);
        $this->assertDatabaseHas('substitute_request_sessions', ['session_id' => $second->id, 'status' => 'open']);
        $this->assertNull($r->refresh()->closed_at);
        $this->assertSame(2, $r->version);
        DB::transaction(fn () => app(GroupTeachers::class)->apply($other));
        $this->assertNotNull($r->refresh()->closed_at);
        $this->assertSame(3, $r->version);
    }

    public function test_operator_denied_before_resource_lookup(): void
    {
        [$actor,,,$s] = $this->fixture();
        OrganizationMembership::query()->where('user_id', $actor->id)->update(['role' => 'platform_admin']);
        try {
            $this->submit($actor, [$s]);
            $this->fail();
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_mine_query_count_is_constant_and_never_includes_another_requester(): void
    {
        [$actor,$p,$g,$first] = $this->fixture();
        $this->submit($actor, [$first]);
        DB::enableQueryLog();
        DB::flushQueryLog();
        app(SubstituteRequests::class)->mine($actor);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();
        for ($i = 0; $i < 29; $i++) {
            $session = $this->second($g, $p);
            $this->submit($actor, [$session]);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $many = app(SubstituteRequests::class)->mine($actor);
        $this->assertSame($count, count(DB::getQueryLog()));
        DB::disableQueryLog();
        $this->assertCount(30, $many['requests']);
        $other = User::factory()->create();
        OrganizationMembership::query()->create(['organization_id' => $g->organization_id, 'user_id' => $other->id, 'role' => 'instructor', 'status' => 'active']);
        $this->assertSame([], app(SubstituteRequests::class)->mine($other)['requests']);
    }

    public function test_standing_in_uses_explicit_replaces_and_separate_children_window(): void
    {
        [$actor,$p,$g,$session] = $this->fixture();
        $replaced = InstructorProfile::query()->create(['public_name' => 'Absent']);
        DB::table('activity_session_instructors')->where('session_id', $session->id)->where('instructor_profile_id', $p->id)->update(['replaces_instructor_profile_id' => $replaced->id]);
        $future = app(SubstituteRequests::class)->mine($actor)['standing_in'];
        $this->assertCount(1, $future);
        $this->assertFalse($future[0]['children_now']);
        $this->assertSame('Absent', $future[0]['replaces']['name']);
        $this->travelTo('2026-11-04 12:00:00');
        $this->assertTrue(app(SubstituteRequests::class)->mine($actor)['standing_in'][0]['children_now']);
        $this->travelTo('2026-11-05 12:00:00');
        $this->assertSame([], app(SubstituteRequests::class)->mine($actor)['standing_in']);
    }

    public function test_options_include_only_own_sessions_and_active_linked_colleagues(): void
    {
        [$actor,$p,$g,$session] = $this->fixture();
        $staff = StaffMember::query()->create(['full_name' => 'Colleague']);
        $colleague = InstructorProfile::query()->create(['public_name' => 'Colleague']);
        $colleague->forceFill(['staff_member_id' => $staff->id])->save();
        $old = InstructorProfile::query()->create(['public_name' => 'Old unlinked']);
        $g->assignments()->attach($colleague->id, ['starts_on' => '2026-12-01']);
        $g->assignments()->attach($old->id);
        $options = app(SubstituteRequests::class)->options($actor, '2026-11-03', '2026-11-03');
        $this->assertCount(1, $options['sessions']);
        $this->assertSame($session->id, $options['sessions'][0]['id']);
        $this->assertSame([$colleague->id], array_column($options['colleagues'], 'profile_id'));
        $staff->update(['archived_at' => now()]);
        $this->assertSame([], app(SubstituteRequests::class)->options($actor, '2026-11-03', '2026-11-03')['colleagues']);
    }

    public function test_withdraw_all_expired_persists_expiry_then_returns_resolved_validation(): void
    {
        [$actor,,,$session] = $this->fixture();
        $request = $this->submit($actor, [$session])['request'];
        $this->travelTo('2026-11-04 12:00:00');
        try {
            app(WithdrawSubstituteRequest::class)->execute($actor, $request->id, 1);
            $this->fail();
        } catch (HttpResponseException $e) {
            $this->assertSame(422, $e->getResponse()->getStatusCode());
            $this->assertNotNull($request->refresh()->closed_at);
            $this->assertSame(2, $request->version);
        }
        $this->assertDatabaseHas('substitute_request_sessions', ['session_id' => $session->id, 'status' => 'expired', 'closed_reason' => 'started']);
    }

    public function test_invalid_selection_note_and_suggestion_leave_no_partial_records(): void
    {
        [$actor,,,$session] = $this->fixture();
        $one = [['id' => $session->id, 'version' => 1]];
        $cases = [
            [[], null, null],
            [array_fill(0, 51, $one[0]), null, null],
            [$one, str_repeat('а', 301), null],
            [$one, null, (string) Str::uuid()],
        ];
        foreach ($cases as [$selected, $note, $suggested]) {
            try {
                app(SubmitSubstituteRequest::class)->execute($actor, $selected, 'personal', $note, $suggested, (string) Str::uuid());
                $this->fail('Invalid input must be rejected.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('substitute_requests', 0);
                $this->assertDatabaseCount('substitute_request_sessions', 0);
                $this->assertDatabaseCount('enrollment_events', 0);
            }
        }
    }

    public function test_started_cancelled_archived_and_draft_sessions_are_rejected(): void
    {
        [$actor,,$group,$session] = $this->fixture();
        $session->update(['starts_at' => now()->subHour(), 'ends_at' => now()->addHour()]);
        foreach (['started', 'cancelled', 'archived', 'draft'] as $state) {
            if ($state === 'cancelled') {
                $session->update(['starts_at' => '2026-11-03 14:00:00', 'ends_at' => '2026-11-03 15:00:00', 'status' => 'cancelled']);
            } elseif ($state === 'archived' || $state === 'draft') {
                $session->update(['status' => 'scheduled']);
                $group->update(['status' => $state]);
            }
            try {
                $this->submit($actor, [$session->refresh()]);
                $this->fail('Unavailable session must be rejected.');
            } catch (ValidationException) {
                $this->assertNotSame('draft', $state);
            } catch (HttpException $e) {
                $this->assertSame('draft', $state);
                $this->assertSame(404, $e->getStatusCode());
            }
            $this->assertDatabaseCount('substitute_requests', 0);
            $this->assertDatabaseCount('enrollment_events', 0);
        }
    }

    public function test_rollback_removes_request_items_letter_and_audit_together(): void
    {
        [$actor,,,$session] = $this->fixture();
        try {
            DB::transaction(function () use ($actor, $session): void {
                $this->submit($actor, [$session]);
                $this->assertDatabaseCount('enrollment_events', 1);
                throw new \RuntimeException('Simulated failure');
            });
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulated failure', $e->getMessage());
        }
        foreach (['substitute_requests', 'substitute_request_sessions', 'enrollment_events', 'audit_events'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }
}
