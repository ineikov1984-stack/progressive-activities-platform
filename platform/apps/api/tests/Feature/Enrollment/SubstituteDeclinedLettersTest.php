<?php

namespace Tests\Feature\Enrollment;

use App\Domain\Catalog\Models\InstructorProfile;
use App\Domain\Catalog\Models\SubstituteRequest;
use App\Domain\Catalog\Models\SubstituteRequestSession;
use App\Domain\Enrollment\Delivery\ComposeDelivery;
use App\Domain\Enrollment\Delivery\DeliveryRecipients;
use App\Domain\Enrollment\Models\EnrollmentEventDelivery;
use App\Domain\Enrollment\Services\TeamLetters;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Organizations\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\VisitOfferFixture;
use Tests\TestCase;

class SubstituteDeclinedLettersTest extends TestCase
{
    use RefreshDatabase, VisitOfferFixture;

    public function test_declined_letter_is_definitive_private_and_only_for_the_active_requester(): void
    {
        $this->travelTo('2026-11-02 10:00:00');
        [$organization, , , $session] = $this->visitFixture();
        $requester = User::factory()->create();
        $decider = User::factory()->create(['name' => 'Елена Димитрова']);
        OrganizationMembership::create(['organization_id' => $organization->id, 'user_id' => $requester->id, 'role' => 'instructor', 'status' => 'active']);
        $profile = InstructorProfile::create(['user_id' => $requester->id, 'public_name' => 'Иван Петров']);
        $record = SubstituteRequest::create(['requester_user_id' => $requester->id, 'requester_profile_id' => $profile->id, 'reason' => 'personal', 'note' => 'PRIVATE NOTE', 'version' => 2, 'closed_at' => now(), 'idempotency_key' => 'declined', 'request_hash' => str_repeat('a', 64)]);
        SubstituteRequestSession::create(['request_id' => $record->id, 'requester_profile_id' => $profile->id, 'session_id' => $session->id, 'status' => 'declined', 'decided_by_user_id' => $decider->id, 'closed_at' => now()]);
        $payload = ['substitute_request_id' => $record->id, 'requester_user_id' => $requester->id, 'user_id' => $requester->id, 'session_ids' => [$session->id]];
        $event = app(TeamLetters::class)->queue('substitute.declined', $payload, CarbonImmutable::now());
        $rows = app(DeliveryRecipients::class)->for($event);
        $this->assertCount(1, $rows);
        $this->assertSame($requester->id, $rows[0]['recipient_user_id']);
        $delivery = EnrollmentEventDelivery::create([...$rows[0], 'event_id' => $event->id]);
        // The final refusal still goes out after the session has begun or changed.
        $this->travelTo('2026-11-04 12:00:00');
        $result = app(ComposeDelivery::class)->handle($delivery);
        $this->assertSame($requester->email, $result['to']);
        $this->assertTrue($result['mail']->team);
        $this->assertNull($result['mail']->replyTo);
        $this->assertSame('Моят график', $result['mail']->button['label']);
        $this->assertStringContainsString('Елена Димитрова', json_encode($result['mail'], JSON_UNESCAPED_UNICODE));
        foreach (['mail.enrollment', 'mail.enrollment-text'] as $view) {
            $this->assertStringNotContainsString('PRIVATE NOTE', view($view, ['mail' => $result['mail']])->render());
        }
        $requester->forceFill(['email_verified_at' => null])->save();
        $this->assertSame(['skip' => 'unverified_email'], app(ComposeDelivery::class)->handle($delivery));
        $requester->forceFill(['email_verified_at' => now()])->save();
        OrganizationMembership::where('user_id', $requester->id)->update(['role' => 'platform_admin']);
        $this->assertSame(['skip' => 'no_recipient'], app(ComposeDelivery::class)->handle($delivery));
        OrganizationMembership::where('user_id', $requester->id)->update(['role' => 'instructor']);
        app(TenantContext::class)->select((string) Str::uuid());
        $this->assertSame(['skip' => 'no_recipient'], app(ComposeDelivery::class)->handle($delivery));
        app(TenantContext::class)->select($organization);
    }

    public function test_decline_outbox_rejects_text_or_invalid_session_identifiers(): void
    {
        $this->visitFixture();
        $valid = ['substitute_request_id' => (string) Str::uuid(), 'requester_user_id' => (string) Str::uuid(), 'user_id' => (string) Str::uuid(), 'session_ids' => [(string) Str::uuid()]];
        foreach ([[...$valid, 'note' => 'private'], [...$valid, 'session_ids' => []], [...$valid, 'session_ids' => ['invalid']], [...$valid, 'session_ids' => array_fill(0, 51, (string) Str::uuid())]] as $payload) {
            try {
                app(TeamLetters::class)->queue('substitute.declined', $payload, CarbonImmutable::now());
                $this->fail('Invalid payload must be refused.');
            } catch (\InvalidArgumentException) {
                $this->assertDatabaseCount('enrollment_events', 0);
            }
        }
    }
}
