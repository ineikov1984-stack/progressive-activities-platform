<?php

namespace Tests\Feature\Enrollment;

use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\EnrollmentEvent;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\StaffGrant;
use App\Domain\Organizations\Models\StaffScopeGroup;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TeamRightsFixture;
use Tests\TestCase;

class EnrollmentTransferVisibilityTest extends TestCase
{
    use RefreshDatabase;
    use TeamRightsFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->travelTo('2026-11-02 10:00:00');
    }

    /** @return array<string, array{bool}> */
    public static function moveChains(): array
    {
        return ['direct' => [false], 'through a cancelled move' => [true]];
    }

    #[DataProvider('moveChains')]
    public function test_a_hidden_destination_is_absent_from_details_roster_and_other_records(bool $cancelled): void
    {
        $f = $this->transferFixture($cancelled);
        $viewer = $this->scopedViewer($f, [$f['tuesday']->id, $f['studio']->id]);
        $this->signInTo($viewer, $f['organization']);

        $details = $this->getJson("/api/v1/enrollment/enrollments/{$f['enrollment']->id}")->assertOk();
        $details->assertJsonPath('enrollment.moves_to', null);
        $this->assertStringNotContainsString($f['thursday']->id, $details->getContent());
        $this->assertStringNotContainsString('Четвъртък', $details->getContent());

        $roster = $this->getJson("/api/v1/enrollment/groups/{$f['tuesday']->id}/roster")->assertOk();
        $row = collect($roster->json('enrollments'))->firstWhere('id', $f['enrollment']->id);
        $this->assertNotNull($row);
        $this->assertNull($row['moves_to']);
        $this->assertStringNotContainsString($f['thursday']->id, $roster->getContent());

        $records = $this->getJson("/api/v1/enrollment/applications/{$f['otherApplication']->id}")->assertOk();
        $row = collect($records->json('records'))->firstWhere('id', $f['enrollment']->id);
        $this->assertNotNull($row);
        $this->assertNull($row['moves_to']);
        $this->assertStringNotContainsString('Четвъртък', $records->getContent());
    }

    #[DataProvider('moveChains')]
    public function test_a_hidden_origin_is_absent_from_details_roster_and_history(bool $cancelled): void
    {
        $f = $this->transferFixture($cancelled);
        $viewer = $this->scopedViewer($f, [$f['thursday']->id]);
        $this->signInTo($viewer, $f['organization']);

        $details = $this->getJson("/api/v1/enrollment/enrollments/{$f['moved']->id}")->assertOk();
        $details->assertJsonPath('enrollment.transferred_from', null);
        $this->assertStringNotContainsString($f['tuesday']->id, $details->getContent());
        $this->assertStringNotContainsString('Вторник', $details->getContent());
        $this->assertNotEmpty($details->json('history'));
        $this->assertArrayNotHasKey('from_group_name', $details->json('history.0.details'));

        $roster = $this->getJson("/api/v1/enrollment/groups/{$f['thursday']->id}/roster")->assertOk();
        $row = collect($roster->json('enrollments'))->firstWhere('id', $f['moved']->id);
        $this->assertNotNull($row);
        $this->assertNull($row['transferred_from_id']);
        $this->assertStringNotContainsString($f['moved']->transferred_from_id, $roster->getContent());
        $application = $this->getJson("/api/v1/enrollment/applications/{$f['moved']->application_id}")->assertOk();
        $this->assertNotEmpty($application->json('history'));
        $this->assertStringNotContainsString('Вторник', $application->getContent());
    }

    #[DataProvider('moveChains')]
    public function test_visible_destinations_and_unrestricted_history_remain_available(bool $cancelled): void
    {
        $f = $this->transferFixture($cancelled);
        $viewer = $this->scopedViewer($f, [$f['tuesday']->id, $f['thursday']->id, $f['studio']->id]);
        foreach ([$viewer, $f['owner']] as $actor) {
            $this->signInTo($actor, $f['organization']);
            $this->getJson("/api/v1/enrollment/enrollments/{$f['enrollment']->id}")->assertOk()
                ->assertJsonPath('enrollment.moves_to.group_id', $f['thursday']->id);
            $this->getJson("/api/v1/enrollment/enrollments/{$f['moved']->id}")->assertOk()
                ->assertJsonPath('enrollment.transferred_from.group_id', $f['tuesday']->id)
                ->assertJsonPath('history.0.details.from_group_name', 'Вторник');
            $roster = $this->getJson("/api/v1/enrollment/groups/{$f['tuesday']->id}/roster")->assertOk();
            $row = collect($roster->json('enrollments'))->firstWhere('id', $f['enrollment']->id);
            $this->assertSame($f['thursday']->id, $row['moves_to']['group_id']);
            $records = $this->getJson("/api/v1/enrollment/applications/{$f['otherApplication']->id}")->assertOk();
            $row = collect($records->json('records'))->firstWhere('id', $f['enrollment']->id);
            $this->assertSame('Четвъртък', $row['moves_to']['group_name']);
        }
    }

    public function test_a_hidden_cancelled_intermediate_does_not_hide_the_visible_destination(): void
    {
        $f = $this->transferFixture(true);
        $this->inTenant($f['organization'], function () use ($f): void {
            $intermediate = Enrollment::query()->findOrFail($f['moved']->transferred_from_id);
            $intermediate->application->forceFill(['group_id' => $f['studio']->id])->save();
            $intermediate->forceFill(['group_id' => $f['studio']->id])->save();
        });
        $viewer = $this->scopedViewer($f, [$f['tuesday']->id, $f['thursday']->id]);
        $this->signInTo($viewer, $f['organization']);
        $this->getJson("/api/v1/enrollment/enrollments/{$f['enrollment']->id}")->assertOk()
            ->assertJsonPath('enrollment.moves_to.group_id', $f['thursday']->id);
        $details = $this->getJson("/api/v1/enrollment/enrollments/{$f['moved']->id}")->assertOk();
        $details->assertJsonPath('enrollment.transferred_from', null);
        $this->assertStringNotContainsString('Ателие', $details->getContent());
        $this->assertStringNotContainsString($f['studio']->id, $details->getContent());
    }

    public function test_roster_transfer_lookups_do_not_grow_with_the_number_of_children(): void
    {
        $f = $this->transferFixture(false);
        $viewer = $this->scopedViewer($f, [$f['thursday']->id]);
        $add = function (int $count) use ($f): void {
            $this->inTenant($f['organization'], function () use ($f, $count): void {
                for ($index = 0; $index < $count; $index++) {
                    $child = $f['owner']->children()->create(['first_name' => 'Демо', 'last_name' => 'Дете']);
                    $previousApplication = $this->rightsApplication($f['owner'], $child->id, $f['tuesday']->id, $f['price']->id, 'approved');
                    $previous = Enrollment::query()->create([
                        'application_id' => $previousApplication->id, 'child_id' => $child->id, 'group_id' => $f['tuesday']->id,
                        'price_option_id' => $f['price']->id, 'scope' => 'full_period', 'status' => 'ended',
                        'starts_on' => '2026-10-20', 'ends_on' => '2026-11-04',
                    ]);
                    $application = $this->rightsApplication($f['owner'], $child->id, $f['thursday']->id, $f['price']->id, 'approved');
                    Enrollment::query()->create([
                        'application_id' => $application->id, 'child_id' => $child->id, 'group_id' => $f['thursday']->id,
                        'price_option_id' => $f['price']->id, 'scope' => 'full_period', 'status' => 'active',
                        'starts_on' => '2026-11-05', 'transferred_from_id' => $previous->id,
                    ]);
                }
            });
        };
        $queries = function (int $rows) use ($f, $viewer): int {
            DB::enableQueryLog();
            DB::flushQueryLog();
            try {
                $this->signInTo($viewer, $f['organization'])
                    ->getJson("/api/v1/enrollment/groups/{$f['thursday']->id}/roster")->assertOk()->assertJsonCount($rows, 'enrollments');

                return count(array_filter(DB::getQueryLog(), fn (array $query): bool => str_contains($query['query'], 'from "enrollments"')));
            } finally {
                DB::disableQueryLog();
                DB::flushQueryLog();
            }
        };
        $add(2);
        $small = $queries(3);
        $add(27);
        $this->assertSame($small, $queries(30));
    }

    /** @return array<string, mixed> */
    private function transferFixture(bool $cancelled): array
    {
        $f = $this->rightsFixture();

        return $this->inTenant($f['organization'], function () use ($f, $cancelled): array {
            $from = $f['enrollment'];
            $parent = User::query()->findOrFail($from->application->parent_user_id);
            $from->forceFill(['status' => 'ended', 'ends_on' => '2026-11-04'])->save();
            $previous = $from;
            if ($cancelled) {
                $application = $this->rightsApplication($parent, $from->child_id, $f['tuesday']->id, $f['price']->id, 'approved');
                $previous = Enrollment::query()->create([
                    'application_id' => $application->id, 'child_id' => $from->child_id, 'group_id' => $f['tuesday']->id,
                    'price_option_id' => $f['price']->id, 'scope' => 'full_period', 'status' => 'cancelled',
                    'starts_on' => '2026-11-05', 'ends_on' => '2026-11-05', 'transferred_from_id' => $from->id,
                ]);
            }
            $application = $this->rightsApplication($parent, $from->child_id, $f['thursday']->id, $f['price']->id, 'approved');
            $moved = Enrollment::query()->create([
                'application_id' => $application->id, 'child_id' => $from->child_id, 'group_id' => $f['thursday']->id,
                'price_option_id' => $f['price']->id, 'scope' => 'full_period', 'status' => 'active',
                'starts_on' => '2026-11-05', 'transferred_from_id' => $previous->id,
            ]);
            EnrollmentEvent::query()->create([
                'enrollment_id' => $moved->id, 'application_id' => $application->id,
                'type' => 'enrollment.transferred', 'occurred_at' => now(), 'payload' => ['transferred_from_id' => $previous->id],
            ]);
            $otherApplication = $this->rightsApplication($parent, $from->child_id, $f['studio']->id, $f['price']->id, 'waitlisted');

            return [...$f, 'moved' => $moved, 'otherApplication' => $otherApplication];
        });
    }

    /** @param array<string, mixed> $f
     * @param  list<string>  $groups
     */
    private function scopedViewer(array $f, array $groups): User
    {
        $viewer = $this->memberOf($f['organization'], 'viewer');
        $person = $this->personFor($f['organization'], $viewer);
        $this->inTenant($f['organization'], function () use ($f, $person, $groups): void {
            $person->forceFill(['access_scope' => 'groups'])->save();
            foreach ($groups as $group) {
                StaffScopeGroup::query()->create(['staff_member_id' => $person->id, 'group_id' => $group, 'added_by' => $f['owner']->id, 'added_at' => now()]);
            }
            StaffGrant::query()->create(['staff_member_id' => $person->id, 'ability' => 'enrollment.enroll', 'granted_by' => $f['owner']->id, 'granted_at' => now()]);
        });

        return $viewer;
    }
}
