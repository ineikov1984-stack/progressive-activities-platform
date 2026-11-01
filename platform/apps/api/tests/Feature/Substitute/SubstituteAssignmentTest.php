<?php

namespace Tests\Feature\Substitute;

use App\Domain\Catalog\Actions\ApplyScheduleChange;
use App\Domain\Catalog\Actions\PreviewScheduleChange;
use App\Domain\Catalog\Actions\SubmitSubstituteRequest;
use App\Domain\Catalog\Models\InstructorProfile;
use App\Domain\Catalog\Models\Location;
use App\Domain\Catalog\Services\ScheduleConflictDetector;
use App\Domain\Catalog\Services\SubstituteCandidates;
use App\Domain\Catalog\ValueObjects\ScheduleChangeRequest;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Organizations\Models\StaffMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\VisitOfferFixture;
use Tests\TestCase;

class SubstituteAssignmentTest extends TestCase
{
    use RefreshDatabase, VisitOfferFixture;

    private function fixture(): array
    {
        $this->travelTo('2026-11-01 10:00:00');
        [$org, , $group, $session] = $this->visitFixture();
        $actor = User::factory()->create();
        $this->actingAs($actor);
        OrganizationMembership::query()->create(['organization_id' => $org->id, 'user_id' => $actor->id, 'role' => 'coordinator', 'status' => 'active']);
        $original = $this->profile('Original');
        $other = $this->profile('Assistant');
        $substitute = $this->profile('Substitute');
        $group->assignments()->attach($original->id, ['role' => 'lead']);
        $group->assignments()->attach($other->id, ['role' => 'assistant']);
        $session->instructors()->attach($original->id, ['role' => 'lead']);
        $session->instructors()->attach($other->id, ['role' => 'assistant']);

        return [$actor, $group, $session->refresh(), $original, $other, $substitute];
    }

    private function profile(string $name): InstructorProfile
    {
        $person = StaffMember::query()->create(['full_name' => $name]);
        $profile = InstructorProfile::query()->create(['public_name' => $name]);
        $profile->forceFill(['staff_member_id' => $person->id])->save();

        return $profile;
    }

    private function request(array $sessions, $original, $substitute, array $warnings = [], $request = null): ScheduleChangeRequest
    {
        return new ScheduleChangeRequest('substitute', array_map(fn ($s) => ['id' => $s->id, 'version' => $s->version], $sessions), null, false, false, $warnings,
            replacesProfileId: $original->id, substituteProfileId: $substitute?->id, requestId: $request?->id, requestVersion: $request?->version);
    }

    private function apply($actor, $request, ?string $key = null): array
    {
        return app(ApplyScheduleChange::class)->handle($actor, $request, $key ?? (string) Str::uuid());
    }

    public function test_assignment_chain_return_and_replay_preserve_original_and_role(): void
    {
        [$actor, , $session, $original, $other, $substitute] = $this->fixture();
        $key = (string) Str::uuid();
        $request = $this->request([$session], $original, $substitute);
        $result = $this->apply($actor, $request, $key);
        $this->assertSame(201, $result['status']);
        $this->assertSame(0, $result['body']['letters']['families']);
        $this->assertSame('manual', $session->refresh()->instructors_source);
        $this->assertSame(2, $session->version);
        $this->assertDatabaseHas('activity_session_instructors', ['session_id' => $session->id, 'instructor_profile_id' => $substitute->id, 'role' => 'lead', 'replaces_instructor_profile_id' => $original->id]);
        $this->assertDatabaseHas('session_changes', ['session_id' => $session->id, 'replaced_profile_id' => $original->id, 'removed_profile_id' => $original->id, 'added_profile_id' => $substitute->id]);
        $replay = $this->apply($actor, $request, $key);
        $this->assertSame(200, $replay['status']);
        $this->assertSame($result['body'], $replay['body']);
        $this->assertCount(2, $result['body']['change']['sessions'][0]['teachers']);
        $next = $this->profile('Next');
        $this->apply($actor, $this->request([$session], $original, $next));
        $this->assertDatabaseHas('session_changes', ['session_id' => $session->id, 'replaced_profile_id' => $original->id, 'removed_profile_id' => $substitute->id, 'added_profile_id' => $next->id]);
        $this->apply($actor, $this->request([$session->refresh()], $original, $original));
        $this->assertSame('assignment', $session->refresh()->instructors_source);
        $this->assertDatabaseHas('activity_session_instructors', ['session_id' => $session->id, 'instructor_profile_id' => $original->id, 'replaces_instructor_profile_id' => null]);
        $this->assertDatabaseHas('activity_session_instructors', ['session_id' => $session->id, 'instructor_profile_id' => $other->id]);
    }

