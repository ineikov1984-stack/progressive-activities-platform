<?php

namespace Tests\Feature\Identity;

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The parent's own name and phone (docs/product/enrollment-contacts-and-emails.md, т. 5). */
class ContactUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(PreventRequestForgery::class);
    }

    public function test_a_user_saves_a_phone_in_one_form_and_the_audit_keeps_only_field_names(): void
    {
        $user = User::factory()->create(['name' => 'Мария Петрова', 'phone' => null]);

        $this->actingAs($user)
            ->patchJson('/api/v1/me/contact', ['name' => '  Мария Петрова-Иванова ', 'phone' => '0881 234 567'])
            ->assertOk()
            ->assertJsonPath('user.name', 'Мария Петрова-Иванова')
            ->assertJsonPath('user.phone', '+359881234567');

        $this->assertSame('+359881234567', $user->fresh()?->phone);
        $event = AuditEvent::query()->where('action', 'identity.contact_updated')->sole();
        $this->assertSame($user->id, $event->actor_id);
        $this->assertNull($event->organization_id);
        $this->assertSame(['fields' => ['name', 'phone']], $event->metadata);
        $this->assertStringNotContainsString('881234567', (string) json_encode($event->getAttributes()));

        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('user.phone', '+359881234567');
    }

    public function test_a_wrong_phone_is_refused_and_an_empty_one_clears_it(): void
    {
        $user = User::factory()->create(['phone' => '+359881234567']);

        $this->actingAs($user)
            ->patchJson('/api/v1/me/contact', ['phone' => '0888'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['phone']);
        $this->assertSame('+359881234567', $user->fresh()?->phone);

        $this->actingAs($user)
            ->patchJson('/api/v1/me/contact', ['name' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);

        $this->actingAs($user)
            ->patchJson('/api/v1/me/contact', ['phone' => ''])
            ->assertOk()
            ->assertJsonPath('user.phone', null);
        $this->assertNull($user->fresh()?->phone);
    }

    public function test_nothing_changed_writes_no_audit_and_a_guest_is_refused(): void
    {
        $user = User::factory()->create(['phone' => '+359881234567']);

        $this->actingAs($user)
            ->patchJson('/api/v1/me/contact', ['phone' => '088 123 4567'])
            ->assertOk();
        $this->assertSame(0, AuditEvent::query()->where('action', 'identity.contact_updated')->count());

        auth()->guard('web')->logout();
        $this->patchJson('/api/v1/me/contact', ['phone' => '0881 234 567'])->assertUnauthorized();
    }
}
