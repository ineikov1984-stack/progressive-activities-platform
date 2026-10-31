<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Models\Activity;
use App\Domain\Catalog\Models\InstructorProfile;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\EnrollmentApplication;
use App\Domain\Enrollment\Models\EnrollmentSessionException;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Organizations\Models\StaffMember;
use App\Domain\Organizations\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use Tests\TestCase;
use Throwable;

/**
 * A change applied as the application runs in PostgreSQL (docs/product/schedule-changes.md, т. 3, 7.3): the request
 * under the role `progressive_app`, so every row it writes — the session, the history of the change and of the session,
 * the visit it ends, the history of the enrollments, the events of the letters, the audit log — passes RLS (WITH
 * CHECK) and the rights the migrations gave, and the repeat reads its stored answer back (SELECT). A child for the
 * period, a trial on the day and its teacher; one transaction, rolled back at the end. Every name is synthetic.
 */
class ScheduleChangeApplicationRoleTest extends TestCase
{
    use RefreshDatabase;

    #[TestGroup('tenant-isolation')]
    public function test_a_cancellation_is_written_whole_under_the_application_role(): void
    {
        try {
            DB::connection('pgsql_admin')->getPdo();
        } catch (Throwable) {
            $this->markTestSkipped('PostgreSQL is required for the check of a change under the application role.');
        }

        Artisan::call('migrate', ['--database' => 'pgsql_admin', '--force' => true]);
        $this->withoutMiddleware(PreventRequestForgery::class);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection('pgsql_admin');
        $context = app(TenantContext::class);
        DB::beginTransaction();

        try {
            $f = $this->changeFixture($context);
            $context->clear();
            DB::statement('SET LOCAL ROLE progressive_app');
            $this->assertSame('progressive_app', DB::selectOne('SELECT current_user AS role')->role);

            $body = [
                'action' => 'cancel', 'sessions' => [['id' => $f['session'], 'version' => 1]],
                'reason' => 'Треньорът е болен.', 'reason_to_parents' => false,
            ];
            $first = $this->actingAs($f['owner'])->withHeader('X-Organization-Id', $f['organization'])
                ->withHeader('Idempotency-Key', 'under-the-application-role')
                ->postJson('/api/v1/schedule/changes', $body)->assertCreated()
                ->assertJsonPath('change.sessions.0.status', 'cancelled')
                // Ани's and Гери's families, and the teacher: found under RLS too.
                ->assertJsonPath('letters', ['families' => 2, 'teachers' => 1, 'without_verified_email' => 0]);
            $changeId = (string) $first->json('change.id');
            // The repeat finds the stored change and its answer under the same role.
            $this->postJson('/api/v1/schedule/changes', $body)->assertOk()->assertJsonPath('change.id', $changeId);

            // Read back as the application, in the organization.
            $this->assertSame('progressive_app', DB::selectOne('SELECT current_user AS role')->role);
            DB::select("SELECT set_config('app.current_organization_id', ?, true)", [$f['organization']]);
            $this->assertSame(1, DB::table('schedule_changes')->where('id', $changeId)->count());
            $this->assertSame(1, DB::table('session_changes')->where('schedule_change_id', $changeId)->count());
            $session = DB::table('activity_sessions')->where('id', $f['session'])->first(['status', 'version', 'change_reason']);
            $this->assertSame(['cancelled', 2, 'Треньорът е болен.'], [$session?->status, (int) $session?->version, $session?->change_reason]);
            $visit = DB::table('enrollments')->where('id', $f['visit'])->first(['status', 'version']);
            $this->assertSame(['cancelled', 2], [$visit?->status, (int) $visit?->version]);
            $enrolled = DB::table('enrollments')->where('id', $f['enrolled'])->first(['status', 'version']);
            $this->assertSame(['active', 2], [$enrolled?->status, (int) $enrolled?->version]);
            // Nothing of it outside the organization.
            DB::select("SELECT set_config('app.current_organization_id', '', true)");
            $this->assertSame(0, DB::table('schedule_changes')->where('id', $changeId)->count());
            $this->assertSame(0, DB::table('session_changes')->where('schedule_change_id', $changeId)->count());

            // What the change wrote besides, counted as the owner of the tables.
            DB::statement('RESET ROLE');
            $this->assertSame(2, DB::table('enrollment_transitions')->where('organization_id', $f['organization'])
                ->whereRaw("metadata->>'schedule_change_id' = ?", [$changeId])->count());
            $this->assertEqualsCanonicalizing(
                ['schedule.sessions_changed', 'schedule.sessions_changed', 'schedule.teacher_sessions_changed'],
                DB::table('enrollment_events')->where('organization_id', $f['organization'])
                    ->whereRaw("payload->>'schedule_change_id' = ?", [$changeId])->pluck('type')->all(),
            );
            $this->assertSame(1, DB::table('audit_events')->where('organization_id', $f['organization'])
                ->where('action', 'schedule.change_applied')->where('subject_id', $changeId)->count());
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $context->clear();
            DB::setDefaultConnection($original);
        }
    }

