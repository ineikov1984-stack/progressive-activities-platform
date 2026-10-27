<?php

namespace Tests\Feature\Identity;

use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['session.secure' => true]);
        $this->withoutMiddleware(PreventRequestForgery::class);
    }

    public function test_active_user_can_log_in_and_the_session_is_regenerated(): void
    {
        $user = User::factory()->create([
            'email' => 'parent@example.test',
            'password' => 'Correct-horse-42',
        ]);
        $oldSessionId = session()->getId();

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'PARENT@example.test',
            'password' => 'Correct-horse-42',
        ]);

        $response->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.email', 'parent@example.test')
            ->assertJsonMissingPath('user.password')
            ->assertJsonMissingPath('user.remember_token');
        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($oldSessionId, session()->getId());
        $this->assertTrue(Str::isUuid($user->id));
        $this->assertTrue(Hash::check('Correct-horse-42', $user->password));
        $this->assertStringStartsWith('$argon2id$', $user->password);
    }

    public function test_wrong_and_unknown_credentials_return_the_same_generic_error(): void
    {
        User::factory()->create([
            'email' => 'parent@example.test',
            'password' => 'Correct-horse-42',
        ]);

        $wrongPassword = $this->postJson('/api/v1/auth/login', [
            'email' => 'parent@example.test',
            'password' => 'wrong-password',
        ]);
        $unknownEmail = $this->postJson('/api/v1/auth/login', [
            'email' => 'unknown@example.test',
            'password' => 'wrong-password',
        ]);

        $wrongPassword->assertUnprocessable();
        $unknownEmail->assertUnprocessable();
        $this->assertSame($wrongPassword->json(), $unknownEmail->json());
        $this->assertSame('Невалиден имейл или парола.', $wrongPassword->json('message'));
    }

    public function test_inactive_user_cannot_log_in(): void
    {
        User::factory()->create([
            'email' => 'inactive@example.test',
            'password' => 'Correct-horse-42',
            'is_active' => false,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'inactive@example.test',
            'password' => 'Correct-horse-42',
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'Невалиден имейл или парола.');

        $this->assertGuest();
    }

    public function test_login_is_rate_limited_without_revealing_account_existence(): void
    {
        foreach (range(1, 5) as $attempt) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'unknown@example.test',
                'password' => "wrong-password-{$attempt}",
            ])->assertUnprocessable();
        }

        $this->postJson('/api/v1/auth/login', [
            'email' => 'unknown@example.test',
            'password' => 'wrong-password-6',
        ])->assertTooManyRequests();
    }

    public function test_authenticated_user_can_read_me_and_log_out(): void
    {
        $user = User::factory()->create([
            'locale' => 'bg',
            'timezone' => 'Europe/Sofia',
            'mfa_enabled_at' => now(),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();

        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.locale', 'bg')
            ->assertJsonPath('user.timezone', 'Europe/Sofia')
            ->assertJsonPath('user.mfa_enabled', true)
            ->assertJsonMissingPath('user.password');

        $this->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->assertGuest();
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_session_cookie_has_secure_browser_flags(): void
    {
        User::factory()->create([
            'email' => 'parent@example.test',
            'password' => 'Correct-horse-42',
        ]);

        $response = $this->withServerVariables(['HTTPS' => 'on'])
            ->postJson('/api/v1/auth/login', [
                'email' => 'parent@example.test',
                'password' => 'Correct-horse-42',
            ]);

        $cookie = collect($response->headers->getCookies())
            ->first(fn ($cookie) => $cookie->getName() === config('session.cookie'));

        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertTrue($cookie->isSecure());
        $this->assertSame('lax', strtolower((string) $cookie->getSameSite()));
    }

    public function test_credentials_are_not_written_to_application_logs(): void
    {
        $messages = [];
        Log::listen(function ($event) use (&$messages): void {
            $messages[] = $event->message.' '.json_encode($event->context);
        });

        $secret = 'Never-log-this-password';
        $this->postJson('/api/v1/auth/login', [
            'email' => 'unknown@example.test',
            'password' => $secret,
        ])->assertUnprocessable();

        $this->assertStringNotContainsString($secret, implode('\n', $messages));
        $this->assertStringNotContainsString('token', strtolower(implode('\n', $messages)));
    }
}
