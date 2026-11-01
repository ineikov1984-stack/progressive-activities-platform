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
use App\Domain\Organizations\Models\StaffGrant;
use App\Domain\Organizations\Models\StaffMember;
use App\Domain\Organizations\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\VisitOfferFixture;
use Tests\TestCase;

class SubstituteRequestedLettersTest extends TestCase
{
    use RefreshDatabase, VisitOfferFixture;

    public function test_requested_letters_recheck_rights_and_never_copy_the_private_note(): void
    {
        $this->travelTo('2026-11-02 10:00:00');
        [$organization, , , $session] = $this->visitFixture();
        $requester = User::factory()->create();
        $profile = InstructorProfile::create(['user_id' => $requester->id, 'public_name' => 'Иван Петров']);
        $office = User::factory()->create();
        $operator = User::factory()->create();
        OrganizationMembership::create(['organization_id' => $organization->id, 'user_id' => $operator->id, 'role' => 'platform_admin', 'status' => 'active']);
        foreach ([$requester, $office] as $user) {
            OrganizationMembership::create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => 'organization_owner', 'status' => 'active']);
        }
        $request = SubstituteRequest::create(['requester_user_id' => $requester->id, 'requester_profile_id' => $profile->id, 'reason' => 'personal', 'note' => 'PRIVATE SECRET NOTE', 'version' => 1, 'idempotency_key' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64)]);
        SubstituteRequestSession::create(['request_id' => $request->id, 'requester_profile_id' => $profile->id, 'session_id' => $session->id, 'status' => 'open']);
        $event = app(TeamLetters::class)->queue('substitute.requested', ['substitute_request_id' => $request->id, 'requester_user_id' => $requester->id], CarbonImmutable::now());
        $this->assertNull($event->application_id);
        $this->assertNull($event->enrollment_id);
        $this->assertSame(['substitute_request_id' => $request->id, 'requester_user_id' => $requester->id], $event->payload);
        $rows = app(DeliveryRecipients::class)->for($event);
        $this->assertCount(1, $rows);
        $this->assertSame($office->id, $rows[0]['recipient_user_id']);
        $delivery = EnrollmentEventDelivery::create([...$rows[0], 'event_id' => $event->id]);
        $result = app(ComposeDelivery::class)->handle($delivery);
        $this->assertSame($office->email, $result['to']);
        $this->assertTrue($result['mail']->team);
        $this->assertNull($result['mail']->replyTo);
        $this->assertStringNotContainsString('PRIVATE SECRET NOTE', json_encode($result['mail'], JSON_UNESCAPED_UNICODE));
        $this->assertContains('Има бележка — вижте я в заявката.', $result['mail']->paragraphs);
        foreach (['mail.enrollment', 'mail.enrollment-text'] as $view) {
            $rendered = view($view, ['mail' => $result['mail']])->render();
            $this->assertStringNotContainsString('PRIVATE SECRET NOTE', $rendered);
            $this->assertStringNotContainsString('Ana Ivanova', $rendered);
        }
        $office->forceFill(['email_verified_at' => null])->save();
        $this->assertSame('unverified_email', app(DeliveryRecipients::class)->for($event)[0]['skipped_reason']);
        $this->assertSame(['skip' => 'unverified_email'], app(ComposeDelivery::class)->handle($delivery));
        $office->forceFill(['email_verified_at' => now()])->save();
        foreach (['withdrawn', 'expired'] as $status) {
            SubstituteRequestSession::where('request_id', $request->id)->update(['status' => $status, 'closed_at' => now()]);
            $this->assertSame(['skip' => 'superseded'], app(ComposeDelivery::class)->handle($delivery));
        }
        SubstituteRequestSession::where('request_id', $request->id)->update(['status' => 'open', 'closed_at' => null]);
        $this->travelTo('2026-11-03 17:01:00 Europe/Sofia');
        $this->assertSame(['skip' => 'superseded'], app(ComposeDelivery::class)->handle($delivery));
        $this->travelTo('2026-11-02 10:00:00');
        app(TenantContext::class)->select((string) Str::uuid());
        $this->assertSame(['skip' => 'no_recipient'], app(ComposeDelivery::class)->handle($delivery));
        app(TenantContext::class)->select($organization);
        DB::enableQueryLog();
        DB::flushQueryLog();
        app(DeliveryRecipients::class)->for($event);
        $before = count(DB::getQueryLog());
        foreach (range(1, 8) as $number) {
            $user = User::factory()->create();
            OrganizationMembership::create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => 'organization_admin', 'status' => 'active']);
        }
        DB::flushQueryLog();
        $this->assertCount(9, app(DeliveryRecipients::class)->for($event));
        $this->assertSame($before, count(DB::getQueryLog()));
        DB::disableQueryLog();
        $membership = OrganizationMembership::where('user_id', $office->id)->firstOrFail();
        $membership->update(['role' => 'instructor']);
        $person = StaffMember::create(['user_id' => $office->id, 'full_name' => 'Офис', 'created_by' => $requester->id]);
        $person->forceFill(['access_scope' => 'all'])->save();
        $this->assertSame(['skip' => 'no_recipient'], app(ComposeDelivery::class)->handle($delivery));
        foreach (['schedule.manage', 'team.assign'] as $ability) {
            StaffGrant::create(['staff_member_id' => $person->id, 'ability' => $ability, 'granted_by' => $requester->id, 'granted_at' => now()]);
        }
        $this->assertSame($office->email, app(ComposeDelivery::class)->handle($delivery)['to']);
        StaffGrant::where('staff_member_id', $person->id)->where('ability', 'team.assign')->update(['revoked_at' => now(), 'revoked_by' => $requester->id, 'revoked_reason' => 'removed']);
        $this->assertSame(['skip' => 'no_recipient'], app(ComposeDelivery::class)->handle($delivery));
        $membership->update(['role' => 'organization_owner']);
        foreach (range(1, 22) as $number) {
            $extra = $session->replicate();
            $extra->starts_at = $session->starts_at->addDays($number);
            $extra->ends_at = $session->ends_at->addDays($number);
            $extra->save();
            SubstituteRequestSession::create(['request_id' => $request->id, 'requester_profile_id' => $profile->id, 'session_id' => $extra->id, 'status' => 'open']);
        }
        $result = app(ComposeDelivery::class)->handle($delivery);
        $this->assertCount(20, array_filter($result['mail']->facts, fn ($fact) => $fact['label'] === 'Занимание'));
        $this->assertContains('И още 3 занимания — вижте заявката.', $result['mail']->paragraphs);
        OrganizationMembership::where('user_id', '!=', $requester->id)->update(['status' => 'inactive']);
        $this->assertSame(['skip' => 'no_recipient'], app(ComposeDelivery::class)->handle($delivery));
        $this->assertSame('no_recipient', app(DeliveryRecipients::class)->for($event)[0]['skipped_reason']);
    }

    public function test_outbox_rejects_text_payloads(): void
    {
        $this->visitFixture();
        $this->expectException(\InvalidArgumentException::class);
        app(TeamLetters::class)->queue('substitute.requested', ['substitute_request_id' => (string) Str::uuid(), 'requester_user_id' => (string) Str::uuid(), 'note' => 'secret'], CarbonImmutable::now());
    }
}