    #[TestGroup('tenant-isolation')]
    public function test_a_move_and_skip_removal_are_written_under_the_application_role(): void
    {
        try {
            DB::connection('pgsql_admin')->getPdo();
        } catch (Throwable) {
            $this->markTestSkipped('PostgreSQL is required for a move under the application role.');
        }
        Artisan::call('migrate', ['--database' => 'pgsql_admin', '--force' => true]);
        $this->withoutMiddleware(PreventRequestForgery::class);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection('pgsql_admin');
        $context = app(TenantContext::class);
        DB::beginTransaction();
        try {
            $f = $this->changeFixture($context);
            EnrollmentSessionException::query()->create([
                'enrollment_id' => $f['enrolled'], 'session_id' => $f['session'], 'kind' => 'excluded',
            ]);
            $day = CarbonImmutable::now('Europe/Sofia')->addDays(8)->toDateString();
            $context->clear();
            DB::statement('SET LOCAL ROLE progressive_app');
            $body = ['action' => 'move', 'sessions' => [['id' => $f['session'], 'version' => 1]],
                'date' => $day, 'from' => '17:00', 'to' => '18:00', 'reason' => 'Залата е заета.', 'reason_to_parents' => false];
            $first = $this->actingAs($f['owner'])->withHeader('X-Organization-Id', $f['organization'])
                ->withHeader('Idempotency-Key', 'move-under-application-role')->postJson('/api/v1/schedule/changes', $body)
                ->assertCreated()->assertJsonPath('change.sessions.0.status', 'scheduled')->assertJsonPath('letters.families', 2);
            $this->postJson('/api/v1/schedule/changes', $body)->assertOk()->assertExactJson($first->json());
            DB::select("SELECT set_config('app.current_organization_id', ?, true)", [$f['organization']]);
            $this->assertSame(0, DB::table('enrollment_session_exceptions')->where('enrollment_id', $f['enrolled'])->count());
            $this->assertSame(2, (int) DB::table('activity_sessions')->where('id', $f['session'])->value('version'));
            $this->assertSame($day, substr((string) DB::table('enrollments')->where('id', $f['visit'])->value('starts_on'), 0, 10));
            $this->assertSame(1, DB::table('session_changes')->where('schedule_change_id', $first->json('change.id'))->count());
            DB::select("SELECT set_config('app.current_organization_id', '', true)");
            $this->assertSame(0, DB::table('session_changes')->where('schedule_change_id', $first->json('change.id'))->count());
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $context->clear();
            DB::setDefaultConnection($original);
        }
    }