    public function test_legacy_lead_pivot_on_an_assistant_uses_the_assignment_role(): void
    {
        [$actor, , $session, , $assistant, $substitute] = $this->fixture();
        $session->instructors()->updateExistingPivot($assistant->id, ['role' => 'lead']);
        $this->apply($actor, $this->request([$session], $assistant, $substitute));
        $this->assertDatabaseHas('activity_session_instructors', ['session_id' => $session->id, 'instructor_profile_id' => $substitute->id, 'role' => 'assistant', 'replaces_instructor_profile_id' => $assistant->id]);
    }

    public function test_release_assistant_then_assign_and_return_without_removing_anyone(): void
    {
        [$actor, , $session, , $assistant, $substitute] = $this->fixture();
        $this->apply($actor, $this->request([$session], $assistant, null));
        $this->assertDatabaseMissing('activity_session_instructors', ['session_id' => $session->id, 'instructor_profile_id' => $assistant->id]);
        $this->apply($actor, $this->request([$session->refresh()], $assistant, $substitute));
        $this->assertDatabaseHas('activity_session_instructors', ['session_id' => $session->id, 'instructor_profile_id' => $substitute->id, 'role' => 'assistant', 'replaces_instructor_profile_id' => $assistant->id]);
        $this->assertDatabaseHas('session_changes', ['session_id' => $session->id, 'replaced_profile_id' => $assistant->id, 'removed_profile_id' => null, 'added_profile_id' => $substitute->id]);
        $this->apply($actor, $this->request([$session->refresh()], $assistant, null));
        $this->apply($actor, $this->request([$session->refresh()], $assistant, $assistant));
        $this->assertSame('assignment', $session->refresh()->instructors_source);
    }

    public function test_optional_request_is_assigned_and_closed_with_one_version_bump(): void
    {
        [$actor, , $session, $original, , $substitute] = $this->fixture();
        $original->forceFill(['user_id' => $actor->id])->save();
        StaffMember::query()->whereKey($original->staff_member_id)->update(['user_id' => $actor->id]);
        $request = app(SubmitSubstituteRequest::class)->execute($actor, [['id' => $session->id, 'version' => $session->version]], 'personal', null, null, 'request')['request'];
        $this->apply($actor, $this->request([$session], $original, $substitute, request: $request));
        $this->assertSame(2, $request->refresh()->version);
        $this->assertNotNull($request->closed_at);
        $this->assertDatabaseHas('substitute_request_sessions', ['request_id' => $request->id, 'status' => 'assigned', 'substitute_profile_id' => $substitute->id, 'decided_by_user_id' => $actor->id]);
    }

