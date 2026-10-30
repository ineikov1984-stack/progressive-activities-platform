<?php

namespace Tests\Feature\Organizations;

use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Organizations\Models\StaffMember;
use App\Domain\Organizations\Tenancy\TenantContext;
use App\Mail\TeamInvitationMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Tests\TestCase;

/** The invitation that works and the roles (docs/product/team.md, т. 4, 7, 9, 11 — PR 2б). */
class TeamInvitationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_owner_invites_a_person_and_the_e_mail_goes_out_after_the_commit(): void
    {
        Mail::fake();
        [$owner, $organization] = $this->team();
        $person = $this->person($organization, null);
        $this->withHeader('X-Organization-Id', $organization->id);

        $response = $this->invite($owner, $person, ['role' => 'instructor', 'email' => ' Petar@Example.test'])->assertCreated()
            ->assertJsonPath('invitation.status', 'pending')
            ->assertJsonPath('invitation.role', 'instructor')
            ->assertJsonPath('invitation.staff_member_id', $person->id)
            ->assertJsonMissingPath('invitation.token_hash')
            ->assertJsonMissingPath('invitation.copy_token_hash')
            ->assertJsonMissingPath('token');
        $link = (string) $response->json('link');
        $copy = $this->tokenOf($response);
        $mailed = $this->mailedToken('petar@example.test');
        // The token is in the fragment, never in the path; the e-mail and the inviter get different tokens.
        $this->assertSame(config('platform.web_url').'/invite#'.$copy, $link);
        $this->assertSame(64, strlen($copy));
        $this->assertSame(64, strlen($mailed));
        $this->assertNotSame($copy, $mailed);
        $this->assertDatabaseHas('invitations', [
            'staff_member_id' => $person->id, 'email' => 'petar@example.test', 'role' => 'instructor',
            'token_hash' => hash('sha256', $mailed), 'copy_token_hash' => hash('sha256', $copy),
        ]);
        // The person had no e-mail: the invitation's is kept on them.
        $this->assertSame('petar@example.test', DB::table('staff_members')->where('id', $person->id)->value('email'));

        Mail::assertSent(TeamInvitationMail::class, fn (TeamInvitationMail $mail): bool => $mail->hasTo('petar@example.test')
            && $mail->url === config('platform.web_url').'/invite#'.$mailed && $mail->role === 'Преподавател' && $mail->organization === 'Школа');
        Mail::assertNothingQueued();
        $mail = Mail::sent(TeamInvitationMail::class)->first();
        $this->assertNotInstanceOf(ShouldQueue::class, $mail);
        $html = $mail->render();
        foreach (['Приеми поканата', 'Покана от', 'Поканата важи до', 'Преподавател', 'Собственик Школа', '/invite#'.$mailed] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $this->assertStringNotContainsString($copy, $html);

        // The audit keeps ids and the role, never the address or a token.
        $metadata = (string) DB::table('audit_events')->where('action', 'team.invitation_sent')->value('metadata');
        $this->assertStringContainsString('instructor', $metadata);
        $this->assertStringNotContainsString('petar', Str::lower($metadata));
        foreach ([$copy, $mailed, hash('sha256', $copy), hash('sha256', $mailed)] as $secret) {
            $this->assertStringNotContainsString($secret, $metadata);
        }
        $this->assertStringNotContainsString('petar', Str::lower((string) DB::table('audit_events')->where('action', 'team.member_updated')->value('metadata')));
    }

    public function test_the_e_mail_is_sent_at_once_and_nothing_waits_in_a_queue(): void
    {
        config(['queue.default' => 'database']);
        [$owner, $organization] = $this->team();
        $person = $this->person($organization, 'petar@example.test');
        $this->withHeader('X-Organization-Id', $organization->id);
        $this->invite($owner, $person)->assertCreated();

        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
        $messages = app('mail.manager')->mailer()->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $email = $messages->first()->getOriginalMessage();
        $this->assertSame('petar@example.test', $email->getTo()[0]->getAddress());
        $this->assertSame('Покана за екипа на Школа', $email->getSubject());
        $this->assertStringContainsString('Приеми поканата', (string) $email->getHtmlBody());
        $this->assertStringContainsString('/invite#', (string) $email->getTextBody());
    }

    public function test_a_mail_server_error_keeps_the_invitation_and_logs_no_address(): void
    {
        Mail::extend('refusing', fn (): AbstractTransport => new class extends AbstractTransport
        {
            protected function doSend(SentMessage $message): void
            {
                throw new TransportException('Expected response code 250 but got 550 <petar@example.test>', 550);
            }

            public function __toString(): string
            {
                return 'refusing';
            }
        });
        config(['mail.mailers.refusing' => ['transport' => 'refusing'], 'mail.default' => 'refusing']);
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $entry) use (&$logged): void {
            $logged[] = $entry;
        });
        [$owner, $organization] = $this->team();
        $person = $this->person($organization, 'petar@example.test');
        $this->withHeader('X-Organization-Id', $organization->id);

        $copy = $this->tokenOf($this->invite($owner, $person)->assertCreated());

        $this->assertDatabaseHas('invitations', ['staff_member_id' => $person->id, 'copy_token_hash' => hash('sha256', $copy)]);
        $warnings = array_values(array_filter($logged, fn (MessageLogged $entry): bool => $entry->level === 'warning'));
        $this->assertCount(1, $warnings);
        $this->assertSame('TransportException:550', $warnings[0]->context['error']);
        foreach ($logged as $entry) {
            $this->assertStringNotContainsString('petar', json_encode([$entry->message, $entry->context], JSON_THROW_ON_ERROR));
            $this->assertStringNotContainsString('/invite', json_encode([$entry->message, $entry->context], JSON_THROW_ON_ERROR));
        }
        // Sending again is the way out.
        $this->invite($owner, $person)->assertOk();
    }

    public function test_sending_again_gives_new_links_and_the_old_ones_stop_working(): void
    {
        Mail::fake();
        [$owner, $organization] = $this->team();
        $person = $this->person($organization, 'petar@example.test');
        $this->withHeader('X-Organization-Id', $organization->id);

        $firstCopy = $this->tokenOf($this->invite($owner, $person)->assertCreated());
        $firstMail = $this->mailedToken('petar@example.test');
        $secondCopy = $this->tokenOf($this->invite($owner, $person, ['role' => 'coordinator'])->assertOk()
            ->assertJsonPath('invitation.role', 'coordinator'));
        $secondMail = $this->mailedToken('petar@example.test');

        $this->assertNotSame($firstCopy, $secondCopy);
        $this->assertNotSame($firstMail, $secondMail);
        $this->assertSame(1, DB::table('invitations')->count());
        foreach ([$firstCopy, $firstMail] as $old) {
            $this->lookup($old)->assertNotFound()->assertJsonPath('message', 'Поканата не е намерена.');
        }
        foreach ([$secondCopy, $secondMail] as $current) {
            $this->lookup($current)->assertOk()->assertJsonPath('invitation.status', 'pending')->assertJsonPath('invitation.role_label', 'Координатор');
        }
        Mail::assertSentCount(2);
        $sent = DB::table('audit_events')->where('action', 'team.invitation_sent')->pluck('metadata')
            ->map(fn (mixed $metadata): array => json_decode((string) $metadata, true, flags: JSON_THROW_ON_ERROR));
        $this->assertSame([false, true], $sent->pluck('resent')->sort()->values()->all());
    }

    public function test_an_old_invitation_for_the_address_is_taken_over_and_another_persons_refuses(): void
    {
        Mail::fake();
        [$owner, $organization] = $this->team();
        $this->withHeader('X-Organization-Id', $organization->id);
        // The old dashboard form, without a person.
        $old = $this->actingAs($owner)->postJson('/api/v1/invitations', ['email' => 'petar@example.test', 'role' => 'viewer'])
            ->assertCreated()->json('token');
        $person = $this->person($organization, 'petar@example.test');
        $token = $this->tokenOf($this->invite($owner, $person)->assertOk());

        $this->assertSame(1, DB::table('invitations')->count());
        $this->assertDatabaseHas('invitations', ['staff_member_id' => $person->id, 'token_hash' => hash('sha256', $token)]);
        $this->lookup($old)->assertNotFound();
        // The same address for another person while this invitation is active.
        $this->invite($owner, $this->person($organization, null, 'Друг човек'), ['role' => 'viewer', 'email' => 'petar@example.test'])
            ->assertConflict();
    }

    public function test_who_may_invite_with_which_role(): void
    {
        Mail::fake();
        [$owner, $organization] = $this->team();
        $admin = $this->member($organization, 'organization_admin', 'Администратор');
        $coordinator = $this->member($organization, 'coordinator', 'Координатор');
        $person = $this->person($organization, 'petar@example.test');
        $this->withHeader('X-Organization-Id', $organization->id);

        $this->invite($owner, $person, ['role' => 'organization_owner'])->assertForbidden();
        $this->invite($owner, $person, ['role' => 'platform_admin'])->assertForbidden();
        $this->invite($admin, $person, ['role' => 'organization_admin'])->assertForbidden();
        $this->invite($coordinator, $person, ['role' => 'viewer'])->assertForbidden();
        $this->invite($owner, $person, ['role' => 'chief'])->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->assertDatabaseCount('invitations', 0);

        $this->invite($admin, $person, ['role' => 'finance'])->assertCreated();
        $this->invite($owner, $person, ['role' => 'organization_admin'])->assertOk()->assertJsonPath('invitation.role', 'organization_admin');
        Mail::assertSentCount(2);
    }

    public function test_an_archived_person_a_person_with_a_login_and_a_person_without_an_address_are_not_invited(): void
    {
        Mail::fake();
        [$owner, $organization] = $this->team();
        $teacher = $this->member($organization, 'instructor', 'Учител', 'teacher@example.test');
        $archived = $this->person($organization, 'archived@example.test', 'Архивиран');
        DB::table('staff_members')->where('id', $archived->id)->update(['archived_at' => now()]);
        $noAddress = $this->person($organization, null, 'Без имейл');
        $this->withHeader('X-Organization-Id', $organization->id);

        $this->invite($owner, $archived)->assertUnprocessable()->assertJsonPath('errors.member.0', 'Човекът е архивиран.');
        $this->invite($owner, $this->personOf($organization, $teacher))->assertUnprocessable()
            ->assertJsonPath('errors.member.0', 'Този човек вече влиза в системата.');
        $this->invite($owner, $noAddress)->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'Въведете имейл, на който да изпратим поканата.');
        $this->invite($owner, $noAddress, ['role' => 'viewer', 'email' => 'Teacher@example.test'])->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'Човек с този имейл вече е в екипа.');

        $this->assertDatabaseCount('invitations', 0);
        $this->assertNull(DB::table('staff_members')->where('id', $noAddress->id)->value('email'));
        Mail::assertNothingSent();
    }

    public function test_the_invitation_page_shows_only_what_it_needs_without_a_login(): void
    {
        Mail::fake();
        [$owner, $organization] = $this->team();
        $person = $this->person($organization, 'petar@example.test');
        $this->withHeader('X-Organization-Id', $organization->id);
        $copy = $this->tokenOf($this->invite($owner, $person));
        $mailed = $this->mailedToken('petar@example.test');
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        User::factory()->create(['email' => 'petar@example.test']);

        foreach ([$copy, $mailed] as $token) {
            $page = $this->lookup($token)->assertOk()
                ->assertJsonPath('invitation.organization_name', 'Школа')
                ->assertJsonPath('invitation.role', 'instructor')
                ->assertJsonPath('invitation.role_label', 'Преподавател')
                ->assertJsonPath('invitation.status', 'pending')
                // Whether an address has a profile is not told: the page offers both and relies on the 409.
                ->assertJsonMissingPath('invitation.has_account')
                ->assertJsonMissingPath('invitation.email');
            $this->assertStringNotContainsString('petar', (string) $page->getContent());
            $this->assertStringNotContainsString(hash('sha256', $token), (string) $page->getContent());
        }

        DB::table('invitations')->update(['expires_at' => now()->subMinute()]);
        $this->lookup($copy)->assertOk()->assertJsonPath('invitation.status', 'expired');
        $this->lookup(Str::random(64))->assertNotFound()->assertJsonPath('message', 'Поканата не е намерена.');
        $this->lookup('short')->assertNotFound()->assertJsonPath('message', 'Поканата не е намерена.');
        $this->postJson('/api/v1/invitations/lookup')->assertUnprocessable()->assertJsonValidationErrors('token');
    }

    #[TestGroup('tenant-isolation')]
    public function test_a_signed_in_person_joins_the_organization_of_the_invitation_not_of_the_session(): void
    {
        Mail::fake();
        [$owner, $organization] = $this->team();
        [, $other] = $this->team('Друго училище');
        $teacher = $this->member($other, 'instructor', 'Петър', 'petar@example.test');
        $person = $this->person($organization, 'petar@example.test');
        $this->withHeader('X-Organization-Id', $organization->id);
        $copy = $this->tokenOf($this->invite($owner, $person));
        $mailed = $this->mailedToken('petar@example.test');
        $this->flushHeaders();

        // The inviter's link joins an existing profile with the invitation's address.
        $accepted = $this->actingAs($teacher)->withSession(['active_organization_id' => $other->id])
            ->postJson('/api/v1/invitations/join', ['token' => $copy])->assertOk()
            ->assertJsonPath('organization.id', $organization->id)
            ->assertJsonPath('membership.role', 'instructor')
            ->assertSessionHas('active_organization_id', $organization->id);
        $this->assertDatabaseHas('organization_memberships', [
            'organization_id' => $organization->id, 'user_id' => $teacher->id, 'role' => 'instructor', 'status' => 'active',
        ]);
        $this->assertSame($teacher->id, DB::table('staff_members')->where('id', $person->id)->value('user_id'));
        $this->assertDatabaseHas('invitations', ['staff_member_id' => $person->id, 'accepted_by' => $teacher->id, 'active_key' => null]);
        // The next request works in the organization of the invitation.
        $this->getJson('/api/v1/context/organization')->assertOk()->assertJsonPath('organization.id', $organization->id);

        // Accepting again, also with the e-mail's link, is the same membership.
        $this->postJson('/api/v1/invitations/join', ['token' => $mailed])->assertOk()->assertJsonPath('membership.id', $accepted->json('membership.id'));
        $this->assertSame(1, DB::table('organization_memberships')->where('organization_id', $organization->id)->where('user_id', $teacher->id)->count());
        $this->assertSame(1, DB::table('staff_members')->where('organization_id', $organization->id)->where('user_id', $teacher->id)->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'team.invitation_accepted')->count());
        $metadata = (string) DB::table('audit_events')->where('action', 'team.invitation_accepted')->value('metadata');
        $this->assertStringContainsString('instructor', $metadata);
        $this->assertStringNotContainsString('petar', Str::lower($metadata));
        $this->assertStringNotContainsString($copy, $metadata);
        // The other organization is untouched.
        $this->assertDatabaseHas('organization_memberships', ['organization_id' => $other->id, 'user_id' => $teacher->id, 'role' => 'instructor']);
    }

    public function test_an_expired_foreign_archived_or_taken_invitation_is_refused(): void
    {
        Mail::fake();
        [$owner, $organization] = $this->team();
        $archivedPerson = $this->person($organization, 'archived@example.test', 'Архивиран');
        $linkedPerson = $this->person($organization, 'linked@example.test', 'Свързан');
        $twicePerson = $this->person($organization, 'twice@example.test', 'Втори запис');
        $this->withHeader('X-Organization-Id', $organization->id);
        $expired = $this->tokenOf($this->invite($owner, $this->person($organization, 'expired@example.test', 'Изтекла')));
        $archived = $this->tokenOf($this->invite($owner, $archivedPerson));
        $linked = $this->tokenOf($this->invite($owner, $linkedPerson));
        $twice = $this->tokenOf($this->invite($owner, $twicePerson));
        $this->flushHeaders();

        DB::table('invitations')->where('email', 'expired@example.test')->update(['expires_at' => now()->subMinute()]);
        DB::table('staff_members')->where('id', $archivedPerson->id)->update(['archived_at' => now()]);
        DB::table('staff_members')->where('id', $linkedPerson->id)->update(['user_id' => User::factory()->create()->id]);
        $twiceUser = User::factory()->create(['email' => 'twice@example.test']);
        $this->person($organization, null, 'Стар запис', $twiceUser);

        $this->actingAs(User::factory()->create(['email' => 'expired@example.test']))->postJson('/api/v1/invitations/join', ['token' => $expired])
            ->assertGone()->assertJsonPath('message', 'Поканата е изтекла. Поискайте нова покана от организацията.');
        $this->actingAs(User::factory()->create(['email' => 'other@example.test']))->postJson('/api/v1/invitations/join', ['token' => $archived])
            ->assertForbidden();
        $this->actingAs(User::factory()->create(['email' => 'archived@example.test']))->postJson('/api/v1/invitations/join', ['token' => $archived])
            ->assertUnprocessable()->assertJsonValidationErrors('invitation');
        $this->actingAs(User::factory()->create(['email' => 'linked@example.test']))->postJson('/api/v1/invitations/join', ['token' => $linked])
            ->assertConflict()->assertJsonPath('message', 'Поканата е свързана с друг профил.');
        $this->actingAs($twiceUser)->postJson('/api/v1/invitations/join', ['token' => $twice])->assertConflict();
        $this->postJson('/api/v1/invitations/join', ['token' => Str::random(64)])->assertNotFound();

        $this->assertSame(0, DB::table('invitations')->whereNotNull('accepted_at')->count());
        $this->assertSame(1, DB::table('organization_memberships')->where('organization_id', $organization->id)->count());
    }

    public function test_a_new_person_chooses_a_password_and_joins(): void
    {
        Mail::fake();
        [$owner, $organization] = $this->team();
        $person = $this->person($organization, 'new@example.test', 'Нов човек');
        $this->withHeader('X-Organization-Id', $organization->id);
        $this->invite($owner, $person)->assertCreated();
        $token = $this->mailedToken('new@example.test');
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $url = '/api/v1/invitations/register';

        $this->postJson($url, ['token' => $token, 'name' => 'Нов човек', 'password' => 'kratka', 'password_confirmation' => 'kratka'])
            ->assertUnprocessable()->assertJsonPath('errors.password.0', 'Паролата трябва да е поне 10 знака.');
        $this->postJson($url, ['token' => $token, 'name' => 'Нов човек', 'password' => 'dalga-parola-1', 'password_confirmation' => 'druga-parola-1'])
            ->assertUnprocessable()->assertJsonPath('errors.password.0', 'Паролите не съвпадат.');
        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'new@example.test']);

        // The address is the invitation's; one in the request is ignored.
        $this->postJson($url, [
            'token' => $token, 'name' => 'Нов човек', 'email' => 'someone@example.test',
            'password' => 'dalga-parola-1', 'password_confirmation' => 'dalga-parola-1',
        ])->assertCreated()
            ->assertJsonPath('organization.id', $organization->id)
            ->assertJsonPath('membership.role', 'instructor')
            ->assertSessionHas('active_organization_id', $organization->id);
        $user = User::query()->where('email', 'new@example.test')->firstOrFail();
        $this->assertNotNull($user->email_verified_at);
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseMissing('users', ['email' => 'someone@example.test']);
        $this->assertSame($user->id, DB::table('staff_members')->where('id', $person->id)->value('user_id'));
        $this->getJson('/api/v1/context/organization')->assertOk()->assertJsonPath('organization.id', $organization->id);

        // Signed in: no second profile.
        $this->postJson($url, ['token' => $token, 'name' => 'Пак', 'password' => 'dalga-parola-1', 'password_confirmation' => 'dalga-parola-1'])->assertConflict();
        $this->assertSame(1, DB::table('users')->where('email', 'new@example.test')->count());
    }

    public function test_the_inviters_link_does_not_make_a_profile(): void
    {
        Mail::fake();
        [$owner, $organization] = $this->team();
        $this->withHeader('X-Organization-Id', $organization->id);
        // The inviter sees this link: with it they could give someone else's address a password of their own.
        $copy = $this->tokenOf($this->invite($owner, $this->person($organization, 'ivan@example.test', 'Иван')));
        // The old dashboard form showed its one token to the inviter too.
        $old = $this->actingAs($owner)->postJson('/api/v1/invitations', ['email' => 'maria@example.test', 'role' => 'viewer'])
            ->assertCreated()->json('token');
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        foreach ([$copy, $old] as $token) {
            $this->postJson('/api/v1/invitations/register', ['token' => $token, 'name' => 'Чужд', 'password' => 'dalga-parola-1', 'password_confirmation' => 'dalga-parola-1'])
                ->assertForbidden()->assertJsonPath('message', 'За нов профил отворете връзката от писмото.');
        }
        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'ivan@example.test']);
        $this->assertDatabaseMissing('users', ['email' => 'maria@example.test']);
        $this->assertSame(0, DB::table('invitations')->whereNotNull('accepted_at')->count());
    }

    public function test_an_address_with_a_profile_logs_in_to_accept(): void
    {
        Mail::fake();
        [$owner, $organization] = $this->team();
        $this->withHeader('X-Organization-Id', $organization->id);
        $this->invite($owner, $this->person($organization, 'known@example.test'))->assertCreated();
        $token = $this->mailedToken('known@example.test');
        User::factory()->create(['email' => 'known@example.test']);
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $body = ['name' => 'Някой', 'password' => 'dalga-parola-1', 'password_confirmation' => 'dalga-parola-1'];

        $this->postJson('/api/v1/invitations/register', ['token' => $token, ...$body])
            ->assertConflict()->assertJsonPath('message', 'Вече имате профил. Влезте, за да приемете поканата.');
        $this->postJson('/api/v1/invitations/register', ['token' => Str::random(64), ...$body])->assertNotFound();
        $this->postJson('/api/v1/invitations/register', $body)->assertUnprocessable()->assertJsonValidationErrors('token');
        $this->assertGuest();
        $this->assertSame(1, DB::table('users')->where('email', 'known@example.test')->count());
        $this->assertNull(DB::table('invitations')->value('accepted_at'));
    }

    public function test_the_role_of_a_person_with_a_login_changes_by_the_rules(): void
    {
        [$owner, $organization] = $this->team();
        $admin = $this->member($organization, 'organization_admin', 'Администратор');
        $teacher = $this->personOf($organization, $this->member($organization, 'instructor', 'Учител'));
        $coordinator = $this->member($organization, 'coordinator', 'Координатор');
        $ownerPerson = $this->personOf($organization, $owner);
        $adminPerson = $this->personOf($organization, $admin);
        $noLogin = $this->person($organization, null, 'Без вход');
        $this->withHeader('X-Organization-Id', $organization->id);
        $role = fn (User $actor, StaffMember $person, string $to, int $version = 1): TestResponse => $this->actingAs($actor)
            ->postJson('/api/v1/team/members/'.$person->id.'/role', ['role' => $to, 'version' => $version]);

        $role($owner, $teacher, 'coordinator')->assertOk()->assertJsonPath('member.role', 'coordinator')->assertJsonPath('member.version', 2);
        $role($owner, $teacher, 'viewer', 1)->assertConflict();
        $audit = json_decode((string) DB::table('audit_events')->where('action', 'team.role_changed')->value('metadata'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['instructor', 'coordinator'], [$audit['from'], $audit['to']]);

        // Nobody changes their own role, so the only owner stays one.
        $role($owner, $ownerPerson, 'organization_admin')->assertUnprocessable()
            ->assertJsonPath('errors.role.0', 'Не можете да смените собствената си роля.');
        // The administrator changes neither the owner nor anyone to administrator; never the operator's role.
        $role($admin, $ownerPerson, 'viewer')->assertForbidden();
        $role($admin, $teacher, 'organization_admin', 2)->assertForbidden();
        $role($admin, $teacher, 'finance', 2)->assertOk()->assertJsonPath('member.role', 'finance');
        $role($owner, $adminPerson, 'platform_admin')->assertForbidden();
        $role($owner, $noLogin, 'viewer')->assertUnprocessable()->assertJsonValidationErrors('role');
        $role($coordinator, $teacher, 'viewer', 3)->assertForbidden();

        // A second owner may step the first down; the one left is not changed by the other.
        $role($owner, $adminPerson, 'organization_owner')->assertOk();
        $role($admin, $ownerPerson, 'organization_admin')->assertOk()->assertJsonPath('member.role', 'organization_admin');
        $role($owner, $adminPerson, 'viewer', 2)->assertForbidden();
        $role($admin, $adminPerson, 'viewer', 2)->assertUnprocessable();
        $this->assertSame([$admin->id], DB::table('organization_memberships')->where('organization_id', $organization->id)
            ->where('role', 'organization_owner')->pluck('user_id')->all());
        $this->assertSame(4, DB::table('audit_events')->where('action', 'team.role_changed')->count());
    }

    #[TestGroup('tenant-isolation')]
    public function test_another_organizations_people_are_not_found_on_any_member_route(): void
    {
        Mail::fake();
        [$owner, $organization] = $this->team();
        [$foreignOwner, $other] = $this->team('Друго училище');
        $foreign = $this->person($other, 'foreign@example.test', 'Чужд човек');
        $foreignLogin = $this->personOf($other, $foreignOwner);
        $this->withHeader('X-Organization-Id', $organization->id);
        $base = '/api/v1/team/members/';

        $this->actingAs($owner)->getJson($base.$foreign->id)->assertNotFound();
        $this->actingAs($owner)->patchJson($base.$foreign->id, ['version' => 1, 'full_name' => 'Х'])->assertNotFound();
        $this->actingAs($owner)->postJson($base.$foreign->id.'/archive', ['version' => 1])->assertNotFound();
        $this->actingAs($owner)->postJson($base.$foreign->id.'/instructor-profile')->assertNotFound();
        $this->actingAs($owner)->postJson($base.$foreign->id.'/invitation', ['role' => 'instructor'])->assertNotFound();
        $this->actingAs($owner)->postJson($base.$foreignLogin->id.'/role', ['role' => 'viewer', 'version' => 1])->assertNotFound();
        // Naming the other organization is no way in either.
        $this->withHeader('X-Organization-Id', $other->id)->actingAs($owner)
            ->postJson($base.$foreign->id.'/invitation', ['role' => 'instructor'])->assertNotFound();

        $this->assertDatabaseCount('invitations', 0);
        Mail::assertNothingSent();
        $this->assertDatabaseHas('organization_memberships', ['user_id' => $foreignOwner->id, 'role' => 'organization_owner']);
        $this->assertSame('Чужд човек', DB::table('staff_members')->where('id', $foreign->id)->value('full_name'));
    }

    /**
     * The whole way under the application's database role (RLS on): the invitation of A is found without any
     * organization in the setting only through `invitation_tenant`, and joining or registering writes only in A.
     */
    #[TestGroup('tenant-isolation')]
    public function test_postgresql_application_role_invites_looks_up_and_joins_only_in_the_invitations_organization(): void
    {
        try {
            DB::connection('pgsql_admin')->getPdo();
        } catch (\Throwable) {
            $this->markTestSkipped('PostgreSQL is required for the application-role invitation check.');
        }
        Artisan::call('migrate', ['--database' => 'pgsql_admin', '--force' => true]);
        Mail::fake();
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection('pgsql_admin');
        DB::beginTransaction();
        try {
            // As the owner of the database: two organizations, their owners and people.
            [$owner, $first] = $this->team('Школа А');
            [$otherOwner, $second] = $this->team('Школа Б');
            $teacherPerson = $this->person($first, 'petar@example.test');
            $newcomerPerson = $this->person($first, 'new@example.test', 'Нов човек');
            $foreignPerson = $this->person($second, 'foreign@example.test', 'Чужд човек');
            $teacher = User::factory()->create(['email' => 'petar@example.test']);
            app(TenantContext::class)->clear();

            DB::statement('SET LOCAL ROLE progressive_app');
            $this->assertSame('progressive_app', DB::selectOne('SELECT current_user AS role')->role);
            $this->withHeader('X-Organization-Id', $second->id);
            $this->invite($otherOwner, $foreignPerson, ['role' => 'viewer'])->assertCreated();
            $this->withHeader('X-Organization-Id', $first->id);
            $copy = $this->tokenOf($this->invite($owner, $teacherPerson)->assertCreated());
            $this->invite($owner, $newcomerPerson)->assertCreated();
            $mailed = $this->mailedToken('new@example.test');
            $this->flushHeaders();

            // No organization in the setting: no invitation is readable, yet the link finds its own.
            $this->blankTenant();
            $this->assertSame(0, DB::table('invitations')->count());
            $this->lookup($copy)->assertOk()->assertJsonPath('invitation.organization_name', 'Школа А');
            $this->blankTenant();
            $this->actingAs($teacher)->postJson('/api/v1/invitations/join', ['token' => $copy])->assertOk()
                ->assertJsonPath('organization.id', $first->id);
            $this->blankTenant();
            $this->app['auth']->forgetGuards();
            $this->postJson('/api/v1/invitations/register', [
                'token' => $mailed, 'name' => 'Нов човек', 'password' => 'dalga-parola-1', 'password_confirmation' => 'dalga-parola-1',
            ])->assertCreated()->assertJsonPath('organization.id', $first->id);

            // In A's setting, B's invitation stays out of reach.
            DB::select("SELECT set_config('app.current_organization_id', ?, true)", [$first->id]);
            $this->assertSame(0, DB::table('invitations')->where('organization_id', $second->id)->count());
            $this->assertSame(0, DB::table('invitations')->where('organization_id', $second->id)->update(['expires_at' => now()]));

            // As the owner of the database again: memberships and people only in A, the audit written.
            DB::statement('RESET ROLE');
            $newcomer = User::query()->where('email', 'new@example.test')->firstOrFail();
            foreach ([$teacher, $newcomer] as $user) {
                $this->assertSame([$first->id], DB::table('organization_memberships')->where('user_id', $user->id)->pluck('organization_id')->all());
                $this->assertSame([$first->id], DB::table('staff_members')->where('user_id', $user->id)->pluck('organization_id')->all());
            }
            $this->assertSame(2, DB::table('audit_events')->where('organization_id', $first->id)->where('action', 'team.invitation_accepted')->count());
            $this->assertSame(0, DB::table('audit_events')->where('organization_id', $second->id)->where('action', 'team.invitation_accepted')->count());
            $this->assertNull(DB::table('invitations')->where('organization_id', $second->id)->value('accepted_at'));
        } finally {
            DB::rollBack();
            DB::setDefaultConnection($original);
            app(TenantContext::class)->clear();
        }
    }

    /** @return array{User, Organization} an owner with a person of the team, as the backfill gives */
    private function team(string $name = 'Школа'): array
    {
        $owner = User::factory()->create(['name' => 'Собственик '.$name]);
        $organization = Organization::query()->withoutGlobalScopes()->create(['name' => $name, 'slug' => Str::slug($name).'-'.Str::random(6), 'status' => 'active']);
        OrganizationMembership::query()->withoutGlobalScopes()->create(['organization_id' => $organization->id, 'user_id' => $owner->id, 'role' => 'organization_owner', 'status' => 'active']);
        $this->person($organization, null, $owner->name, $owner);

        return [$owner, $organization];
    }

    /** A member with a login and their person of the team. */
    private function member(Organization $organization, string $role, string $name, ?string $email = null): User
    {
        $user = User::factory()->create(['name' => $name, ...($email === null ? [] : ['email' => $email])]);
        OrganizationMembership::query()->withoutGlobalScopes()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => $role, 'status' => 'active']);
        $this->person($organization, null, $name, $user);

        return $user;
    }

    private function person(Organization $organization, ?string $email, string $name = 'Петър Стоянов', ?User $user = null): StaffMember
    {
        app(TenantContext::class)->select($organization);
        $person = StaffMember::query()->create(['full_name' => $name, 'phone' => '+359888123456', 'email' => $email, 'version' => 1]);
        if ($user !== null) {
            $person->forceFill(['user_id' => $user->id])->save();
        }
        app(TenantContext::class)->clear();

        return $person;
    }

    private function personOf(Organization $organization, User $user): StaffMember
    {
        return StaffMember::query()->withoutGlobalScopes()->where('organization_id', $organization->id)->where('user_id', $user->id)->firstOrFail();
    }

    /** @param  array<string, mixed>  $body */
    private function invite(User $actor, StaffMember $person, array $body = ['role' => 'instructor']): TestResponse
    {
        return $this->actingAs($actor)->postJson('/api/v1/team/members/'.$person->id.'/invitation', $body);
    }

    /** The token of the inviter's link for copying. */
    private function tokenOf(TestResponse $response): string
    {
        return Str::after((string) $response->json('link'), '/invite#');
    }

    /** The token of the latest e-mail sent to the address. */
    private function mailedToken(string $address): string
    {
        $mail = Mail::sent(TeamInvitationMail::class, fn (TeamInvitationMail $mail): bool => $mail->hasTo($address))->last();
        $this->assertInstanceOf(TeamInvitationMail::class, $mail);

        return Str::after($mail->url, '/invite#');
    }

    private function lookup(string $token): TestResponse
    {
        return $this->postJson('/api/v1/invitations/lookup', ['token' => $token]);
    }

    /** No organization in the PostgreSQL setting, as at the start of a request without a tenant. */
    private function blankTenant(): void
    {
        DB::select("SELECT set_config('app.current_organization_id', '', true)");
    }
}
