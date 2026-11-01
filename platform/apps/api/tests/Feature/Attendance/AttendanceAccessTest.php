<?php

namespace Tests\Feature\Attendance;

use App\Domain\Attendance\Models\AttendanceSession;
use App\Domain\Attendance\Services\AttendanceAccess;
use App\Domain\Catalog\Models\Session;
use App\Domain\Catalog\Services\AccessScope;
use App\Domain\Catalog\Services\TeachingScope;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\StaffGrant;
use App\Domain\Organizations\Models\StaffScopeGroup;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TeamRightsFixture;
use Tests\TestCase;

class AttendanceAccessTest extends TestCase
{
    use RefreshDatabase, TeamRightsFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-03T15:00:00Z');
    }

    public static function roles(): array
    {
        return [['organization_owner', true, true, true], ['organization_admin', true, true, true],
            ['coordinator', true, true, false], ['viewer', true, false, false], ['finance', true, false, false],
            ['instructor', false, false, false], ['platform_admin', false, false, false]];
    }

    #[DataProvider('roles')]
    public function test_role_rights_and_late_correction_are_separate(string $role, bool $view, bool $mark, bool $late): void
    {
        $f = $this->rightsFixture();
        $user = $this->memberOf($f['organization'], $role);
        $this->inTenant($f['organization'], function () use ($f, $user, $view, $mark, $late): void {
            $access = app(AttendanceAccess::class);
            $this->assertSame($view, $access->canView($user, $f['session']));
            $this->assertSame($mark, $access->mayMark($user, $f['session']));
            $this->assertSame($mark, $access->canMark($user, $f['session']));
            $this->assertFalse($access->requiresReason($user, $f['session']));
            $batch = $access->forSessions($user, collect([$f['session']]), [])[$f['session']->id];
            $this->assertSame($view, $batch['view']);
            $this->assertSame($mark, $batch['mark']);
            $this->travelTo('2026-11-04T22:00:00Z');
            $this->assertSame($late, $access->canMark($user, $f['session']));
            $this->assertSame($late, $access->requiresReason($user, $f['session']));
            $this->assertSame($late, $access->forSessions($user, collect([$f['session']]), [])[$f['session']->id]['mark']);
        });
    }

    public static function teachingKinds(): array
    {
        return [['lead'], ['assistant'], ['substitute'], ['offering_lead']];
    }

    #[DataProvider('teachingKinds')]
    public function test_teachers_share_the_opening_and_calendar_day_deadline(string $kind): void
    {
        $f = $this->rightsFixture();
        $teacher = $this->teacher($f, $kind);
        $this->inTenant($f['organization'], function () use ($f, $teacher): void {
            $access = app(AttendanceAccess::class);
            $this->travelTo('2026-11-03T14:44:00Z');
            $this->assertTrue($access->mayMark($teacher, $f['session']));
            $this->assertTrue($access->canMark($teacher, $f['session']));
            $this->assertFalse($access->abilities($teacher, $f['session'])['mark']);
            $this->travelTo('2026-11-03T14:45:00Z');
            $this->assertTrue($access->abilities($teacher, $f['session'])['mark']);
            $this->travelTo('2026-11-04T21:59:59Z');
            $this->assertTrue($access->canView($teacher, $f['session']));
            $this->assertTrue($access->canMark($teacher, $f['session']));
            $this->travelTo('2026-11-04T22:00:00Z');
            $this->assertFalse($access->canMark($teacher, $f['session']));
        });
    }

    public function test_periods_and_own_groups_do_not_grant_past_attendance_from_a_future_assignment(): void
    {
        $f = $this->rightsFixture();
        $future = $this->teacher($f, 'lead', '2026-11-05');
        $ended = $this->teacher($f, 'lead', null, '2026-11-02');
        $endingToday = $this->teacher($f, 'assistant', null, '2026-11-03');
        $this->inTenant($f['organization'], function () use ($f, $future, $ended, $endingToday): void {
            $teaching = app(TeachingScope::class);
            $access = app(AttendanceAccess::class);
            $this->assertFalse($teaching->teachesSession($future, $f['session']));
            $this->assertFalse($teaching->teachesSession($ended, $f['session']));
            $this->assertFalse($access->canView($future, $f['session']));
            $this->assertNotContains($f['tuesday']->id, $teaching->ownGroupsNow($future));
            $this->travelTo('2026-11-04T10:00:00Z');
            $this->assertTrue($access->canView($endingToday, $f['session']));
            $this->assertTrue($access->canMark($endingToday, $f['session']));
            $this->travelTo('2026-11-05T10:00:00Z');
            $this->assertContains($f['tuesday']->id, $teaching->ownGroupsNow($future));
            $this->assertTrue($access->canView($future, $f['session']));
            $this->assertFalse($access->mayMark($future, $f['session']));
        });
    }

    public function test_only_substitution_is_not_an_own_group_but_replacing_the_assigned_teacher_does_not_remove_their_right(): void
    {
        $f = $this->rightsFixture();
        $lead = $this->teacher($f, 'lead');
        $substitute = $this->teacher($f, 'substitute');
        $this->inTenant($f['organization'], function () use ($f, $lead, $substitute): void {
            $teaching = app(TeachingScope::class);
            $this->assertSame([], $teaching->ownGroupsNow($substitute));
            $this->assertTrue($teaching->teachesSession($lead, $f['session']));
            $this->assertTrue($teaching->teachesSession($substitute, $f['session']));
            $next = $f['tuesday']->sessions()->where('id', '!=', $f['session']->id)->firstOrFail();
            $this->assertFalse(app(AttendanceAccess::class)->canView($substitute, $next));
            $this->travelTo('2026-11-05T10:00:00Z');
            $this->assertFalse(app(AttendanceAccess::class)->canView($substitute, $f['session']));
            $this->assertTrue(app(AttendanceAccess::class)->canView($lead, $f['session']));
        });
    }

    public function test_scoped_grants_do_not_use_the_broader_teaching_shortcut(): void
    {
        $f = $this->rightsFixture();
        $teacher = $this->teacher($f, 'lead', '2026-11-05');
        $person = $this->personFor($f['organization'], $viewer = $this->memberOf($f['organization'], 'viewer'));
        $this->inTenant($f['organization'], function () use ($f, $teacher, $viewer, $person): void {
            $person->forceFill(['access_scope' => 'groups'])->save();
            StaffScopeGroup::create(['staff_member_id' => $person->id, 'group_id' => $f['tuesday']->id, 'added_by' => $f['owner']->id, 'added_at' => now()]);
            StaffGrant::create(['staff_member_id' => $person->id, 'ability' => 'attendance.mark', 'granted_by' => $f['owner']->id, 'granted_at' => now()]);
            $scope = app(AccessScope::class);
            $this->assertTrue($scope->allows($teacher, 'attendance.mark', $f['tuesday']->id));
            $this->assertFalse($scope->allowsInScope($teacher, 'attendance.mark', $f['tuesday']->id));
            $access = app(AttendanceAccess::class);
            $this->assertTrue($access->mayMark($viewer, $f['session']));
            $outside = $f['thursday']->sessions()->firstOrFail();
            $this->assertFalse($access->canView($viewer, $outside));
            $this->assertFalse($access->mayMark($viewer, $outside));
        });
    }

    public function test_draft_foreign_and_inactive_access_are_denied(): void
    {
        $f = $this->rightsFixture();
        $foreign = $this->rightsFixture();
        $this->inTenant($f['organization'], function () use ($f, $foreign): void {
            $access = app(AttendanceAccess::class);
            $this->assertFalse($access->canView($f['owner'], $foreign['session']));
            $this->assertFalse($access->mayMark($f['owner'], $foreign['session']));
            $f['tuesday']->update(['status' => 'draft']);
            $this->assertFalse($access->canView($f['owner'], $f['session']->fresh()));
            $f['tuesday']->update(['status' => 'published']);
            DB::table('organization_memberships')->where('user_id', $f['owner']->id)->update(['status' => 'inactive']);
            $this->assertFalse($access->canView($f['owner'], $f['session']->fresh()));
            $this->assertFalse($access->mayMark($f['owner'], $f['session']->fresh()));
        });
    }

    public static function localDays(): array
    {
        return [['2026-10-23T20:30:00Z', '2026-10-24T20:59:59Z', '2026-10-24T21:00:00Z'],
            ['2026-10-24T21:30:00Z', '2026-10-26T21:59:59Z', '2026-10-26T22:00:00Z'],
            ['2027-03-27T22:30:00Z', '2027-03-29T20:59:59Z', '2027-03-29T21:00:00Z']];
    }

    #[DataProvider('localDays')]
    public function test_deadline_follows_sofia_calendar_days_and_daylight_saving(string $start, string $before, string $expired): void
    {
        $session = (new Session)->forceFill(['starts_at' => $start]);
        $access = app(AttendanceAccess::class);
        $this->travelTo(CarbonImmutable::parse($before));
        $this->assertTrue($access->withinDeadline($session));
        $this->travelTo(CarbonImmutable::parse($expired));
        $this->assertFalse($access->withinDeadline($session));
        $this->assertSame(CarbonImmutable::parse($start)->subMinutes(15)->timestamp, $access->opensAt($session)->timestamp);
    }

    public function test_batch_teaching_has_a_constant_query_count_for_three_and_thirty_sessions(): void
    {
        $f = $this->rightsFixture();
        $teacher = $this->teacher($f, 'lead');
        $this->inTenant($f['organization'], function () use ($f, $teacher): void {
            for ($day = 1; $day <= 30; $day++) {
                $f['tuesday']->sessions()->create(['starts_at' => "2026-12-{$day}T10:00:00Z", 'ends_at' => "2026-12-{$day}T11:00:00Z", 'status' => 'scheduled']);
            }
            $sessions = $f['tuesday']->sessions()->orderBy('id')->take(30)->get();
            $counts = [];
            foreach ([3, 30] as $count) {
                DB::flushQueryLog();
                DB::enableQueryLog();
                try {
                    $taught = app(TeachingScope::class)->sessionsTaught($teacher, $sessions->take($count));
                    $counts[] = count(DB::getQueryLog());
                } finally {
                    DB::disableQueryLog();
                }
                $this->assertCount($count, $taught);
            }
            $this->assertSame($counts[0], $counts[1]);
        });
    }

    public function test_office_held_flags_require_both_rights_and_reflect_the_session_state(): void
    {
        $f = $this->rightsFixture();
        $teacher = $this->teacher($f, 'lead');
        $coordinator = $this->memberOf($f['organization'], 'coordinator');
        $this->inTenant($f['organization'], function () use ($f, $teacher, $coordinator): void {
            $access = app(AttendanceAccess::class);
            $this->assertFalse($access->mayRecordNotHeld($teacher, $f['session']));
            $this->assertTrue($access->mayRecordNotHeld($coordinator, $f['session']));
            $this->assertTrue($access->abilities($coordinator, $f['session'])['not_held']);
            $state = AttendanceSession::query()->create([
                'organization_id' => $f['organization']->id, 'session_id' => $f['session']->id,
                'version' => 1, 'not_held_at' => now(), 'not_held_by' => $coordinator->id, 'not_held_reason' => 'Затворена зала',
            ]);
            $this->assertInstanceOf(CarbonImmutable::class, $state->not_held_at);
            $this->assertFalse($access->abilities($coordinator, $f['session'])['mark']);
            $this->assertFalse($access->abilities($coordinator, $f['session'])['not_held']);
            $this->assertTrue($access->abilities($coordinator, $f['session'])['held']);
            $this->assertFalse($access->abilities($teacher, $f['session'])['held']);
            $this->travelTo('2026-11-04T22:00:00Z');
            $this->assertFalse($access->abilities($coordinator, $f['session'])['held']);
            $this->assertTrue($access->abilities($f['owner'], $f['session'])['held']);
        });
    }

    private function teacher(array $f, string $kind, ?string $from = null, ?string $until = null): User
    {
        $user = $this->memberOf($f['organization'], 'instructor');
        $profile = $this->profileOf($f['organization'], $this->personFor($f['organization'], $user, true));
        $this->inTenant($f['organization'], function () use ($f, $profile, $kind, $from, $until): void {
            if ($kind === 'offering_lead') {
                $f['chess']->update(['lead_instructor_profile_id' => $profile->id]);
            } elseif ($kind === 'substitute') {
                $f['session']->instructors()->attach($profile->id, ['role' => 'lead']);
            } else {
                $f['tuesday']->assignments()->attach($profile->id, ['role' => $kind, 'starts_on' => $from, 'ends_on' => $until]);
            }
        });

        return $user;
    }
}
