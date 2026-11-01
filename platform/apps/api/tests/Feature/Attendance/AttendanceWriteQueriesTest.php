<?php

namespace Tests\Feature\Attendance;

use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Organizations\Tenancy\TenantContext;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\VisitOfferFixture;
use Tests\TestCase;

class AttendanceWriteQueriesTest extends TestCase
{
    use RefreshDatabase, VisitOfferFixture;

    public function test_three_and_thirty_children_are_written_in_a_constant_number_of_queries(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->travelTo('2026-11-03T14:00:00Z');
        [$org, , $group, $session, , $child] = $this->visitFixture();
        $owner = User::factory()->create();
        OrganizationMembership::query()->create(['organization_id' => $org->id, 'user_id' => $owner->id, 'role' => 'organization_owner', 'status' => 'active']);
        $enrollments = [];
        for ($i = 0; $i < 3; $i++) {
            $enrollments[] = $this->visitEnrollment($child->owner->children()->create(['first_name' => 'Дете '.$i, 'last_name' => 'Пример']), $group, null, 'full_period');
        }
        $uri = '/api/v1/attendance/sessions/'.$session->id.'/changes';
        $this->actingAs($owner)->withSession(['active_organization_id' => $org->id])->postJson($uri, [])->assertUnprocessable();
        $measure = function (string $key, int $children) use ($uri): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->withHeader('Idempotency-Key', $key)->postJson($uri, ['action' => 'all_present'])->assertOk()->assertJsonCount($children, 'applied');
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };
        $three = $measure('three', 3);
        $this->withHeader('Idempotency-Key', 'clear')->postJson($uri, ['action' => 'mark', 'marks' => array_map(fn ($enrollment): array => ['enrollment_id' => $enrollment->id, 'status' => null, 'version' => 1], $enrollments)])->assertOk();
        app(TenantContext::class)->select($org);
        for ($i = 3; $i < 30; $i++) {
            $this->visitEnrollment($child->owner->children()->create(['first_name' => 'Дете '.$i, 'last_name' => 'Пример']), $group, null, 'full_period');
        }
        $thirty = $measure('thirty', 30);
        $this->assertSame($three, $thirty);
        $this->assertSame(30, DB::table('attendance_marks')->count());
        $this->assertSame(36, DB::table('attendance_mark_changes')->count());
    }
}
