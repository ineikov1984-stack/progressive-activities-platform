<?php

namespace Tests\Feature\Enrollment;

use App\Domain\Enrollment\Models\Child;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ParentChildTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(PreventRequestForgery::class);
    }

    public function test_guest_cannot_manage_child_profiles(): void
    {
        $this->getJson('/api/v1/parent/children')->assertUnauthorized();
        $this->postJson('/api/v1/parent/children', [])->assertUnauthorized();
    }

    public function test_parent_can_create_and_list_only_their_children(): void
    {
        $parent = User::factory()->create();
        $otherParent = User::factory()->create();
        $foreign = $otherParent->children()->create([
            'first_name' => 'Чуждо',
            'last_name' => 'Дете',
        ]);

        $response = $this->actingAs($parent)->postJson('/api/v1/parent/children', [
            'owner_user_id' => $otherParent->id,
            'first_name' => 'Ани',
            'last_name' => 'Иванова',
            'birth_date' => '2018-04-12',
            'school_name' => 'Основно училище',
            'class_label' => '2. клас',
            'institution_group' => '2А',
        ]);

        $response->assertCreated()
            ->assertJsonPath('child.owner_user_id', $parent->id)
            ->assertJsonPath('child.first_name', 'Ани')
            ->assertJsonPath('child.birth_date', '2018-04-12')
            ->assertJsonPath('child.school_name', 'Основно училище')
            ->assertJsonPath('child.class_label', '2. клас')
            ->assertJsonPath('child.institution_group', '2А')
            ->assertJsonPath('child.status', 'active')
            ->assertJsonPath('child.version', 1);

        $childId = $response->json('child.id');
        $this->assertDatabaseHas('children', ['id' => $childId, 'owner_user_id' => $parent->id]);
        $this->actingAs($parent)->getJson('/api/v1/parent/children')
            ->assertOk()
            ->assertJsonCount(1, 'children')
            ->assertJsonPath('children.0.id', $childId)
            ->assertJsonMissing(['id' => $foreign->id]);
    }

    public function test_names_are_required_and_optional_fields_are_validated(): void
    {
        $parent = User::factory()->create();

        $this->actingAs($parent)->postJson('/api/v1/parent/children', [
            'first_name' => '',
            'last_name' => '',
            'birth_date' => 'not-a-date',
            'school_name' => str_repeat('а', 256),
            'class_label' => str_repeat('б', 101),
            'institution_group' => str_repeat('в', 101),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors([
                'first_name', 'last_name', 'birth_date', 'school_name', 'class_label', 'institution_group',
            ]);
    }

    public function test_parent_can_read_update_and_archive_their_child(): void
    {
        $parent = User::factory()->create();
        $child = $parent->children()->create([
            'first_name' => 'Ани',
            'last_name' => 'Иванова',
        ]);

        $this->actingAs($parent)->getJson('/api/v1/parent/children/'.$child->id)
            ->assertOk()
            ->assertJsonPath('child.id', $child->id);

        $this->actingAs($parent)->patchJson('/api/v1/parent/children/'.$child->id, [
            'owner_user_id' => User::factory()->create()->id,
            'version' => 1,
            'first_name' => 'Анна',
            'school_name' => 'Ново училище',
        ])->assertOk()
            ->assertJsonPath('child.owner_user_id', $parent->id)
            ->assertJsonPath('child.first_name', 'Анна')
            ->assertJsonPath('child.school_name', 'Ново училище')
            ->assertJsonPath('child.version', 2);

        $this->actingAs($parent)->patchJson('/api/v1/parent/children/'.$child->id, [
            'version' => 2,
            'status' => 'archived',
        ])->assertOk()
            ->assertJsonPath('child.status', 'archived')
            ->assertJsonPath('child.version', 3);
    }

    public function test_foreign_child_uuid_is_not_disclosed_for_read_or_write(): void
    {
        $parent = User::factory()->create();
        $foreign = User::factory()->create()->children()->create([
            'first_name' => 'Чуждо',
            'last_name' => 'Дете',
        ]);

        $this->actingAs($parent)->getJson('/api/v1/parent/children/'.$foreign->id)->assertNotFound();
        $this->actingAs($parent)->patchJson('/api/v1/parent/children/'.$foreign->id, [
            'version' => 1,
            'first_name' => 'Опит за промяна',
        ])->assertNotFound();

        $this->assertSame('Чуждо', Child::query()->findOrFail($foreign->id)->first_name);
    }

    public function test_stale_child_update_is_rejected_without_losing_newer_data(): void
    {
        $parent = User::factory()->create();
        $child = $parent->children()->create([
            'first_name' => 'Ани',
            'last_name' => 'Иванова',
        ]);
        $child->update(['first_name' => 'Актуално име', 'version' => 2]);

        $this->actingAs($parent)->patchJson('/api/v1/parent/children/'.$child->id, [
            'version' => 1,
            'first_name' => 'Остаряла промяна',
        ])->assertConflict()
            ->assertJsonPath('message', 'Профилът е променен междувременно. Презаредете и опитайте отново.');

        $this->assertDatabaseHas('children', [
            'id' => $child->id,
            'first_name' => 'Актуално име',
            'version' => 2,
        ]);
    }
}