    /**
     * "Шах · Вторник" a week from now, taught by Мария; Ани enrolled for the period since a week ago, Гери on a trial
     * that day. Made as the owner of the tables, in the test's transaction.
     *
     * @return array{organization: string, owner: User, session: string, enrolled: string, visit: string}
     */
    private function changeFixture(TenantContext $context): array
    {
        $organization = Organization::query()->withoutGlobalScopes()->create(['name' => 'Школа', 'slug' => 'school-'.Str::random(12), 'status' => 'active']);
        $owner = $this->teamMember($organization, 'organization_owner');
        $teacher = $this->teamMember($organization, 'instructor');
        $context->select($organization);
        StaffMember::query()->create(['full_name' => 'Синтетичен собственик', 'user_id' => $owner->id]);
        $person = StaffMember::query()->create(['full_name' => 'Мария Синтетична', 'user_id' => $teacher->id]);
        $profile = InstructorProfile::query()->create(['public_name' => 'Мария']);
        $profile->forceFill(['staff_member_id' => $person->id])->save();

        $activity = Activity::query()->create([
            'slug' => 'chess-'.Str::random(8), 'title' => 'Шах', 'category' => 'Наука', 'summary' => 'Описание', 'age_label' => '7+', 'status' => 'published',
        ]);
        $offering = $activity->offerings()->create([]);
        $group = $offering->groups()->create(['name' => 'Вторник', 'capacity_max' => 10, 'status' => 'published']);
        $starts = CarbonImmutable::now()->utc()->addWeek()->setTime(15, 0);
        $session = $group->sessions()->create(['starts_at' => $starts, 'ends_at' => $starts->addHour(), 'status' => 'scheduled']);
        $session->instructors()->sync([$profile->id => ['role' => 'lead']]);
        $price = $offering->priceOptions()->create([
            'group_id' => $group->id, 'enrollment_mode' => 'monthly', 'unit' => 'month', 'amount_cents' => 4500, 'currency' => 'EUR', 'is_available' => true,
        ]);

        return [
            'organization' => (string) $organization->id,
            'owner' => $owner,
            'session' => (string) $session->id,
            'enrolled' => $this->approved('Ани', (string) $group->id, (string) $price->id, null, CarbonImmutable::now()->subWeek()->toDateString()),
            'visit' => $this->approved('Гери', (string) $group->id, (string) $price->id, (string) $session->id, $starts->setTimezone('Europe/Sofia')->toDateString()),
        ];
    }

    private function teamMember(Organization $organization, string $role): User
    {
        $user = User::factory()->create();
        OrganizationMembership::query()->withoutGlobalScopes()->create([
            'organization_id' => $organization->id, 'user_id' => $user->id, 'role' => $role, 'status' => 'active',
        ]);

        return $user;
    }

    /** A child of a new family, approved for the period or, with a session, for a trial — which keeps a copy of its terms. */
    private function approved(string $firstName, string $groupId, string $priceId, ?string $sessionId, string $startsOn): string
    {
        $parent = User::factory()->create(['name' => 'Родител '.$firstName]);
        $child = $parent->children()->create(['first_name' => $firstName, 'last_name' => 'Тестова']);
        $scope = $sessionId === null ? 'full_period' : 'trial';
        $application = EnrollmentApplication::query()->create([
            'parent_user_id' => $parent->id, 'child_id' => $child->id, 'group_id' => $groupId, 'price_option_id' => $priceId,
            'session_id' => $sessionId, 'scope' => $scope, 'source' => 'organization_catalog', 'status' => 'approved',
            'idempotency_key' => (string) Str::uuid(), 'accepted_terms_version' => 'v1', 'accepted_terms_at' => now(),
            'amount_cents_snapshot' => 1500, 'currency_snapshot' => 'EUR', 'unit_snapshot' => $sessionId === null ? 'month' : 'session',
            ...($sessionId === null ? [] : ['payment_terms_snapshot' => ['rules' => [], 'accepted' => ['via' => 'parent', 'at' => now()->toIso8601String()]]]),
        ]);

        return (string) Enrollment::query()->create([
            'application_id' => $application->id, 'child_id' => $child->id, 'group_id' => $groupId, 'price_option_id' => $priceId,
            'session_id' => $sessionId, 'scope' => $scope, 'status' => 'active', 'starts_on' => $startsOn,
        ])->id;
    }
}
