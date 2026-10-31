<?php

namespace Tests\Feature\Enrollment;

use App\Domain\Catalog\Models\InstructorProfile;
use App\Domain\Catalog\Models\OrganizationCatalogProfile;
use App\Domain\Catalog\Models\Session;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\EnrollmentApplication;
use App\Domain\Enrollment\Models\EnrollmentEvent;
use App\Domain\Enrollment\Models\EnrollmentEventDelivery;
use App\Domain\Enrollment\Models\EnrollmentPause;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\Organization;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CapturingMailSender;
use Tests\Support\ScheduleChangeFixture;
use Tests\TestCase;

/**
 * The letters of "Отмени" and "Възстанови" (docs/product/schedule-changes.md, т. 5.5, 6; PR 4а): one per family and
 * one per teacher, each line checked again when the letter is written, and the families that never heard of a
 * cancellation told that the session takes place. "Шах · Вторник" on 3, 10, 17 and 24 November and "Шах · Четвъртък" on
 * 5, 12, 19 and 26 November, each at 17:00 in Sofia; Ани for the period (Мария's), Вики for one visit on the 3rd
 * (Петя's). Monday 2 November, 12:00 in Sofia. Every name is synthetic.
 */
class ScheduleChangeLettersTest extends TestCase
{
    use RefreshDatabase;
    use ScheduleChangeFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->travelTo('2026-11-02 10:00:00');
    }

    public function test_a_cancellation_tells_each_family_once_without_private_details_and_invites_the_visits_to_another_day(): void
    {
        $f = $this->letterFixture();
        $trial = $this->visitOn($f, $f['session'], 'Гери');

        $this->changeAsOwner($f, 'cancel', $f['session'], ['reason' => 'Треньорът е болен.'])->assertCreated();
        $sent = $this->deliverLetters();

        // Ани's family.
        $letters = $sent->to($this->emailOf($f, $f['enrollment']));
        $this->assertCount(1, $letters);
        $mail = $letters[0];
        $this->assertSame('Заниманието по Шах на 3 ноември няма да се проведе', $mail->envelope()->subject);
        $this->assertSame('info@school.example', $mail->envelope()->replyTo[0]->address ?? null);
        $html = CapturingMailSender::html($mail);
        foreach (['Здравейте, Мария,', 'Заниманието на Ани в Шах · Вторник във вторник, 3 ноември, 17:00, няма да се проведе.',
            'Следващо занимание', 'вторник, 10 ноември · 17:00–18:00'] as $expected) {
            $this->assertStringContainsString($expected, $html);
        }
        // Never a surname, class, a sum — and not the reason, which is not for the parents.
        foreach (['Иванова', '3 клас', 'EUR', 'болен'] as $private) {
            $this->assertStringNotContainsString($private, $html);
            $this->assertStringNotContainsString($private, CapturingMailSender::text($mail));
        }

        // Гери's trial: choose another day — up to five, the next ones with a place in the groups of "Шах".
        $invitation = $sent->to($this->emailOf($f, $trial))[0];
        $this->assertSame('Пробното занимание на Гери на 3 ноември се отменя — изберете друг ден', $invitation->envelope()->subject);
        $html = CapturingMailSender::html($invitation);
        $this->assertStringContainsString('Пробното занимание на Гери по Шах във вторник, 3 ноември, 17:00, няма да се проведе. Изберете друг ден:', $html);
        foreach (['четвъртък, 5 ноември, 17:00 · Четвъртък · 10 свободни места', 'вторник, 10 ноември, 17:00 · Вторник · 9 свободни места',
            'четвъртък, 19 ноември, 17:00 · Четвъртък · 10 свободни места', 'Виж свободните дни',
            '/o/'.$f['slug'].'/activities/'.$f['activity']->slug, 'Отговорете на този имейл с деня, който ви е удобен.'] as $expected) {
            $this->assertStringContainsString($expected, $html);
        }
        $this->assertStringNotContainsString('24 ноември', $html);
        $this->assertSame(5, substr_count(CapturingMailSender::text($invitation), 'Свободен ден:'));

        // Вики's single visit: no price for a single visit is open, so the team offers a day.
        $single = $sent->to($this->emailOf($f, $f['visit']))[0];
        $this->assertSame('Еднократното посещение на Вики на 3 ноември се отменя — изберете друг ден', $single->envelope()->subject);
        $this->assertStringContainsString('Отговорете на този имейл и екипът ще ви предложи друг ден.', CapturingMailSender::html($single));
        $this->assertCount(3, $sent->sent);
    }

    public function test_the_reason_goes_only_when_it_is_meant_for_the_parents_and_the_button_only_with_a_public_page(): void
    {
        $f = $this->letterFixture();
        $this->inTenant($f['organization'], fn () => OrganizationCatalogProfile::query()->update(['is_published' => false]));

        $this->changeAsOwner($f, 'cancel', $f['session'], ['reason' => 'Залата е наводнена.', 'reason_to_parents' => true])->assertCreated();
        $sent = $this->deliverLetters();

        $html = CapturingMailSender::html($sent->to($this->emailOf($f, $f['enrollment']))[0]);
        $this->assertStringContainsString('Обяснение от екипа', $html);
        $this->assertStringContainsString('„Залата е наводнена.“', $html);
        // A school with a closed system has no public page: no button to it.
        $this->assertStringNotContainsString('Виж свободните дни', CapturingMailSender::html($sent->to($this->emailOf($f, $f['visit']))[0]));
    }

    /** The blocker of the architect's review (т. 13): "Отмени" and at once "Възстанови", before any letter went. */
    public function test_cancel_and_restore_before_the_letters_tell_only_what_is_now(): void
    {
        $f = $this->letterFixture();
        $trial = $this->visitOn($f, $f['session'], 'Гери');
        $waiting = $this->waitingFor($f, $f['session'], 'Дани');

        $this->changeAsOwner($f, 'cancel', $f['session'])->assertCreated();
        $this->changeAsOwner($f, 'restore', $f['session'])->assertCreated();
        $sent = $this->deliverLetters();

        // The child of the period: only "ще се проведе", as for a session added — never "няма да се проведе".
        $letters = $sent->to($this->emailOf($f, $f['enrollment']));
        $this->assertCount(1, $letters);
        $this->assertSame('Заниманието по Шах: вторник, 3 ноември, 17:00', $letters[0]->envelope()->subject);
        $html = CapturingMailSender::html($letters[0]);
        $this->assertStringContainsString('Заниманието в Шах · Вторник във вторник, 3 ноември, 17:00, ще се проведе. Очакваме Ани.', $html);
        $this->assertStringNotContainsString('няма да се проведе', $html);

        // The trial: the invitation says the session is back, the visit stays cancelled, the day is among the free ones —
        // and no second letter "денят отново е свободен".
        $letters = $sent->to($this->emailOf($f, $trial));
        $this->assertCount(1, $letters);
        $this->assertSame('Пробното занимание на Гери на 3 ноември е отменено — изберете ден', $letters[0]->envelope()->subject);
        $html = CapturingMailSender::html($letters[0]);
        $this->assertStringContainsString('Заниманието на 3 ноември беше отменено и после върнато. Пробното занимание на Гери остава отменено — изберете ден, и 3 ноември, ако ви е удобен:', $html);
        $this->assertStringContainsString('вторник, 3 ноември, 17:00 · Вторник · 9 свободни места', $html);
        $this->assertStringNotContainsString('Денят отново е свободен', $html);
        $this->assertSame('cancelled', $this->inTenant($f['organization'], fn () => Enrollment::query()->findOrFail($trial->id)->status->value));

        // The application waiting for it: the same.
        $letters = $sent->to($this->emailOf($f, $waiting));
        $this->assertCount(1, $letters);
        $this->assertStringContainsString('Заявката за пробно занимание на Дани остава отказана — изберете ден, и 3 ноември, ако ви е удобен:', CapturingMailSender::html($letters[0]));
        $this->assertSame('rejected', $this->inTenant($f['organization'], fn () => EnrollmentApplication::query()->findOrFail($waiting->id)->status->value));
        $this->assertSame(4, $this->inTenant($f['organization'], fn (): int => EnrollmentEventDelivery::query()->where('skipped_reason', 'superseded')->count()));
    }

    public function test_restore_after_the_letters_says_it_takes_place_after_all_and_that_the_day_is_free(): void
    {
        $f = $this->letterFixture();
        $trial = $this->visitOn($f, $f['session'], 'Гери');
        $this->changeAsOwner($f, 'cancel', $f['session'])->assertCreated();
        $this->deliverLetters();

        $this->travelTo('2026-11-02 11:00:00');
        $this->changeAsOwner($f, 'restore', $f['session'])->assertCreated();
        $sent = $this->deliverLetters();

        $letters = $sent->to($this->emailOf($f, $f['enrollment']));
        $this->assertCount(1, $letters);
        $this->assertSame('Заниманието на 3 ноември все пак ще се проведе', $letters[0]->envelope()->subject);
        $this->assertStringContainsString('Заниманието в Шах · Вторник във вторник, 3 ноември, 17:00, няма да бъде отменено. Очакваме Ани.', CapturingMailSender::html($letters[0]));
        $letters = $sent->to($this->emailOf($f, $trial));
        $this->assertCount(1, $letters);
        $this->assertSame('Заниманието на 3 ноември ще се проведе — денят отново е свободен', $letters[0]->envelope()->subject);
        $this->assertStringContainsString('Денят отново е свободен. Ако искате Гери да дойде тогава и има място, запишете се отново.', CapturingMailSender::html($letters[0]));
    }

    public function test_a_session_cancelled_in_the_editor_comes_back_with_a_letter_to_whom_it_counts_for(): void
    {
        $f = $this->letterFixture();
        $tenth = $this->sessionOn($f, $f['tuesday'], '2026-11-10');
        $this->inTenant($f['organization'], fn () => Session::query()->whereKey($tenth->id)->update(['status' => 'cancelled']));

        $this->changeAsOwner($f, 'restore', $tenth)->assertCreated();
        $letters = $this->deliverLetters()->to($this->emailOf($f, $f['enrollment']));

        $this->assertCount(1, $letters);
        $this->assertSame('Заниманието по Шах: вторник, 10 ноември, 17:00', $letters[0]->envelope()->subject);
        $this->assertStringContainsString('ще се проведе. Очакваме Ани.', CapturingMailSender::html($letters[0]));
    }

    public function test_each_line_is_checked_again_when_the_letter_is_written(): void
    {
        $f = $this->letterFixture();
        $mother = User::query()->findOrFail($this->parentId($f, $f['enrollment']));
        $brother = $this->enrolledIn($f, $f['tuesday'], 'Боян', '2026-10-20', $mother);
        $ema = $this->enrolledIn($f, $f['tuesday'], 'Ема', '2026-10-20');
        $joro = $this->enrolledIn($f, $f['tuesday'], 'Жоро', '2026-10-20');
        $trial = $this->visitOn($f, $f['session'], 'Гери');
        $joroParent = $this->emailOf($f, $joro);

        $this->changeAsOwner($f, 'cancel', $f['session'])->assertCreated();
        $this->inTenant($f['organization'], function () use ($brother, $ema, $joro): void {
            // After the change: Боян's enrollment is cancelled, Ема goes on a pause that day, Жоро's application has
            // another parent.
            Enrollment::query()->whereKey($brother->id)->update(['status' => 'cancelled']);
            EnrollmentPause::query()->create(['enrollment_id' => $ema->id, 'starts_on' => '2026-11-03', 'ends_on' => '2026-11-04', 'reason_category' => 'health']);
            EnrollmentApplication::query()->whereKey($joro->application_id)->update(['parent_user_id' => User::factory()->create()->id]);
        });
        $sent = $this->deliverLetters();

        $letters = $sent->to($mother->email);
        $this->assertCount(1, $letters);
        $html = CapturingMailSender::html($letters[0]);
        $this->assertStringContainsString('Ани', $html);
        $this->assertStringNotContainsString('Боян', $html);
        $this->assertSame([], $sent->to($this->emailOf($f, $ema)));
        $this->assertSame([], $sent->to($joroParent));
        // An invitation for another day stays: the visit really was cancelled.
        $this->assertCount(1, $sent->to($this->emailOf($f, $trial)));
    }

    public function test_the_approval_of_a_visit_cancelled_before_it_went_is_not_sent(): void
    {
        $f = $this->letterFixture();
        $trial = $this->visitOn($f, $f['session'], 'Гери');
        $this->inTenant($f['organization'], fn () => EnrollmentEvent::query()->create([
            'type' => 'enrollment.application_approved', 'application_id' => $trial->application_id, 'enrollment_id' => $trial->id,
            'payload' => ['status' => 'approved'], 'occurred_at' => now(),
        ]));

        $this->changeAsOwner($f, 'cancel', $f['session'])->assertCreated();
        $letters = $this->deliverLetters()->to($this->emailOf($f, $trial));

        $this->assertCount(1, $letters);
        $this->assertStringContainsString('изберете друг ден', $letters[0]->envelope()->subject);
    }

    public function test_the_teachers_hear_of_their_session_without_the_names_of_the_children(): void
    {
        $f = $this->letterFixture();
        $lead = $this->memberOf($f['organization'], 'instructor');
        $assistant = $this->memberOf($f['organization'], 'instructor');
        $unconfirmed = $this->memberOf($f['organization'], 'instructor');
        $unconfirmed->forceFill(['email_verified_at' => null])->save();
        $profiles = [];
        foreach ([$lead, $assistant, $unconfirmed] as $user) {
            $profiles[] = $this->profileOf($f['organization'], $this->personFor($f['organization'], $user, teaches: true));
        }
        $this->inTenant($f['organization'], function () use ($f, $profiles): void {
            // Without a login: a profile only.
            $nobody = InstructorProfile::query()->create(['public_name' => 'Без вход']);
            foreach ($profiles as $index => $profile) {
                $f['tuesday']->assignments()->attach($profile->id, ['role' => $index === 0 ? 'lead' : 'assistant', 'version' => 1]);
            }
            foreach (Session::query()->where('group_id', $f['tuesday']->id)->get() as $session) {
                $session->instructors()->sync([
                    $profiles[0]->id => ['role' => 'lead'], $profiles[1]->id => ['role' => 'assistant'],
                    $profiles[2]->id => ['role' => 'assistant'], $nobody->id => ['role' => 'assistant'],
                ]);
            }
        });

        $this->changeAsOwner($f, 'cancel', $f['session'])->assertCreated();
        $this->assertSame(3, $this->inTenant($f['organization'], fn (): int => EnrollmentEvent::query()->where('type', 'schedule.teacher_sessions_changed')->count()));
        $sent = $this->deliverLetters();

        $letters = $sent->to($lead->email);
        $this->assertCount(1, $letters);
        $this->assertSame('Вашето занимание Шах · Вторник на 3 ноември е отменено', $letters[0]->envelope()->subject);
        $html = CapturingMailSender::html($letters[0]);
        foreach (['Заниманието Шах · Вторник във вторник, 3 ноември, 17:00, няма да се проведе.', 'Деца', 'Моят график', '/dashboard/schedule?organization='.$f['organization']->id] as $expected) {
            $this->assertStringContainsString($expected, $html);
        }
        foreach (['Ани', 'Вики', 'Иванова'] as $private) {
            $this->assertStringNotContainsString($private, $html);
        }
        $this->assertCount(1, $sent->to($assistant->email));
        $this->assertSame([], $sent->to($unconfirmed->email));
        $this->assertSame('unverified_email', $this->inTenant($f['organization'], fn () => EnrollmentEventDelivery::query()
            ->where('recipient_user_id', $unconfirmed->id)->value('skipped_reason')));

        // Back: the teachers from the assignments hear it will take place.
        $this->travelTo('2026-11-02 11:00:00');
        $this->changeAsOwner($f, 'restore', $f['session'])->assertCreated();
        $letters = $this->deliverLetters()->to($lead->email);
        $this->assertCount(1, $letters);
        $this->assertSame('Вашето занимание Шах · Вторник на 3 ноември ще се проведе', $letters[0]->envelope()->subject);

        // Cancelled and back before any letter: only that it takes place.
        $tenth = $this->sessionOn($f, $f['tuesday'], '2026-11-10');
        $this->changeAsOwner($f, 'cancel', $tenth)->assertCreated();
        $this->changeAsOwner($f, 'restore', $tenth)->assertCreated();
        $letters = $this->deliverLetters()->to($lead->email);
        $this->assertCount(1, $letters);
        $this->assertSame('Вашето занимание Шах · Вторник на 10 ноември ще се проведе', $letters[0]->envelope()->subject);
    }

    public function test_a_family_without_a_confirmed_address_gets_nothing_and_the_office_is_told(): void
    {
        $f = $this->letterFixture();
        $ema = $this->enrolledIn($f, $f['tuesday'], 'Ема', '2026-10-20', User::factory()->unverified()->create());

        $this->changeAsOwner($f, 'cancel', $f['session'])->assertCreated()->assertJsonPath('letters.without_verified_email', 1);
        $sent = $this->deliverLetters();

        $this->assertSame([], $sent->to($this->emailOf($f, $ema)));
        // Outside inTenant: a nested one clears the organization before the outer query runs.
        $parentId = $this->parentId($f, $ema);
        $this->assertSame('unverified_email', $this->inTenant($f['organization'], fn () => EnrollmentEventDelivery::query()
            ->where('recipient_user_id', $parentId)->value('skipped_reason')));
    }

    public function test_answers_go_to_the_activitys_address_and_without_any_the_letter_says_once_to_call(): void
    {
        $f = $this->letterFixture();
        $this->inTenant($f['organization'], fn () => $f['chess']->forceFill(['contact_email' => 'chess@school.example'])->save());

        $this->changeAsOwner($f, 'cancel', $f['session'])->assertCreated();
        $mail = $this->deliverLetters()->to($this->emailOf($f, $f['enrollment']))[0];
        $this->assertSame('chess@school.example', $mail->envelope()->replyTo[0]->address ?? null);
        $this->assertStringContainsString('Имате въпрос? Отговорете на този имейл', CapturingMailSender::text($mail));

        // Neither the activity's address nor the organization's: no Reply-To, and one line to call — also with an invitation.
        $this->inTenant($f['organization'], fn () => $f['chess']->forceFill(['contact_email' => null])->save());
        Organization::query()->withoutGlobalScopes()->whereKey($f['organization']->id)->update(['public_contact_email' => null]);
        $next = $this->sessionOn($f, $f['tuesday'], '2026-11-10');
        $trial = $this->visitOn($f, $next, 'Гери');
        $this->changeAsOwner($f, 'cancel', $next)->assertCreated();
        $invitation = $this->deliverLetters()->to($this->emailOf($f, $trial))[0];
        $this->assertSame([], $invitation->envelope()->replyTo);
        foreach ([CapturingMailSender::html($invitation), CapturingMailSender::text($invitation)] as $body) {
            $this->assertSame(1, substr_count($body, 'Не отговаряйте на този имейл — свържете се с Школа Звезда по телефона.'));
            $this->assertStringNotContainsString('Отговорете на този имейл', $body);
        }
    }

    /**
     * The fixture with a published, coloured profile, a contact address and a price for a trial in the activity.
     *
     * @return array<string, mixed>
     */
    private function letterFixture(): array
    {
        $f = $this->rightsFixture();
        Organization::query()->withoutGlobalScopes()->whereKey($f['organization']->id)->update(['public_contact_email' => 'info@school.example']);
        $profile = $this->inTenant($f['organization'], function () use ($f): OrganizationCatalogProfile {
            $f['chess']->priceOptions()->create(['enrollment_mode' => 'trial', 'unit' => 'session', 'amount_cents' => 1000, 'currency' => 'EUR', 'is_available' => true]);

            return OrganizationCatalogProfile::query()->create(['public_name' => 'Школа Звезда', 'accent_color' => '#216653', 'is_published' => true]);
        });

        return [...$f, 'slug' => (string) $profile->slug];
    }

    /** @param  array<string, mixed>  $f */
    private function parentId(array $f, Enrollment|EnrollmentApplication $record): string
    {
        $applicationId = $record instanceof Enrollment ? $record->application_id : $record->id;

        return $this->inTenant($f['organization'], fn (): string => (string) EnrollmentApplication::query()->whereKey($applicationId)->value('parent_user_id'));
    }

    /** @param  array<string, mixed>  $f */
    private function emailOf(array $f, Enrollment|EnrollmentApplication $record): string
    {
        return (string) User::query()->whereKey($this->parentId($f, $record))->value('email');
    }
}
