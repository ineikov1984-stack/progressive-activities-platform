<?php

namespace Tests\Feature\Enrollment;

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Enrollment\Models\EnrollmentApplication;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\StaffGrant;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use Tests\Support\TeamRightsFixture;
use Tests\TestCase;

/**
 * "Покажи телефона" (docs/product/team-rights.md, т. 6): the owner sees the parent's phone at once; everyone else who
 * sees contacts in the group gets it hidden and shows it on request — never cached, written down with identifiers only,
 * at most 30 a hour; outside the window "not found", without the right 403, another organization's record 404. The
 * lists never carry a phone or an e-mail.
 */
class ContactRevealTest extends TestCase
{
    use RefreshDatabase;
    use TeamRightsFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        // Monday 2 November 2026, 12:00 in Sofia.
        $this->travelTo('2026-11-02 10:00:00');
    }

    public function test_the_administrator_shows_the_contact_and_the_audit_keeps_identifiers_only(): void
    {
        $f = $this->rightsFixture();
        $admin = $this->memberOf($f['organization'], 'organization_admin');
        $parent = $this->parentOf($f);
        $this->signInTo($admin, $f['organization']);

        $details = $this->getJson("/api/v1/enrollment/enrollments/{$f['enrollment']->id}")->assertOk()
            ->assertJsonPath('contact_status', 'hidden')->assertJsonPath('contact.has_phone', true)->assertJsonPath('contact.phone', null);
        $this->assertStringNotContainsString((string) $parent->phone, (string) $details->getContent());
        $this->assertStringNotContainsString($parent->email, (string) $details->getContent());

        $shown = $this->postJson("/api/v1/enrollment/enrollments/{$f['enrollment']->id}/contact/reveal", ['from' => 'details'])->assertOk()
            ->assertJsonPath('contact.name', 'Мария Иванова')
            ->assertJsonPath('contact.phone', $parent->phone)
            ->assertJsonPath('contact.email', $parent->email);
        $this->assertStringContainsString('no-store', (string) $shown->headers->get('Cache-Control'));

        $event = AuditEvent::query()->where('action', 'enrollment.contact_revealed')->sole();
        $this->assertSame(['enrollment', $f['enrollment']->id], [$event->subject_type, $event->subject_id]);
        $this->assertSame([
            'child_id' => $f['enrollment']->child_id, 'group_id' => $f['tuesday']->id, 'parent' => 'user:'.$parent->id,
            'role' => 'organization_admin', 'grant_id' => null, 'from' => 'details',
        ], $event->metadata);
        $stored = (string) json_encode($event->getAttributes(), JSON_UNESCAPED_UNICODE);
        foreach ([(string) $parent->phone, ltrim((string) $parent->phone, '+'), $parent->email, 'Мария', 'Иванова', 'Ани'] as $secret) {
            $this->assertStringNotContainsString($secret, $stored);
        }

        // Whom it was shown to is in the child's details for those who manage the team, and in the person's history.
        $this->getJson("/api/v1/enrollment/enrollments/{$f['enrollment']->id}")->assertJsonPath('contact_reveals.0.by', $admin->name)
            ->assertJsonPath('contact_reveals.0.from', 'details');
        $adminPerson = $this->personFor($f['organization'], $admin);
        $this->signInTo($f['owner'], $f['organization'])->getJson("/api/v1/team/members/{$adminPerson->id}/access")->assertOk()
            ->assertJsonPath('access.history.0.action', 'enrollment.contact_revealed')->assertJsonPath('access.history.0.group_label', 'Шах · Вторник');
    }

    public function test_the_owner_sees_the_contact_at_once_and_is_not_counted(): void
    {
        $f = $this->rightsFixture();
        $parent = $this->parentOf($f);
        $this->signInTo($f['owner'], $f['organization']);

        $this->getJson("/api/v1/enrollment/applications/{$f['waiting']->id}")->assertOk()
            ->assertJsonPath('contact_status', 'shown')->assertJsonPath('contact.name', 'Петя Петрова');
        $this->getJson("/api/v1/enrollment/enrollments/{$f['enrollment']->id}")->assertOk()
            ->assertJsonPath('contact_status', 'shown')->assertJsonPath('contact.phone', $parent->phone);
        for ($i = 0; $i < 31; $i++) {
            $this->postJson("/api/v1/enrollment/applications/{$f['waiting']->id}/contact/reveal")->assertOk();
        }
    }

    public function test_more_than_thirty_a_hour_waits(): void
    {
        $f = $this->rightsFixture();
        $coordinator = $this->memberOf($f['organization'], 'coordinator');
        $this->signInTo($coordinator, $f['organization']);
        $path = "/api/v1/enrollment/applications/{$f['waiting']->id}/contact/reveal";

        for ($i = 0; $i < 30; $i++) {
            $this->postJson($path)->assertOk();
        }
        $this->postJson($path)->assertStatus(429)->assertJsonPath('message', 'Показахте много контакти за кратко време. Опитайте по-късно.');
        $this->assertSame(30, AuditEvent::query()->where('action', 'enrollment.contact_revealed')->count());

        $this->travelTo('2026-11-02 11:00:01');
        $this->postJson($path)->assertOk();
    }

    public function test_more_than_a_hundred_a_day_waits_too(): void
    {
        $f = $this->rightsFixture();
        $coordinator = $this->memberOf($f['organization'], 'coordinator');
        // A hundred already shown today, within the hours' limits.
        for ($i = 0; $i < 100; $i++) {
            RateLimiter::hit('contact-reveal:day:'.$f['organization']->id.':'.$coordinator->id, 86400);
        }
        $path = "/api/v1/enrollment/applications/{$f['waiting']->id}/contact/reveal";

        $this->signInTo($coordinator, $f['organization'])->postJson($path)->assertStatus(429)
            ->assertJsonPath('message', 'Показахте много контакти за кратко време. Опитайте по-късно.');
        $this->assertSame(0, AuditEvent::query()->where('action', 'enrollment.contact_revealed')->count());

        $this->travelTo('2026-11-03 10:00:01');
        $this->postJson($path)->assertOk();
    }

    #[TestGroup('tenant-isolation')]
    public function test_without_the_right_outside_the_window_and_in_another_organization(): void
    {
        $f = $this->rightsFixture();
        $viewer = $this->memberOf($f['organization'], 'viewer');
        $this->signInTo($viewer, $f['organization'])->postJson("/api/v1/enrollment/enrollments/{$f['enrollment']->id}/contact/reveal")
            ->assertForbidden()->assertJsonPath('message', 'Нямате право да виждате контактите на родителите в тази група.');

        // Ended more than 90 days ago: outside the window.
        $this->inTenant($f['organization'], fn () => $f['enrollment']->forceFill(['status' => 'ended', 'starts_on' => '2026-01-10', 'ends_on' => '2026-07-01'])->save());
        $coordinator = $this->memberOf($f['organization'], 'coordinator');
        $this->signInTo($coordinator, $f['organization'])->postJson("/api/v1/enrollment/enrollments/{$f['enrollment']->id}/contact/reveal")
            ->assertNotFound()->assertJsonPath('message', 'Контактът вече не е достъпен.');

        $other = $this->rightsFixture();
        $this->signInTo($coordinator, $f['organization'])->postJson("/api/v1/enrollment/applications/{$other['waiting']->id}/contact/reveal")->assertNotFound();
        $this->assertSame(0, AuditEvent::query()->where('action', 'enrollment.contact_revealed')->count());
    }

    #[TestGroup('tenant-isolation')]
    public function test_a_teacher_with_the_added_right_shows_it_in_their_own_group_only(): void
    {
        $f = $this->rightsFixture();
        $teacher = $this->memberOf($f['organization'], 'instructor');
        $person = $this->personFor($f['organization'], $teacher, teaches: true);
        $profile = $this->profileOf($f['organization'], $person);
        $this->inTenant($f['organization'], function () use ($f, $person, $profile): void {
            $f['tuesday']->assignments()->attach($profile->id, ['role' => 'lead', 'version' => 1]);
            StaffGrant::query()->create(['staff_member_id' => $person->id, 'ability' => 'contacts.view', 'granted_by' => $f['owner']->id, 'granted_at' => now()->subDay()]);
        });
        $this->signInTo($teacher, $f['organization']);

        // On the teacher's roster: a button, never the parent's name.
        $roster = $this->getJson("/api/v1/enrollment/groups/{$f['tuesday']->id}/roster")->assertOk()
            ->assertJsonPath('enrollments.0.can_reveal_contact', true)->assertJsonPath('enrollments.0.enrollment_id', $f['enrollment']->id);
        $this->assertStringNotContainsString('Мария', (string) $roster->getContent());

        $this->postJson("/api/v1/enrollment/enrollments/{$f['enrollment']->id}/contact/reveal", ['from' => 'roster'])->assertOk()
            ->assertJsonPath('contact.name', 'Мария Иванова');
        $grant = $this->inTenant($f['organization'], fn () => StaffGrant::query()->where('ability', 'contacts.view')->value('id'));
        $this->assertSame(['instructor', $grant, 'roster'], array_values(array_intersect_key(
            AuditEvent::query()->where('action', 'enrollment.contact_revealed')->sole()->metadata, array_flip(['role', 'grant_id', 'from']),
        )));

        // An application, even waiting for the teacher's own group: a teacher sees the enrolled children, not the
        // applications, without "enrollment.view" there.
        $this->postJson("/api/v1/enrollment/applications/{$f['waiting']->id}/contact/reveal")->assertForbidden()
            ->assertJsonPath('message', 'Нямате право да виждате контактите на родителите в тази група.');
        // Another group of the organization: refused.
        $outside = $this->inTenant($f['organization'], fn () => $this->rightsApplication(
            $f['owner'], $f['owner']->children()->create(['first_name' => 'Дани', 'last_name' => 'Колев'])->id, $f['thursday']->id, $f['price']->id, 'waitlisted',
        ));
        $this->postJson("/api/v1/enrollment/applications/{$outside->id}/contact/reveal")->assertForbidden();
        $this->assertSame(1, AuditEvent::query()->where('action', 'enrollment.contact_revealed')->count());
    }

    public function test_the_lists_never_carry_a_phone_or_an_e_mail_and_the_events_stay_with_the_team_managers(): void
    {
        $f = $this->rightsFixture();
        $coordinator = $this->memberOf($f['organization'], 'coordinator');
        $parents = User::query()->whereIn('name', ['Мария Иванова', 'Петя Петрова'])->get();
        $this->signInTo($coordinator, $f['organization']);
        $this->postJson("/api/v1/enrollment/applications/{$f['waiting']->id}/contact/reveal")->assertOk();

        foreach ([
            '/api/v1/enrollment/applications?status=waitlisted', '/api/v1/enrollment/groups', "/api/v1/enrollment/groups/{$f['tuesday']->id}/roster",
        ] as $list) {
            $text = (string) $this->getJson($list)->assertOk()->getContent();
            foreach ($parents as $parent) {
                $this->assertStringNotContainsString($parent->email, $text, $list);
                $this->assertStringNotContainsString(ltrim((string) $parent->phone, '+'), $text, $list);
            }
        }
        // The coordinator sees the team but not who was shown which contact; the owner does.
        $this->assertNotContains('enrollment.contact_revealed', array_column($this->getJson('/api/v1/audit-events')->assertOk()->json('events'), 'action'));
        $this->assertContains('enrollment.contact_revealed', array_column(
            $this->signInTo($f['owner'], $f['organization'])->getJson('/api/v1/audit-events')->assertOk()->json('events'), 'action',
        ));
    }

    /** @param array<string, mixed> $f */
    private function parentOf(array $f): User
    {
        return $this->inTenant($f['organization'], fn (): User => User::query()->findOrFail(
            EnrollmentApplication::query()->findOrFail($f['enrollment']->application_id)->parent_user_id,
        ));
    }
}
