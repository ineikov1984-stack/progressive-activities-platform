<?php

namespace Tests\Feature\Substitute;

use App\Domain\Catalog\Models\Activity;
use App\Domain\Catalog\Models\InstructorProfile;
use App\Domain\Catalog\Models\ScheduleChange;
use App\Domain\Catalog\Services\SubstituteCandidates;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\StaffMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\TeamRightsFixture;
use Tests\TestCase;

class SubstituteCandidatesTest extends TestCase
{
    use RefreshDatabase, TeamRightsFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 1)->startOfDay());
    }

    public function test_candidates_are_linked_staff_without_contacts_and_exclude_people_already_teaching(): void
    {
        $f = $this->rightsFixture();
        $this->inTenant($f['organization'], function () use ($f): void {
            $original = $this->candidate('Иван');
            $free = $this->candidate('Мария');
            InstructorProfile::query()->create(['public_name' => 'Стар профил']);
            $archived = $this->candidate('Архив');
            StaffMember::query()->where('id', $archived->staff_member_id)->update(['archived_at' => now()]);
            $f['session']->instructors()->attach($original->id, ['organization_id' => $f['organization']->id, 'role' => 'lead']);
            $result = app(SubstituteCandidates::class)->read($f['owner'], [$f['session']->id]);
            $this->assertSame($original->id, $result['replaces']['profile_id']);
            $this->assertSame([$free->id], array_column($result['candidates'], 'profile_id'));
            $this->assertSame('free', $result['candidates'][0]['state']);
            $this->assertFalse($result['candidates'][0]['has_access']);
            $this->assertFalse($result['can_go_without']);
            $this->assertSame($original->id, $result['sessions'][0]['steps_down']['profile_id']);
            $this->assertArrayNotHasKey('email', $result['candidates'][0]);
        });
    }

    public function test_rights_are_checked_before_invalid_sessions(): void
    {
        $f = $this->rightsFixture();
        foreach (['instructor', 'platform_admin', 'finance'] as $role) {
            $user = $this->memberOf($f['organization'], $role);
            $this->inTenant($f['organization'], function () use ($user): void {
                try {
                    app(SubstituteCandidates::class)->read($user, []);
                    $this->fail('Expected forbidden.');
                } catch (HttpException $e) {
                    $this->assertSame(403, $e->getStatusCode());
                    $this->assertSame('Заместник назначават собственикът, администраторът и координаторът.', $e->getMessage());
                }
            });
        }
    }

    public function test_validation_and_foreign_resources_do_not_leak(): void
    {
        $f = $this->rightsFixture();
        $foreign = $this->rightsFixture();
        $this->inTenant($f['organization'], function () use ($f, $foreign): void {
            foreach ([[], array_fill(0, 51, (string) Str::uuid()), ['bad']] as $ids) {
                try {
                    app(SubstituteCandidates::class)->read($f['owner'], $ids);
                    $this->fail('Expected validation error.');
                } catch (ValidationException $e) {
                    $this->assertSame('Изберете от 1 до 50 занимания.', $e->errors()['sessions'][0]);
                }
            }
            try {
                app(SubstituteCandidates::class)->read($f['owner'], [$foreign['session']->id]);
                $this->fail('Expected not found.');
            } catch (HttpException $e) {
                $this->assertSame(404, $e->getStatusCode());
            }
        });
    }

    public function test_busy_uses_all_profiles_of_a_person_and_ignores_touching_and_draft_sessions(): void
    {
        $f = $this->rightsFixture();
        $this->inTenant($f['organization'], function () use ($f): void {
            $original = $this->candidate('Иван');
            $candidate = $this->candidate('Мария');
            $alias = InstructorProfile::query()->create(['public_name' => 'Втори профил']);
            $user = User::factory()->create();
            StaffMember::query()->where('id', $candidate->staff_member_id)->update(['user_id' => $user->id]);
            $alias->update(['user_id' => $user->id]);
            $f['session']->instructors()->attach($original->id, ['organization_id' => $f['organization']->id]);
            $other = $f['studio']->sessions()->create(['starts_at' => '2026-11-03 15:30:00', 'ends_at' => '2026-11-03 17:00:00', 'status' => 'scheduled']);
            $other->instructors()->attach($alias->id, ['organization_id' => $f['organization']->id]);
            $result = app(SubstituteCandidates::class)->read($f['owner'], [$f['session']->id]);
            $row = collect($result['candidates'])->firstWhere('profile_id', $candidate->id);
            $this->assertSame('busy', $row['state']);
            $this->assertSame(1, $row['busy_in']);
            $this->assertSame($other->id, $row['warnings'][0]['other_session_id']);
            $this->assertSame('instructor', $row['warnings'][0]['type']);
            $f['studio']->update(['status' => 'draft']);
            $result = app(SubstituteCandidates::class)->read($f['owner'], [$f['session']->id]);
            $this->assertSame('free', collect($result['candidates'])->firstWhere('profile_id', $candidate->id)['state']);
        });
    }

    public function test_replaced_original_returns_first_and_multiple_originals_require_a_choice(): void
    {
        $f = $this->rightsFixture();
        $this->inTenant($f['organization'], function () use ($f): void {
            $original = $this->candidate('Иван');
            $substitute = $this->candidate('Мария');
            $helper = $this->candidate('Петър');
            $f['session']->instructors()->attach($substitute->id, ['organization_id' => $f['organization']->id, 'replaces_instructor_profile_id' => $original->id]);
            $f['session']->instructors()->attach($helper->id, ['organization_id' => $f['organization']->id, 'role' => 'assistant']);
            $choice = app(SubstituteCandidates::class)->read($f['owner'], [$f['session']->id]);
            $this->assertCount(2, $choice['choose_replaced']);
            $this->assertSame([], $choice['candidates']);
            $result = app(SubstituteCandidates::class)->read($f['owner'], [$f['session']->id], $original->id);
            $this->assertSame($original->id, $result['candidates'][0]['profile_id']);
            $this->assertTrue($result['candidates'][0]['return']);
            $this->assertSame($substitute->id, $result['sessions'][0]['steps_down']['profile_id']);
            $this->assertTrue($result['can_go_without']);
        });
    }

    public function test_away_uses_current_requests_and_replacements_and_excludes_selected_sessions(): void
    {
        $f = $this->rightsFixture();
        $this->inTenant($f['organization'], function () use ($f): void {
            $original = $this->candidate('Иван');
            $candidate = $this->candidate('Мария');
            $substitute = $this->candidate('Петър');
            $f['session']->instructors()->attach($original->id, ['organization_id' => $f['organization']->id]);
            $other = $f['studio']->sessions()->create(['starts_at' => '2026-11-03 18:00:00', 'ends_at' => '2026-11-03 19:00:00', 'status' => 'scheduled']);
            $other->instructors()->attach($substitute->id, ['organization_id' => $f['organization']->id, 'replaces_instructor_profile_id' => $candidate->id]);
            $read = fn () => collect(app(SubstituteCandidates::class)->read($f['owner'], [$f['session']->id])['candidates'])->firstWhere('profile_id', $candidate->id);
            $this->assertSame('away', $read()['state']);
            $this->assertSame('substitute_away', $read()['warnings'][0]['type']);
            $this->travelTo(now()->setDate(2026, 11, 3)->setTime(14, 0));
            $other->update(['starts_at' => '2026-11-03 12:00:00', 'ends_at' => '2026-11-03 13:00:00']);
            $this->assertSame('away', $read()['state']);
            $this->travelTo(now()->setDate(2026, 11, 1)->startOfDay());
            $other->instructors()->detach();
            $this->assertSame('free', $read()['state']);
            $requestId = (string) Str::uuid();
            DB::table('substitute_requests')->insert(['id' => $requestId, 'organization_id' => $f['organization']->id, 'requester_user_id' => $f['owner']->id, 'requester_profile_id' => $candidate->id, 'reason' => 'personal', 'version' => 1, 'idempotency_key' => $requestId, 'request_hash' => str_repeat('a', 64)]);
            DB::table('substitute_request_sessions')->insert(['id' => (string) Str::uuid(), 'organization_id' => $f['organization']->id, 'request_id' => $requestId, 'requester_profile_id' => $candidate->id, 'session_id' => $other->id, 'status' => 'open']);
            $this->assertSame('away', $read()['state']);
            DB::table('substitute_request_sessions')->where('request_id', $requestId)->update(['status' => 'withdrawn']);
            $this->assertSame('free', $read()['state']);
        });
    }

    public function test_query_count_is_bounded_for_people_and_fifty_intervals_across_four_months(): void
    {
        $f = $this->rightsFixture();
        $this->inTenant($f['organization'], function () use ($f): void {
            $original = $this->candidate('Иван');
            $f['session']->instructors()->attach($original->id, ['organization_id' => $f['organization']->id]);
            for ($i = 0; $i < 3; $i++) {
                $this->candidate('Колега '.$i);
            }
            $measure = function (array $ids) use ($f): array {
                DB::enableQueryLog();
                DB::flushQueryLog();
                app(SubstituteCandidates::class)->read($f['owner'], $ids);
                $queries = DB::getQueryLog();
                DB::disableQueryLog();

                return $queries;
            };
            $small = $measure([$f['session']->id]);
            for ($i = 3; $i < 30; $i++) {
                $this->candidate('Колега '.$i);
            }
            $this->assertCount(count($small), $measure([$f['session']->id]));
            $ids = [$f['session']->id];
            for ($i = 1; $i < 50; $i++) {
                $start = now()->setDate(2026, 11, 3)->setTime(15, 0)->addDays($i * 2);
                $session = $f['tuesday']->sessions()->create(['starts_at' => $start, 'ends_at' => $start->copy()->addHour(), 'status' => 'scheduled']);
                $session->instructors()->attach($original->id, ['organization_id' => $f['organization']->id]);
                $ids[] = $session->id;
            }
            $large = $measure($ids);
            $this->assertCount(count($small), $large);
            $interval = array_values(array_filter($large, fn ($q) => str_contains($q['query'], 'x.ends_at > s.starts_at')));
            $this->assertCount(1, $interval);
            $this->assertStringContainsString('VALUES', $interval[0]['query']);
            $this->assertStringNotContainsString('BETWEEN', $interval[0]['query']);
        });
    }

    public function test_invalid_session_states_have_precise_errors(): void
    {
        $f = $this->rightsFixture();
        $this->inTenant($f['organization'], function () use ($f): void {
            foreach ([['archived', 'scheduled', '2026-11-03', 'Групата е в архива.'], ['published', 'cancelled', '2026-11-03', 'Заниманието вече е отменено.'], ['published', 'scheduled', '2026-10-31', 'Заниманието вече е започнало.']] as [$groupStatus, $status, $day, $message]) {
                $f['tuesday']->update(['status' => $groupStatus]);
                $f['session']->update(['status' => $status, 'starts_at' => $day.' 15:00:00']);
                try {
                    app(SubstituteCandidates::class)->read($f['owner'], [$f['session']->id]);
                    $this->fail('Expected invalid state.');
                } catch (ValidationException $e) {
                    $this->assertSame($message, $e->errors()['sessions'][0]);
                }
            }
        });
    }

    public function test_linked_staff_login_is_authoritative_and_roles_follow_assignments(): void
    {
        $f = $this->rightsFixture();
        $user = $this->memberOf($f['organization'], 'instructor');
        $this->inTenant($f['organization'], function () use ($f, $user): void {
            $original = $this->candidate('Иван');
            $original->update(['user_id' => $user->id]);
            $free = $this->candidate('Мария');
            $free->update(['user_id' => $user->id]);
            $f['tuesday']->assignments()->attach($original->id, ['organization_id' => $f['organization']->id, 'role' => 'assistant']);
            $f['session']->instructors()->attach($original->id, ['organization_id' => $f['organization']->id, 'role' => 'lead']);
            $result = app(SubstituteCandidates::class)->read($f['owner'], [$f['session']->id]);
            $this->assertSame('assistant', $result['sessions'][0]['teachers'][0]['role']);
            $this->assertFalse(collect($result['candidates'])->firstWhere('profile_id', $free->id)['has_access']);
        });
    }

    public function test_open_request_of_current_substitute_is_returned_and_suggestion_is_marked(): void
    {
        $f = $this->rightsFixture();
        $this->inTenant($f['organization'], function () use ($f): void {
            $original = $this->candidate('Иван');
            $current = $this->candidate('Мария');
            $suggested = $this->candidate('Петър');
            $f['session']->instructors()->attach($current->id, ['organization_id' => $f['organization']->id, 'replaces_instructor_profile_id' => $original->id]);
            $requestId = (string) Str::uuid();
            DB::table('substitute_requests')->insert(['id' => $requestId, 'organization_id' => $f['organization']->id, 'requester_user_id' => $f['owner']->id, 'requester_profile_id' => $current->id, 'suggested_profile_id' => $suggested->id, 'reason' => 'other', 'version' => 2, 'idempotency_key' => $requestId, 'request_hash' => str_repeat('a', 64)]);
            DB::table('substitute_request_sessions')->insert(['id' => (string) Str::uuid(), 'organization_id' => $f['organization']->id, 'request_id' => $requestId, 'requester_profile_id' => $current->id, 'session_id' => $f['session']->id, 'status' => 'open']);
            $result = app(SubstituteCandidates::class)->read($f['owner'], [$f['session']->id]);
            $this->assertSame($requestId, $result['open_requests'][0]['id']);
            $this->assertTrue(collect($result['candidates'])->firstWhere('profile_id', $suggested->id)['suggested']);
            $this->assertSame('free', $result['candidates'][0]['state']);
        });
    }

    public function test_activity_assignment_is_relevant_to_any_selected_day_in_that_activity(): void
    {
        $f = $this->rightsFixture();
        $this->inTenant($f['organization'], function () use ($f): void {
            $original = $this->candidate('Иван');
            $candidate = $this->candidate('Мария');
            $f['session']->instructors()->attach($original->id, ['organization_id' => $f['organization']->id]);
            $f['tuesday']->assignments()->attach($candidate->id, ['organization_id' => $f['organization']->id, 'role' => 'assistant', 'ends_on' => '2026-11-10']);
            $later = $f['studio']->sessions()->create(['starts_at' => '2027-02-01 15:00:00', 'ends_at' => '2027-02-01 16:00:00', 'status' => 'scheduled']);
            $later->instructors()->attach($original->id, ['organization_id' => $f['organization']->id]);
            $result = app(SubstituteCandidates::class)->read($f['owner'], [$f['session']->id, $later->id]);
            $row = collect($result['candidates'])->firstWhere('profile_id', $candidate->id);
            $this->assertSame('activity', $row['section']);
            $this->assertContains('помага в Вторник', $row['groups']);
        });
    }

    public function test_released_original_uses_latest_substitution_and_cannot_go_without_again(): void
    {
        $f = $this->rightsFixture();
        $this->inTenant($f['organization'], function () use ($f): void {
            $original = $this->candidate('Иван');
            $helper = $this->candidate('Петър');
            $f['session']->instructors()->attach($helper->id, ['organization_id' => $f['organization']->id]);
            $change = ScheduleChange::query()->create(['action' => 'substitute', 'session_count' => 1, 'idempotency_key' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64), 'result' => [], 'actor_user_id' => $f['owner']->id]);
            DB::table('session_changes')->insert(['id' => (string) Str::uuid(), 'organization_id' => $f['organization']->id, 'schedule_change_id' => $change->id, 'session_id' => $f['session']->id, 'from_status' => 'scheduled', 'to_status' => 'scheduled', 'to_starts_at' => $f['session']->starts_at, 'to_ends_at' => $f['session']->ends_at, 'replaced_profile_id' => $original->id, 'removed_profile_id' => $original->id, 'added_profile_id' => null, 'confirmed_overlaps' => '[]', 'created_at' => now()]);
            $result = app(SubstituteCandidates::class)->read($f['owner'], [$f['session']->id], $original->id);
            $this->assertNull($result['sessions'][0]['steps_down']);
            $this->assertFalse($result['can_go_without']);
            $this->assertSame($original->id, $result['candidates'][0]['profile_id']);
            $this->assertTrue($result['candidates'][0]['return']);
        });
    }

    public function test_draft_group_is_not_found(): void
    {
        $f = $this->rightsFixture();
        $this->inTenant($f['organization'], function () use ($f): void {
            $f['tuesday']->update(['status' => 'draft']);
            try {
                app(SubstituteCandidates::class)->read($f['owner'], [$f['session']->id]);
                $this->fail('Expected hidden draft.');
            } catch (HttpException $e) {
                $this->assertSame(404, $e->getStatusCode());
            }
        });
    }

    public function test_values_warning_queries_execute_on_postgresql(): void
    {
        try {
            $connection = DB::connection('pgsql_admin');
            $pdo = $connection->getPdo();
        } catch (\Throwable) {
            $this->markTestSkipped('PostgreSQL required for VALUES runtime verification.');
        }
        $originalConnection = DB::getDefaultConnection();
        DB::setDefaultConnection('pgsql_admin');
        $pdo->beginTransaction();
        try {
            $organization = Organization::query()->withoutGlobalScopes()->create(['name' => 'Школа', 'slug' => 'pg-'.Str::random(12), 'status' => 'active']);
            $owner = $this->memberOf($organization, 'organization_owner');
            $f = $this->inTenant($organization, function () use ($organization, $owner): array {
                $activity = Activity::query()->create(['slug' => 'pg-'.Str::random(12), 'title' => 'Шах', 'category' => 'Наука', 'summary' => 'Описание', 'age_label' => '2.–4. клас', 'status' => 'published']);
                $offering = $activity->offerings()->create([]);
                $group = $offering->groups()->create(['name' => 'Група', 'capacity_max' => 10, 'status' => 'published']);
                $session = $group->sessions()->create(['starts_at' => '2026-11-03 15:00:00', 'ends_at' => '2026-11-03 16:00:00', 'status' => 'scheduled']);

                return ['organization' => $organization, 'owner' => $owner, 'session' => $session, 'studio' => $group];
            });
            $this->inTenant($f['organization'], function () use ($f): void {
                $original = $this->candidate('Иван');
                $candidate = $this->candidate('Мария');
                $substitute = $this->candidate('Петър');
                $f['session']->instructors()->attach($original->id, ['organization_id' => $f['organization']->id]);
                $busy = $f['studio']->sessions()->create(['starts_at' => '2026-11-03 15:30:00', 'ends_at' => '2026-11-03 16:30:00', 'status' => 'scheduled']);
                $busy->instructors()->attach($candidate->id, ['organization_id' => $f['organization']->id]);
                $away = $f['studio']->sessions()->create(['starts_at' => '2026-11-03 18:00:00', 'ends_at' => '2026-11-03 19:00:00', 'status' => 'scheduled']);
                $away->instructors()->attach($substitute->id, ['organization_id' => $f['organization']->id, 'replaces_instructor_profile_id' => $candidate->id]);
                $result = app(SubstituteCandidates::class)->read($f['owner'], [$f['session']->id]);
                $row = collect($result['candidates'])->firstWhere('profile_id', $candidate->id);
                $this->assertSame('away', $row['state']);
                $this->assertEqualsCanonicalizing(['instructor', 'substitute_away'], array_column($row['warnings'], 'type'));
            });
        } finally {
            $pdo->rollBack();
            DB::setDefaultConnection($originalConnection);
        }
    }

    private function candidate(string $name): InstructorProfile
    {
        $staff = StaffMember::query()->create(['full_name' => $name]);
        $profile = InstructorProfile::query()->create(['public_name' => $name]);
        $profile->forceFill(['staff_member_id' => $staff->id])->save();

        return $profile;
    }
}
