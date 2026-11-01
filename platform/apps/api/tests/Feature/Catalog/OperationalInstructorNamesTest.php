<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Actions\SubmitSubstituteRequest;
use App\Domain\Catalog\Models\InstructorProfile;
use App\Domain\Catalog\Services\InstructorNames;
use App\Domain\Catalog\Services\OfficeSubstituteRequests;
use App\Domain\Catalog\Services\SubstituteCandidates;
use App\Domain\Catalog\Services\SubstituteRequests;
use App\Domain\Organizations\Models\StaffMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\TeamRightsFixture;
use Tests\TestCase;

class OperationalInstructorNamesTest extends TestCase
{
    use RefreshDatabase, TeamRightsFixture;

    public function test_existing_request_and_schedule_follow_team_edits_without_changing_the_public_profile(): void
    {
        $this->travelTo('2026-11-01 10:00:00');
        $f = $this->rightsFixture();
        $teacher = $this->memberOf($f['organization'], 'instructor');
        [$profile,$person,$request] = $this->inTenant($f['organization'], function () use ($f, $teacher): array {
            $person = StaffMember::create(['user_id' => $teacher->id, 'full_name' => 'Актуално име', 'phone' => '+359888123456']);
            $profile = InstructorProfile::create(['public_name' => 'Име за сайта', 'user_id' => $teacher->id]);
            $profile->forceFill(['staff_member_id' => $person->id])->save();
            $f['chess']->update(['lead_instructor_profile_id' => $profile->id]);
            $f['session']->instructors()->attach($profile->id, ['organization_id' => $f['organization']->id]);
            $request = app(SubmitSubstituteRequest::class)->execute($teacher, [['id' => $f['session']->id, 'version' => $f['session']->version]], 'personal', null, null, (string) Str::uuid())['request'];

            return [$profile, $person, $request];
        });
        $this->actingAs($f['owner'])->withHeader('X-Organization-Id', $f['organization']->id)->getJson('/api/v1/schedule/substitute-requests')->assertOk()->assertJsonPath('requests.0.requester.name', 'Актуално име');
        $this->getJson('/api/v1/schedule?from=2026-11-02&to=2026-11-08')->assertOk()->assertJsonPath('sessions.0.instructors.0.name', 'Актуално име');
        $this->getJson('/api/v1/schedule/sessions/'.$f['session']->id)->assertOk()->assertJsonPath('session.instructors.0.name', 'Актуално име');
        $this->actingAs($teacher)->patchJson('/api/v1/me/contact', ['name' => 'Име след промяна'])->assertOk();
        $this->inTenant($f['organization'], function () use ($f, $profile, $request): void {
            $this->assertSame('Име след промяна', app(OfficeSubstituteRequests::class)->detail($f['owner'], $request->id)['requester']['name']);
            $this->assertSame('Име след промяна', app(SubstituteCandidates::class)->read($f['owner'], [$f['session']->id])['replaces']['name']);
            $this->assertSame('Име за сайта', $profile->fresh()->public_name);
        });
        $this->actingAs($f['owner'])->patchJson('/api/v1/team/members/'.$person->id, ['version' => 2, 'full_name' => 'Име от офиса'])->assertOk();
        $this->getJson('/api/v1/schedule/substitute-requests')->assertOk()->assertJsonPath('requests.0.requester.name', 'Име от офиса');
        $this->inTenant($f['organization'], fn () => $f['tuesday']->assignments()->attach($profile->id, ['organization_id' => $f['organization']->id, 'role' => 'lead', 'starts_on' => '2026-10-01']));
        $this->inTenant($f['organization'], fn () => $f['thursday']->sessions()->firstOrFail()->instructors()->attach($profile->id, ['organization_id' => $f['organization']->id]));
        $preview = $this->postJson('/api/v1/schedule/changes/preview', ['action' => 'move', 'sessions' => [['id' => $f['session']->id, 'version' => 1]], 'date' => '2026-11-05', 'from' => '17:00', 'to' => '18:00', 'reason' => 'Причина'])->assertOk();
        $warnings = json_encode($preview->json('warnings'), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('Име от офиса', $warnings);
        $this->assertStringNotContainsString('Име за сайта', $warnings);
    }

    public function test_suggestions_and_replacements_use_current_team_names(): void
    {
        $this->travelTo('2026-11-01 10:00:00');
        $f = $this->rightsFixture();
        $teacher = $this->memberOf($f['organization'], 'instructor');
        $this->inTenant($f['organization'], function () use ($f, $teacher): void {
            $profiles = [];
            foreach (['Оригинал', 'Заместник'] as $name) {
                $person = StaffMember::create(['full_name' => $name, 'user_id' => $name === 'Оригинал' ? $teacher->id : null]);
                $p = InstructorProfile::create(['public_name' => 'Старо '.$name]);
                $p->forceFill(['staff_member_id' => $person->id])->save();
                $profiles[] = $p;
            }
            [$original,$sub] = $profiles;
            $f['thursday']->assignments()->attach($sub->id, ['organization_id' => $f['organization']->id, 'role' => 'lead', 'starts_on' => '2026-10-01']);
            $f['session']->instructors()->attach($original->id, ['organization_id' => $f['organization']->id]);
            $request = app(SubmitSubstituteRequest::class)->execute($teacher, [['id' => $f['session']->id, 'version' => 1]], 'personal', null, $sub->id, (string) Str::uuid())['request'];
            $this->assertSame('Заместник', app(SubstituteRequests::class)->request($request)['suggested']['name']);
            $this->assertSame('Заместник', app(SubstituteCandidates::class)->read($f['owner'], [$f['session']->id])['candidates'][0]['name']);
            $f['session']->instructors()->detach($original->id);
            $f['session']->instructors()->attach($sub->id, ['organization_id' => $f['organization']->id, 'replaces_instructor_profile_id' => $original->id]);
            $row = app(SubstituteRequests::class)->request($request)['sessions'][0];
            $this->assertSame('Заместник', $row['now']['substitute']['name']);
        });
    }

    #[Group('tenant-isolation')]
    public function test_name_lookup_is_tenant_scoped_preserves_legacy_fallback_and_batches_queries(): void
    {
        $f = $this->rightsFixture();
        $foreign = $this->rightsFixture();
        $other = $this->inTenant($foreign['organization'], fn () => InstructorProfile::create(['public_name' => 'Чужд профил']));
        $this->inTenant($f['organization'], function () use ($other): void {
            $legacy = InstructorProfile::create(['public_name' => 'Без свързан служител']);
            $ids = [];
            for ($i = 0; $i < 30; $i++) {
                $person = StaffMember::create(['full_name' => 'Човек '.$i]);
                $p = InstructorProfile::create(['public_name' => 'Публично '.$i]);
                $p->forceFill(['staff_member_id' => $person->id])->save();
                $ids[] = $p->id;
            }
            $reader = app(InstructorNames::class);
            $this->assertSame([$legacy->id => 'Без свързан служител'], $reader->forIds([$legacy->id, $other->id]));
            $counts = [];
            foreach ([array_slice($ids, 0, 3), $ids] as $batch) {
                DB::enableQueryLog();
                DB::flushQueryLog();
                $names = $reader->forIds($batch);
                $counts[] = count(DB::getQueryLog());
                DB::disableQueryLog();
                $this->assertCount(count($batch), $names);
                $this->assertSame('Човек 0', $names[$ids[0]]);
            }
            $this->assertSame($counts[0], $counts[1]);
            $this->assertLessThanOrEqual(2, $counts[1]);
        });
    }
}
