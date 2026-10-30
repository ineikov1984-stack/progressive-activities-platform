<?php

namespace Tests\Feature\Organizations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The existing team gets a person each, teacher profiles are linked to them, and sessions whose teachers differ from
 * their group's are marked as changed by hand (docs/product/team.md, т. 3, 5.1, 12).
 */
class StaffMembersMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_members_and_teacher_profiles_get_a_person_and_the_step_reverses(): void
    {
        // The migrator keeps the migration it has loaded; requiring the file again would declare its class twice.
        Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true]);
        $this->assertFalse(Schema::hasTable('staff_members'));

        $now = now();
        $organization = (string) Str::uuid();
        DB::table('organizations')->insert(['id' => $organization, 'name' => 'School', 'slug' => 'school', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        $owner = $this->addUser('Мария Колева', '0888000111');
        $teacher = $this->addUser('Иван Петров', null);
        $operator = $this->addUser('Оператор', null);
        foreach ([[$owner, 'organization_owner'], [$teacher, 'instructor'], [$operator, 'platform_admin']] as [$user, $role]) {
            DB::table('organization_memberships')->insert(['id' => (string) Str::uuid(), 'organization_id' => $organization, 'user_id' => $user, 'role' => $role, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        }
        $linked = $this->addProfile($organization, 'Иван', $teacher);
        $unlinked = $this->addProfile($organization, 'Петър Стоянов', null);

        $activity = (string) Str::uuid();
        DB::table('activities')->insert(['id' => $activity, 'organization_id' => $organization, 'title' => 'Chess', 'slug' => 'chess', 'category' => 'Games', 'summary' => 'Chess', 'age_label' => '7+', 'status' => 'draft', 'created_at' => $now, 'updated_at' => $now]);
        $offering = (string) Str::uuid();
        DB::table('offerings')->insert(['id' => $offering, 'organization_id' => $organization, 'activity_id' => $activity, 'created_at' => $now, 'updated_at' => $now]);
        $group = (string) Str::uuid();
        DB::table('activity_groups')->insert(['id' => $group, 'organization_id' => $organization, 'offering_id' => $offering, 'name' => 'Вторник', 'capacity_min' => 1, 'status' => 'draft', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('activity_group_instructors')->insert(['group_id' => $group, 'instructor_profile_id' => $linked, 'organization_id' => $organization, 'created_at' => $now, 'updated_at' => $now]);
        $inherited = $this->addSession($organization, $group, '2026-11-03 15:00:00', [$linked]);
        $byHand = $this->addSession($organization, $group, '2026-11-10 15:00:00', [$unlinked]);
        $withoutTeachers = $this->addSession($organization, $group, '2026-11-17 15:00:00', []);

        Artisan::call('migrate', ['--force' => true]);
        $this->assertTrue(Schema::hasTable('staff_members'));

        $people = DB::table('staff_members')->where('organization_id', $organization)->get()->keyBy('full_name');
        $this->assertCount(3, $people, 'The owner, the teacher with a login and the teacher without one; not the platform operator.');
        $this->assertSame('0888000111', $people['Мария Колева']->phone);
        $this->assertSame($teacher, $people['Иван Петров']->user_id);
        $this->assertNull($people['Петър Стоянов']->user_id);
        $this->assertSame($people['Иван Петров']->id, DB::table('instructor_profiles')->where('id', $linked)->value('staff_member_id'));
        $this->assertSame($people['Петър Стоянов']->id, DB::table('instructor_profiles')->where('id', $unlinked)->value('staff_member_id'));
        $this->assertDatabaseHas('activity_group_instructors', ['group_id' => $group, 'role' => 'lead', 'starts_on' => null, 'ends_on' => null, 'version' => 1]);
        $this->assertSame('assignment', DB::table('activity_sessions')->where('id', $inherited)->value('instructors_source'));
        $this->assertSame('manual', DB::table('activity_sessions')->where('id', $byHand)->value('instructors_source'));
        $this->assertSame('manual', DB::table('activity_sessions')->where('id', $withoutTeachers)->value('instructors_source'));

        // Back: an ended and a not yet started assignment would become current without their period, so they go.
        $later = $this->addProfile($organization, 'Нов треньор', null);
        DB::table('activity_group_instructors')->insert([
            ['group_id' => $group, 'instructor_profile_id' => $unlinked, 'organization_id' => $organization, 'starts_on' => null, 'ends_on' => '2020-01-31', 'created_at' => $now, 'updated_at' => $now],
            ['group_id' => $group, 'instructor_profile_id' => $later, 'organization_id' => $organization, 'starts_on' => '2999-01-01', 'ends_on' => null, 'created_at' => $now, 'updated_at' => $now],
        ]);
        Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true]);
        $this->assertSame([$linked], DB::table('activity_group_instructors')->where('group_id', $group)->pluck('instructor_profile_id')->all());
        $this->assertFalse(Schema::hasColumn('activity_sessions', 'instructors_source'));
        Artisan::call('migrate', ['--force' => true]);
    }

    private function addUser(string $name, ?string $phone): string
    {
        $id = (string) Str::uuid();
        DB::table('users')->insert(['id' => $id, 'name' => $name, 'email' => Str::random(8).'@example.test', 'password' => 'x', 'phone' => $phone, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function addProfile(string $organization, string $name, ?string $user): string
    {
        $id = (string) Str::uuid();
        DB::table('instructor_profiles')->insert(['id' => $id, 'organization_id' => $organization, 'user_id' => $user, 'public_name' => $name, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    /** @param list<string> $teachers */
    private function addSession(string $organization, string $group, string $startsAt, array $teachers): string
    {
        $id = (string) Str::uuid();
        DB::table('activity_sessions')->insert(['id' => $id, 'organization_id' => $organization, 'group_id' => $group, 'starts_at' => $startsAt, 'ends_at' => $startsAt, 'status' => 'scheduled', 'created_at' => now(), 'updated_at' => now()]);
        foreach ($teachers as $teacher) {
            DB::table('activity_session_instructors')->insert(['session_id' => $id, 'instructor_profile_id' => $teacher, 'organization_id' => $organization, 'created_at' => now(), 'updated_at' => now()]);
        }

        return $id;
    }
}