    public function test_substitute_never_locks_or_assesses_enrollment_effects_in_apply_or_preview(): void
    {
        [$actor, , $session, $original, , $substitute] = $this->fixture();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $request = $this->request([$session], $original, $substitute);
        $preview = app(PreviewScheduleChange::class)->handle($request);
        $this->assertSame(0, $preview['totals']['families']);
        $this->apply($actor, $request);
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/from ["`]*(enrollments|enrollment_applications|enrollment_session_exceptions)["` ]/i', $query);
        }

    }

    public function test_overlap_requires_exact_key_and_preserves_previous_confirmations(): void
    {
        [$actor, $group, $session, $original, , $substitute] = $this->fixture();
        $otherGroup = $group->offering->groups()->create(['name' => 'Other', 'status' => 'published']);
        $busy = $otherGroup->sessions()->create(['starts_at' => $session->starts_at, 'ends_at' => $session->ends_at, 'status' => 'scheduled']);
        $busy->instructors()->attach($substitute->id);
        try {
            $this->apply($actor, $this->request([$session], $original, $substitute));
            $this->fail();
        } catch (HttpResponseException $e) {
            $this->assertSame(422, $e->getResponse()->getStatusCode());
            $this->assertSame('instructor', $e->getResponse()->getData(true)['warnings'][0]['type']);
        }
        $warning = ['type' => 'instructor', 'session_id' => $session->id, 'other_session_id' => $busy->id];
        $this->apply($actor, $this->request([$session], $original, $substitute, [$warning]));
        $confirmed = json_decode(DB::table('session_changes')->where('session_id', $session->id)->value('confirmed_overlaps'), true);
        $this->assertContains($warning, $confirmed);
        $this->apply($actor, $this->request([$session->refresh()], $original, $original));
        $latest = DB::table('session_changes')->where('session_id', $session->id)->orderByDesc('created_at')->orderByDesc('id')->value('confirmed_overlaps');
        $this->assertContains($warning, json_decode($latest, true));
    }

    public function test_bulk_assignment_query_count_is_constant_with_different_current_substitutes(): void
    {
        [$actor, $group, $first, $original, , $incoming] = $this->fixture();
        $sessions = [];
        for ($i = 0; $i < 33; $i++) {
            $session = $group->sessions()->create(['starts_at' => now()->addDays($i + 3), 'ends_at' => now()->addDays($i + 3)->addHour(), 'status' => 'scheduled', 'instructors_source' => 'manual']);
            $previous = $this->profile('Current '.$i);
            $session->instructors()->attach($previous->id, ['role' => 'lead', 'replaces_instructor_profile_id' => $original->id]);
            $sessions[] = $session->refresh();
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->apply($actor, $this->request(array_slice($sessions, 0, 3), $original, $incoming));
        $three = count(DB::getQueryLog());
        DB::disableQueryLog();
        // Compare three and thirty sessions with different current substitutes.
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->apply($actor, $this->request(array_slice($sessions, 3), $original, $incoming));
        $many = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame($three, $many);
    }

    public function test_unverified_teacher_is_counted_in_preview_and_apply(): void
    {
        [$actor, $group, $session, $original, , $substitute] = $this->fixture();
        $user = User::factory()->create(['email_verified_at' => null]);
        OrganizationMembership::query()->create(['organization_id' => $group->organization_id, 'user_id' => $user->id, 'role' => 'instructor', 'status' => 'active']);
        StaffMember::query()->whereKey($substitute->staff_member_id)->update(['user_id' => $user->id]);
        $substitute->forceFill(['user_id' => $user->id])->save();
        $request = $this->request([$session], $original, $substitute);
        $preview = app(PreviewScheduleChange::class)->handle($request);
        $this->assertSame(0, $preview['totals']['teachers']);
        $this->assertSame(1, $preview['totals']['without_verified_email']);
        $applied = $this->apply($actor, $request);
        $this->assertSame(1, $applied['body']['letters']['without_verified_email']);
    }

    public function test_move_confirmation_survives_substitution_and_subsequent_location_change(): void
    {
        [$actor, $group, $session, $original, $assistant, $substitute] = $this->fixture();
        $other = $group->offering->groups()->create(['name' => 'Other', 'status' => 'published']);
        $busy = $other->sessions()->create(['starts_at' => '2026-11-04 14:00:00', 'ends_at' => '2026-11-04 15:00:00', 'status' => 'scheduled']);
        $busy->instructors()->attach($original->id);
        $key = ['type' => 'instructor', 'session_id' => $session->id, 'other_session_id' => $busy->id];
        $this->apply($actor, new ScheduleChangeRequest('move', [['id' => $session->id, 'version' => 1]], null, false, false, [$key], date: '2026-11-04', from: '16:00', to: '17:00'));
        $this->assertSame([], app(ScheduleConflictDetector::class)->forGroup($group));
        $this->apply($actor, $this->request([$session->refresh()], $assistant, $substitute));
        $this->assertSame([], app(ScheduleConflictDetector::class)->forGroup($group));
        $location = Location::query()->create(['name' => 'Other room', 'type' => 'physical', 'space_usage' => 'shared']);
        $this->apply($actor, new ScheduleChangeRequest('change_location', [['id' => $session->id, 'version' => 3]], null, false, false, [], locationProvided: true, locationId: $location->id));
        $this->assertSame([], app(ScheduleConflictDetector::class)->forGroup($group));
    }

    public function test_move_preserves_manual_substitute_and_substitution_keeps_existing_reason(): void
    {
        [$actor, , $session, $original, , $substitute] = $this->fixture();
        $session->update(['change_reason' => 'Existing move reason']);
        $this->apply($actor, $this->request([$session], $original, $substitute));
        $this->assertSame('Existing move reason', $session->refresh()->change_reason);
        $this->apply($actor, new ScheduleChangeRequest('move', [['id' => $session->id, 'version' => 2]], 'Next move', false, false, [], date: '2026-11-06', from: '16:00', to: '17:00'));
        $this->assertSame('manual', $session->refresh()->instructors_source);
        $this->assertDatabaseHas('activity_session_instructors', ['session_id' => $session->id, 'instructor_profile_id' => $substitute->id, 'replaces_instructor_profile_id' => $original->id]);
        $this->assertDatabaseMissing('activity_session_instructors', ['session_id' => $session->id, 'instructor_profile_id' => $original->id]);
    }

    public function test_overlapping_selected_sessions_share_candidate_and_assignment_warning_keys(): void
    {
        [$actor, $group, $session, $original, , $substitute] = $this->fixture();
        $otherGroup = $group->offering->groups()->create(['name' => 'Second group', 'status' => 'published']);
        $other = $otherGroup->sessions()->create(['starts_at' => $session->starts_at, 'ends_at' => $session->ends_at, 'status' => 'scheduled']);
        $other->instructors()->attach($original->id);
        $rows = app(SubstituteCandidates::class)->read($actor, [$session->id, $other->id], $original->id);
        $candidate = collect($rows['candidates'])->firstWhere('profile_id', $substitute->id);
        $this->assertSame('busy', $candidate['state']);
        $this->assertCount(2, $candidate['warnings']);
        $preview = app(PreviewScheduleChange::class)->handle($this->request([$session, $other->refresh()], $original, $substitute));
        $this->assertSame($candidate['warnings'], $preview['warnings']);
        $keys = array_map(fn ($w) => array_intersect_key($w, array_flip(['type', 'session_id', 'other_session_id'])), $candidate['warnings']);
        $this->assertSame(201, $this->apply($actor, $this->request([$session, $other], $original, $substitute, $keys))['status']);
    }

    public function test_released_noop_and_releasing_only_teacher_fail_without_writes(): void
    {
        [$actor, , $session, $original, $assistant] = $this->fixture();
        $this->apply($actor, $this->request([$session], $assistant, null));
        foreach ([$assistant, $original] as $profile) {
            try {
                $this->apply($actor, $this->request([$session->refresh()], $profile, null));
                $this->fail();
            } catch (ValidationException) {
                $this->assertSame(2, $session->refresh()->version);
            }
        }
    }
}
