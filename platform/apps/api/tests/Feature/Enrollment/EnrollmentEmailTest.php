<?php

namespace Tests\Feature\Enrollment;

use App\Domain\Catalog\Models\Activity;
use App\Domain\Catalog\Models\Group;
use App\Domain\Catalog\Models\Location;
use App\Domain\Catalog\Models\OrganizationCatalogProfile;
use App\Domain\Enrollment\Delivery\DeliverEvents;
use App\Domain\Enrollment\Delivery\ExpandEvents;
use App\Domain\Enrollment\Delivery\MailSender;
use App\Domain\Enrollment\Models\Child;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\EnrollmentApplication;
use App\Domain\Enrollment\Models\EnrollmentEvent;
use App\Domain\Enrollment\Models\EnrollmentEventDelivery;
use App\Domain\Enrollment\Models\EnrollmentPause;
use App\Domain\Enrollment\Models\EnrollmentSessionException;
use App\Domain\Enrollment\Models\EnrollmentTransition;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Organizations\Tenancy\TenantContext;
use App\Mail\EnrollmentMail;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;
use Throwable;

/** The enrollment emails (docs/product/enrollment-contacts-and-emails.md, т. 6 and 10; ADR 0005). Synthetic people only. */
class EnrollmentEmailTest extends TestCase
{
    use RefreshDatabase;

    private RecordingMailSender $mailer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->travelTo('2026-11-02 10:00:00');
        $this->mailer = new RecordingMailSender;
        $this->app->instance(MailSender::class, $this->mailer);
    }

    public function test_an_approval_tells_the_parent_when_and_where_without_private_details(): void
    {
        $f = $this->fixture();
        $coordinator = $this->member($f['organization'], 'coordinator');
        $this->actingAs($coordinator)->withSession(['active_organization_id' => $f['organization']->id])
            ->postJson("/api/v1/enrollment/applications/{$f['application']->id}/review", ['decision' => 'approve', 'version' => 1])
            ->assertOk();

        $stats = app(DeliverEvents::class)->run();

        $this->assertSame(1, $stats['sent']);
        $this->assertCount(1, $this->mailer->sent);
        [$to, $mail] = [$this->mailer->sent[0]['to'], $this->mailer->sent[0]['mail']];
        $this->assertSame($f['parent']->email, $to);
        $envelope = $mail->envelope();
        $this->assertSame('Записахме Ния в „Шахмат · Група 2“', $envelope->subject);
        $this->assertSame('Школа Звезда чрез Progressive Activities', $envelope->from?->name);
        $this->assertSame('info@zvezda.example', $envelope->replyTo[0]->address ?? null);
        $html = $this->html($mail);
        foreach (['Здравейте, Мария,', 'Първо занимание', 'сряда, 4 ноември · 17:30–18:30', 'Всяка сряда · 17:30–18:30', 'Зала 1, ул. Пример 1, София', 'Вход от двора.', 'Как да стигнете', '#216653',
            '/email-icons/calendar.png', '/email-icons/repeat.png', '/email-icons/pin.png'] as $expected) {
            $this->assertStringContainsString($expected, $html);
        }
        // Never the surname, class, birth date, the parent's note, a phone or an internal note.
        foreach (['Иванова', '2 клас', '2018', 'Обадете се след', '0888', 'Петрова'] as $private) {
            $this->assertStringNotContainsString($private, $html);
            $this->assertStringNotContainsString($private, $this->text($mail));
        }
        $delivery = $this->withTenant($f['organization'], fn () => EnrollmentEventDelivery::query()->sole());
        $this->assertSame('sent', $delivery->status);
        $this->assertSame(1, $delivery->attempts);
        $this->assertNotNull($delivery->sent_at);
        $this->assertStringStartsWith($delivery->id.'@', (string) $mail->headers()->messageId);
    }

    public function test_the_team_hears_of_a_new_application_without_the_child_or_the_parent(): void
    {
        $f = $this->fixture();
        $this->event($f, 'enrollment.application_submitted', ['status' => 'submitted']);

        app(DeliverEvents::class)->run();

        $byAddress = collect($this->mailer->sent)->keyBy('to');
        $this->assertSame(['info@zvezda.example', 'maria@example.com'], $byAddress->keys()->sort()->values()->all());
        $parent = $byAddress[$f['parent']->email]['mail'];
        $this->assertSame('Получихме заявката за Ния — „Шахмат · Група 2“', $parent->envelope()->subject);
        $team = $byAddress['info@zvezda.example']['mail'];
        $this->assertSame('Нова заявка за „Шахмат · Група 2“', $team->envelope()->subject);
        $html = $this->html($team);
        foreach (['Отвори заявката', '/dashboard/enrollments?organization='.$f['organization']->id, '10 свободни места от 10', '1 заявка в организацията'] as $expected) {
            $this->assertStringContainsString($expected, $html);
        }
        // A shared inbox may be read by people who may not see contacts.
        foreach (['Ния', 'Иванова', 'Мария', $f['parent']->email] as $private) {
            $this->assertStringNotContainsString($private, $html);
            $this->assertStringNotContainsString($private, $this->text($team));
        }
    }

    public function test_automatic_approval_sends_only_the_approval(): void
    {
        $f = $this->fixture();
        $enrollment = $this->withTenant($f['organization'], function () use ($f): Enrollment {
            $f['application']->forceFill(['status' => 'approved'])->save();

            return Enrollment::query()->create([
                'application_id' => $f['application']->id, 'child_id' => $f['child']->id, 'group_id' => $f['group']->id,
                'price_option_id' => $f['application']->price_option_id, 'scope' => 'full_period', 'status' => 'active', 'starts_on' => '2026-11-02',
            ]);
        });
        $this->event($f, 'enrollment.application_submitted', ['status' => 'submitted']);
        $this->event($f, 'enrollment.application_approved', ['status' => 'approved'], $enrollment);

        app(DeliverEvents::class)->run();

        $this->assertSame(['Записахме Ния в „Шахмат · Група 2“'], array_map(fn (array $sent): string => $sent['mail']->envelope()->subject, $this->mailer->sent));
        $this->assertSame(2, $this->withTenant($f['organization'], fn () => EnrollmentEventDelivery::query()->where('skipped_reason', 'superseded')->count()));
    }

    public function test_an_unconfirmed_address_gets_nothing(): void
    {
        $f = $this->fixture(verified: false);
        $this->event($f, 'enrollment.application_rejected', ['status' => 'rejected']);
        $this->withTenant($f['organization'], fn () => $f['application']->forceFill(['status' => 'rejected'])->save());

        app(DeliverEvents::class)->run();

        $this->assertSame([], $this->mailer->sent);
        $this->assertSame('unverified_email', $this->withTenant($f['organization'], fn () => EnrollmentEventDelivery::query()->sole()->skipped_reason));
    }

    public function test_a_failure_waits_and_stops_after_five_attempts_without_logging_the_address(): void
    {
        $f = $this->fixture();
        $this->withTenant($f['organization'], fn () => $f['application']->forceFill(['status' => 'rejected'])->save());
        $this->event($f, 'enrollment.application_rejected', ['status' => 'rejected']);
        $this->mailer->failWith = new TransportException('550 mailbox unavailable for '.$f['parent']->email, 550);
        $logged = [];
        Log::listen(function ($message) use (&$logged): void {
            $logged[] = $message->message.' '.json_encode($message->context);
        });
        $delivery = fn (): EnrollmentEventDelivery => $this->withTenant($f['organization'], fn () => EnrollmentEventDelivery::query()->sole());

        app(DeliverEvents::class)->run();
        $this->assertSame('pending', $delivery()->status);
        $this->assertSame(1, $delivery()->attempts);
        $this->assertSame('TransportException:550', $delivery()->last_error);
        $this->assertSame(now()->addMinute()->toDateTimeString(), $delivery()->next_attempt_at?->toDateTimeString());

        // Not before its time.
        app(DeliverEvents::class)->run();
        $this->assertSame(1, $delivery()->attempts);

        foreach ([1, 5, 30, 120] as $minutes) {
            $this->travel($minutes)->minutes();
            app(DeliverEvents::class)->run();
        }
        $this->assertSame('failed', $delivery()->status);
        $this->assertSame(5, $delivery()->attempts);
        $this->assertSame([], $this->mailer->sent);
        $this->assertStringNotContainsString($f['parent']->email, implode("\n", $logged));
    }

    public function test_an_expired_lease_is_taken_again_with_its_attempt_counted(): void
    {
        $f = $this->fixture();
        $this->withTenant($f['organization'], fn () => $f['application']->forceFill(['status' => 'rejected'])->save());
        $this->event($f, 'enrollment.application_rejected', ['status' => 'rejected']);
        app(ExpandEvents::class)->handle($f['organization']->id);
        // A sender stopped after taking the email.
        $this->withTenant($f['organization'], fn () => EnrollmentEventDelivery::query()->update([
            'status' => 'sending', 'attempts' => 1, 'locked_until' => now()->subMinute(),
        ]));

        app(DeliverEvents::class)->run();

        $delivery = $this->withTenant($f['organization'], fn () => EnrollmentEventDelivery::query()->sole());
        $this->assertSame('sent', $delivery->status);
        $this->assertSame(2, $delivery->attempts);
        $this->assertCount(1, $this->mailer->sent);
    }

    public function test_old_events_and_events_without_an_email_send_nothing(): void
    {
        $f = $this->fixture();
        $this->withTenant($f['organization'], fn () => $f['application']->forceFill(['status' => 'rejected'])->save());
        $old = $this->event($f, 'enrollment.application_rejected', ['status' => 'rejected'], null, null, now()->subHours(73));
        $silent = $this->event($f, 'enrollment.visit_outcome_recorded', ['outcome' => 'attended']);

        app(DeliverEvents::class)->run();

        $this->assertSame([], $this->mailer->sent);
        $this->withTenant($f['organization'], function () use ($old, $silent): void {
            $this->assertSame('too_old', EnrollmentEventDelivery::query()->where('event_id', $old->id)->sole()->skipped_reason);
            $this->assertSame(0, EnrollmentEventDelivery::query()->where('event_id', $silent->id)->count());
            $this->assertNotNull(EnrollmentEvent::query()->findOrFail($silent->id)->expanded_at);
        });
    }

    public function test_an_event_is_expanded_once(): void
    {
        $f = $this->fixture();
        $this->withTenant($f['organization'], fn () => $f['application']->forceFill(['status' => 'rejected'])->save());
        $event = $this->event($f, 'enrollment.application_rejected', ['status' => 'rejected']);

        app(ExpandEvents::class)->handle($f['organization']->id);
        DB::table('enrollment_events')->where('id', $event->id)->update(['expanded_at' => null]);
        app(ExpandEvents::class)->handle($f['organization']->id);

        $this->assertSame(1, DB::table('enrollment_event_deliveries')->where('event_id', $event->id)->count());
    }

    public function test_a_parent_ending_tells_the_team_and_never_repeats_the_parents_words(): void
    {
        $f = $this->fixture();
        $enrollment = $this->withTenant($f['organization'], function () use ($f): Enrollment {
            $f['application']->forceFill(['status' => 'approved'])->save();

            return Enrollment::query()->create([
                'application_id' => $f['application']->id, 'child_id' => $f['child']->id, 'group_id' => $f['group']->id,
                'price_option_id' => $f['application']->price_option_id, 'scope' => 'full_period', 'status' => 'ended',
                'starts_on' => '2026-10-01', 'ends_on' => '2026-11-30',
            ]);
        });
        $byParent = $this->transition($f, $enrollment, $f['parent'], 'Местим се в друг град.');
        $this->event($f, 'enrollment.ended', ['last_day' => '2026-11-30', 'cancelled' => false], $enrollment, $byParent);
        // A pause set by the team: the parent hears of it, without its reason; the team gets nothing.
        $coordinator = $this->member($f['organization'], 'coordinator');
        $byTeam = $this->transition($f, $enrollment, $coordinator, null);
        $this->event($f, 'enrollment.paused', ['starts_on' => '2026-11-09', 'ends_on' => '2026-11-15', 'reason_category' => 'health'], $enrollment, $byTeam);
        app(DeliverEvents::class)->run();

        $subjects = collect($this->mailer->sent)->map(fn (array $sent): string => $sent['to'].' | '.$sent['mail']->envelope()->subject)->sort()->values()->all();
        $this->assertSame([
            'info@zvezda.example | Родител прекрати записване в „Шахмат · Група 2“',
            $f['parent']->email.' | Пауза за Ния: 9–15 ноември',
            $f['parent']->email.' | Последен ден на Ния в „Шахмат · Група 2“: 30 ноември',
        ], $subjects);
        $byTo = collect($this->mailer->sent);
        $ended = $byTo->first(fn (array $sent): bool => str_starts_with($sent['mail']->envelope()->subject, 'Последен ден'))['mail'];
        // The parent knows what they wrote; it may be about health — it stays out of the email.
        $this->assertStringContainsString('30 ноември', $this->html($ended));
        $this->assertStringContainsString('Последно занимание', $this->html($ended));
        $this->assertStringContainsString('сряда, 25 ноември · 17:30–18:30', $this->html($ended));
        $this->assertStringNotContainsString('Местим се', $this->html($ended));
        $pause = $byTo->first(fn (array $sent): bool => str_starts_with($sent['mail']->envelope()->subject, 'Пауза'))['mail'];
        foreach (['от понеделник, 9 ноември до неделя, 15 ноември', 'Връща се', 'сряда, 18 ноември · 17:30–18:30', 'Датите не са верни?'] as $expected) {
            $this->assertStringContainsString($expected, $this->html($pause));
        }
        $this->assertStringNotContainsString('health', $this->html($pause));
        $this->assertStringNotContainsString('Здравословни', $this->html($pause));
        $team = $byTo->firstWhere('to', 'info@zvezda.example')['mail'];
        foreach (['Местим се', 'Ния', 'Мария'] as $private) {
            $this->assertStringNotContainsString($private, $this->html($team));
        }
        $this->assertStringContainsString('view=groups&amp;group='.$f['group']->id, $this->html($team));
        foreach (['Всяка сряда · 17:30–18:30', 'понеделник, 30 ноември', 'се освобождава от 1 декември'] as $expected) {
            $this->assertStringContainsString($expected, $this->html($team));
        }
    }

    public function test_a_session_counted_again_says_when_and_where_and_tells_the_team_which_one(): void
    {
        $f = $this->fixture();
        $enrollment = $this->withTenant($f['organization'], function () use ($f): Enrollment {
            $f['application']->forceFill(['status' => 'approved'])->save();

            return Enrollment::query()->create([
                'application_id' => $f['application']->id, 'child_id' => $f['child']->id, 'group_id' => $f['group']->id,
                'price_option_id' => $f['application']->price_option_id, 'scope' => 'full_period', 'status' => 'active', 'starts_on' => '2026-10-01',
            ]);
        });
        $session = $this->withTenant($f['organization'], fn () => $f['group']->sessions()->orderBy('starts_at')->firstOrFail());
        $byParent = $this->transition($f, $enrollment, $f['parent'], null);
        $this->event($f, 'enrollment.session_restored', ['session_id' => $session->id], $enrollment, $byParent);

        app(DeliverEvents::class)->run();

        $byAddress = collect($this->mailer->sent)->keyBy('to');
        $parent = $byAddress[$f['parent']->email]['mail'];
        $this->assertSame('Заниманието на 4 ноември отново се брои за Ния', $parent->envelope()->subject);
        foreach (['Очакваме Ния на заниманието.', 'сряда, 4 ноември · 17:30–18:30', 'Шахмат · Група 2', 'Зала 1, ул. Пример 1, София', 'Как да стигнете', 'Отговорете на този имейл'] as $expected) {
            $this->assertStringContainsString($expected, $this->html($parent));
        }
        $team = $byAddress['info@zvezda.example']['mail'];
        $this->assertSame('Родител отмени пропуска на 4 ноември в „Шахмат · Група 2“', $team->envelope()->subject);
        foreach (['сряда, 4 ноември · 17:30–18:30', 'Всяка сряда · 17:30–18:30', 'Отвори групата'] as $expected) {
            $this->assertStringContainsString($expected, $this->html($team));
        }
        foreach (['Ния', 'Мария'] as $private) {
            $this->assertStringNotContainsString($private, $this->html($team));
        }
    }

    public function test_a_skip_by_the_team_names_the_session_and_when_the_child_comes_back(): void
    {
        $f = $this->fixture();
        $enrollment = $this->withTenant($f['organization'], function () use ($f): Enrollment {
            $f['application']->forceFill(['status' => 'approved'])->save();

            return Enrollment::query()->create([
                'application_id' => $f['application']->id, 'child_id' => $f['child']->id, 'group_id' => $f['group']->id,
                'price_option_id' => $f['application']->price_option_id, 'scope' => 'full_period', 'status' => 'active', 'starts_on' => '2026-10-01',
            ]);
        });
        $third = $this->withTenant($f['organization'], fn () => $f['group']->sessions()->orderBy('starts_at')->skip(2)->firstOrFail());
        $this->withTenant($f['organization'], fn () => EnrollmentSessionException::query()->create([
            'enrollment_id' => $enrollment->id, 'session_id' => $third->id, 'kind' => 'excluded', 'reason' => 'Състезание в училище.',
        ]));
        $coordinator = $this->member($f['organization'], 'coordinator');
        $this->event($f, 'enrollment.session_excluded', ['session_id' => $third->id], $enrollment, $this->transition($f, $enrollment, $coordinator, 'Състезание в училище.'));

        app(DeliverEvents::class)->run();

        $this->assertCount(1, $this->mailer->sent);
        $mail = $this->mailer->sent[0]['mail'];
        $this->assertSame('Ния пропуска заниманието на 18 ноември', $mail->envelope()->subject);
        $html = $this->html($mail);
        foreach (['Пропуска', 'сряда, 18 ноември · 17:30–18:30', 'Връща се', 'сряда, 25 ноември · 17:30–18:30', '„Състезание в училище.“', 'Грешка ли е?'] as $expected) {
            $this->assertStringContainsString($expected, $html);
        }
        // The session after the skipped one — not the first session of the period.
        $this->assertStringNotContainsString('4 ноември', $html);
    }

    public function test_a_session_skipped_again_before_sending_does_not_say_we_expect_the_child(): void
    {
        $f = $this->fixture();
        $enrollment = $this->activeEnrollment($f);
        $session = $this->withTenant($f['organization'], fn () => $f['group']->sessions()->orderBy('starts_at')->firstOrFail());
        // Restored, then skipped again within the same minute: only the skip is told.
        $this->event($f, 'enrollment.session_restored', ['session_id' => $session->id], $enrollment);
        $this->withTenant($f['organization'], fn () => EnrollmentSessionException::query()->create([
            'enrollment_id' => $enrollment->id, 'session_id' => $session->id, 'kind' => 'excluded', 'reason' => 'Отново не може.',
        ]));
        $this->event($f, 'enrollment.session_excluded', ['session_id' => $session->id], $enrollment);

        app(DeliverEvents::class)->run();

        $this->assertSame(['Ния пропуска заниманието на 4 ноември'], array_map(fn (array $sent): string => $sent['mail']->envelope()->subject, $this->mailer->sent));
        $this->assertSame(1, $this->withTenant($f['organization'], fn () => EnrollmentEventDelivery::query()->where('skipped_reason', 'superseded')->count()));
    }

    public function test_a_cancelled_pause_names_the_pause_and_the_next_session_and_an_early_end_the_return(): void
    {
        $f = $this->fixture();
        $enrollment = $this->activeEnrollment($f);
        [$cancelledPause, $shortenedPause] = $this->withTenant($f['organization'], fn (): array => [
            EnrollmentPause::query()->create(['enrollment_id' => $enrollment->id, 'starts_on' => '2026-11-09', 'ends_on' => '2026-11-15', 'reason_category' => 'holiday', 'cancelled_at' => now()]),
            EnrollmentPause::query()->create(['enrollment_id' => $enrollment->id, 'starts_on' => '2026-11-16', 'ends_on' => '2026-11-17', 'reason_category' => 'holiday']),
        ]);
        $this->event($f, 'enrollment.pause_ended', ['pause_id' => $cancelledPause->id, 'resume_on' => '2026-11-09', 'cancelled' => true], $enrollment);
        app(DeliverEvents::class)->run();
        $cancelled = $this->html($this->mailer->sent[0]['mail']);
        // Which pause, and simply the next session.
        foreach (['Отменена пауза', 'от понеделник, 9 ноември до неделя, 15 ноември', 'Следващо занимание', 'сряда, 4 ноември · 17:30–18:30'] as $expected) {
            $this->assertStringContainsString($expected, $cancelled);
        }

        $this->mailer->sent = [];
        $this->event($f, 'enrollment.pause_ended', ['pause_id' => $shortenedPause->id, 'resume_on' => '2026-11-18', 'cancelled' => false], $enrollment);
        app(DeliverEvents::class)->run();
        $ended = $this->html($this->mailer->sent[0]['mail']);
        foreach (['от понеделник, 16 ноември до вторник, 17 ноември', 'Връща се', 'сряда, 18 ноември · 17:30–18:30'] as $expected) {
            $this->assertStringContainsString($expected, $ended);
        }
    }

    public function test_a_move_names_the_last_session_in_the_old_group_and_the_first_in_the_new_one(): void
    {
        $f = $this->fixture();
        $old = $this->withTenant($f['organization'], function () use ($f): Enrollment {
            $f['application']->forceFill(['status' => 'approved'])->save();

            return Enrollment::query()->create([
                'application_id' => $f['application']->id, 'child_id' => $f['child']->id, 'group_id' => $f['group']->id,
                'price_option_id' => $f['application']->price_option_id, 'scope' => 'full_period', 'status' => 'ended',
                'starts_on' => '2026-10-01', 'ends_on' => '2026-11-22',
            ]);
        });
        [$moved, $application] = $this->withTenant($f['organization'], function () use ($f, $old): array {
            $target = $f['group']->offering->groups()->create([
                'name' => 'Група 3', 'capacity_max' => 10, 'status' => 'published', 'enrollment_approval_mode' => 'manual',
                'location_id' => $f['group']->location_id,
                'schedule_pattern' => ['timezone' => 'Europe/Sofia', 'frequency' => 'weekly', 'slots' => [['weekday' => 1, 'starts_at' => '16:00', 'duration_minutes' => 60]]],
            ]);
            foreach (['2026-11-23', '2026-11-30'] as $day) {
                $target->sessions()->create(['starts_at' => "{$day}T14:00:00Z", 'ends_at' => "{$day}T15:00:00Z", 'status' => 'scheduled']);
            }
            $application = $f['application']->replicate()->fill(['group_id' => $target->id, 'idempotency_key' => fake()->uuid(), 'source' => 'staff']);
            $application->save();

            return [Enrollment::query()->create([
                'application_id' => $application->id, 'child_id' => $f['child']->id, 'group_id' => $target->id,
                'price_option_id' => $application->price_option_id, 'scope' => 'full_period', 'status' => 'active',
                'starts_on' => '2026-11-23', 'transferred_from_id' => $old->id,
            ]), $application];
        });
        $this->event([...$f, 'application' => $application], 'enrollment.transferred', ['transferred_from_id' => $old->id, 'effective_date' => '2026-11-23'], $moved);

        app(DeliverEvents::class)->run();

        $html = $this->html($this->mailer->sent[0]['mail']);
        // The mock-up: the last session in the old group, not the calendar day before the move.
        foreach (['Последно занимание в „Група 2“', 'сряда, 18 ноември · 17:30–18:30', 'Първо занимание в новата група', 'понеделник, 23 ноември · 16:00–17:00', 'Всеки понеделник · 16:00–17:00'] as $expected) {
            $this->assertStringContainsString($expected, $html);
        }
        $this->assertStringNotContainsString('22 ноември', $html);
    }

    public function test_skipping_the_last_session_of_the_period_says_so(): void
    {
        $f = $this->fixture();
        $enrollment = $this->activeEnrollment($f);
        $last = $this->withTenant($f['organization'], fn () => $f['group']->sessions()->orderByDesc('starts_at')->firstOrFail());
        $this->withTenant($f['organization'], fn () => EnrollmentSessionException::query()->create([
            'enrollment_id' => $enrollment->id, 'session_id' => $last->id, 'kind' => 'excluded', 'reason' => 'Пътуваме.',
        ]));
        $this->event($f, 'enrollment.session_excluded', ['session_id' => $last->id], $enrollment);

        app(DeliverEvents::class)->run();

        $html = $this->html($this->mailer->sent[0]['mail']);
        $this->assertStringContainsString('сряда, 25 ноември · 17:30–18:30', $html);
        $this->assertStringContainsString('След него няма други занимания за Ния в този период.', $html);
        $this->assertStringNotContainsString('Връща се', $html);
    }

    public function test_the_logo_comes_through_the_web_address_and_only_when_its_file_is_stored(): void
    {
        Storage::fake('local');
        config(['platform.web_url' => 'https://app.example']);
        $f = $this->fixture();
        $id = (string) Str::uuid();
        $profile = $this->withTenant($f['organization'], function () use ($id): OrganizationCatalogProfile {
            $profile = OrganizationCatalogProfile::query()->sole();
            $profile->forceFill(['logo_path' => "catalog/{$id}.png"])->save();

            return $profile;
        });
        $parentHtml = function (): string {
            $sent = collect($this->mailer->sent)->firstWhere('to', 'maria@example.com');
            $this->mailer->sent = [];

            return $this->html($sent['mail']);
        };

        // The file is not on disk: the initials, never a broken picture.
        $this->event($f, 'enrollment.application_submitted', ['status' => 'submitted']);
        app(DeliverEvents::class)->run();
        $html = $parentHtml();
        $this->assertStringNotContainsString('/media/', $html);
        $this->assertStringContainsString('ШЗ', $html);

        Storage::disk('local')->put("catalog/{$f['organization']->id}/{$id}.png", 'png');
        Storage::disk('local')->put("catalog/{$f['organization']->id}/{$id}.json", json_encode([
            'organization_id' => $f['organization']->id, 'activity_id' => null, 'path' => "catalog/{$id}.png", 'mime' => 'image/png',
        ], JSON_THROW_ON_ERROR));
        $this->event($f, 'enrollment.application_submitted', ['status' => 'submitted']);
        app(DeliverEvents::class)->run();
        $html = $parentHtml();
        // Through the web app's public proxy, as on the public page — not the API's own address.
        $this->assertStringContainsString('src="https://app.example/backend/api/v1/public/organizations/'.$profile->slug.'/media/'.$id.'.png"', $html);
        $this->assertStringContainsString('alt="ШЗ"', $html);
    }

    public function test_the_teams_words_come_from_the_transition_not_from_the_application(): void
    {
        $f = $this->fixture();
        $coordinator = $this->member($f['organization'], 'coordinator');
        $this->actingAs($coordinator)->withSession(['active_organization_id' => $f['organization']->id])
            ->postJson("/api/v1/enrollment/applications/{$f['application']->id}/review", [
                'decision' => 'reject', 'version' => 1, 'public_reason' => 'Групата е за по-малки деца.', 'internal_note' => 'Само за екипа.',
            ])->assertOk();
        // The application's text is changed later; the email quotes what the parent was told then.
        $this->withTenant($f['organization'], fn () => $f['application']->forceFill(['public_reason' => 'Друг текст'])->save());

        app(DeliverEvents::class)->run();

        $html = $this->html($this->mailer->sent[0]['mail']);
        $this->assertStringContainsString('Обяснение от екипа', $html);
        $this->assertStringContainsString('„Групата е за по-малки деца.“', $html);
        $this->assertStringNotContainsString('Друг текст', $html);
        $this->assertStringNotContainsString('Само за екипа', $html);
    }

    public function test_one_broken_email_does_not_stop_the_others(): void
    {
        $f = $this->fixture();
        $enrollment = $this->withTenant($f['organization'], function () use ($f): Enrollment {
            $f['application']->forceFill(['status' => 'rejected'])->save();

            return Enrollment::query()->create([
                'application_id' => $f['application']->id, 'child_id' => $f['child']->id, 'group_id' => $f['group']->id,
                'price_option_id' => $f['application']->price_option_id, 'scope' => 'full_period', 'status' => 'active', 'starts_on' => '2026-10-01',
            ]);
        });
        $broken = $this->event($f, 'enrollment.paused', ['starts_on' => 'не е дата', 'ends_on' => 'не е дата'], $enrollment);
        $this->event($f, 'enrollment.application_rejected', ['status' => 'rejected']);

        app(DeliverEvents::class)->run();

        $this->assertCount(1, $this->mailer->sent);
        $delivery = $this->withTenant($f['organization'], fn () => EnrollmentEventDelivery::query()->where('event_id', $broken->id)->sole());
        $this->assertSame('pending', $delivery->status);
        $this->assertSame(1, $delivery->attempts);
        $this->assertNotNull($delivery->last_error);
    }

    public function test_out_of_time_gives_back_what_it_took_without_counting(): void
    {
        $f = $this->fixture();
        $this->withTenant($f['organization'], fn () => $f['application']->forceFill(['status' => 'rejected'])->save());
        $this->event($f, 'enrollment.application_rejected', ['status' => 'rejected']);

        app(DeliverEvents::class)->run(0);

        $this->assertSame([], $this->mailer->sent);
        $delivery = $this->withTenant($f['organization'], fn () => EnrollmentEventDelivery::query()->sole());
        $this->assertSame('pending', $delivery->status);
        $this->assertSame(0, $delivery->attempts);
    }

    #[TestGroup('tenant-isolation')]
    public function test_each_organization_is_sent_on_its_own_and_the_context_is_cleared(): void
    {
        $first = $this->fixture('Школа Звезда');
        $second = $this->fixture('Клуб Ракета', email: 'parent-two@example.com');
        foreach ([$first, $second] as $f) {
            $this->withTenant($f['organization'], fn () => $f['application']->forceFill(['status' => 'rejected'])->save());
            $this->event($f, 'enrollment.application_rejected', ['status' => 'rejected']);
        }

        app(DeliverEvents::class)->run();

        $this->assertFalse(app(TenantContext::class)->hasOrganization());
        $sent = collect($this->mailer->sent)->map(fn (array $item): string => $item['to'].' | '.$item['mail']->envelope()->from?->name)->sort()->values()->all();
        $this->assertSame([
            $first['parent']->email.' | Школа Звезда чрез Progressive Activities',
            'parent-two@example.com | Клуб Ракета чрез Progressive Activities',
        ], $sent);
        foreach (DB::table('enrollment_event_deliveries')->get() as $row) {
            $this->assertSame($row->organization_id, DB::table('enrollment_events')->where('id', $row->event_id)->value('organization_id'));
        }
    }

    public function test_production_refuses_a_mailer_that_keeps_the_text_and_links_without_https(): void
    {
        $environment = $this->app->environment();
        $this->app->detectEnvironment(fn (): string => 'production');
        config(['platform.web_url' => 'https://app.example.com']);

        try {
            config(['mail.default' => 'log']);
            $this->assertSame(1, Artisan::call('enrollment:deliver-events'));
            // "log" hidden in a failover chain is refused too.
            config(['mail.default' => 'failover', 'mail.mailers.failover' => ['transport' => 'failover', 'mailers' => ['smtp', 'log']]]);
            $this->assertSame(1, Artisan::call('enrollment:deliver-events'));
            config(['mail.default' => 'smtp', 'platform.web_url' => 'http://app.example.com']);
            $this->assertSame(1, Artisan::call('enrollment:deliver-events'));
            config(['platform.web_url' => 'https://app.example.com']);
            $this->assertSame(0, Artisan::call('enrollment:deliver-events'));
        } finally {
            $this->app->detectEnvironment(fn (): string => $environment);
        }
    }

    /**
     * Школа Звезда (synthetic): Шахмат · Група 2 on Wednesdays 17:30, in Зала 1; Мария with Ния (2 клас),
     * an application with a note; a coloured, published profile and a contact address.
     *
     * @return array{organization: Organization, parent: User, child: Child, group: Group, application: EnrollmentApplication}
     */
    private function fixture(string $name = 'Школа Звезда', bool $verified = true, ?string $email = null): array
    {
        $organization = Organization::query()->withoutGlobalScopes()->create([
            'name' => $name, 'slug' => fake()->unique()->slug(), 'status' => 'active', 'public_contact_email' => 'info@zvezda.example',
        ]);
        $parent = User::factory()->create([
            'name' => 'Мария Петрова', 'phone' => '+359888123456', 'email' => $email ?? 'maria@example.com',
            'email_verified_at' => $verified ? now() : null,
        ]);
        $child = $parent->children()->create(['first_name' => 'Ния', 'last_name' => 'Иванова', 'class_label' => '2 клас', 'birth_date' => '2018-05-14']);

        return $this->withTenant($organization, function () use ($organization, $parent, $child, $name): array {
            OrganizationCatalogProfile::query()->create(['public_name' => $name, 'accent_color' => '#216653', 'is_published' => true]);
            $location = Location::query()->create([
                'name' => 'Зала 1', 'type' => 'physical', 'space_usage' => 'exclusive', 'address' => 'ул. Пример 1, София', 'public_directions' => 'Вход от двора.',
            ]);
            $activity = Activity::query()->create([
                'slug' => fake()->unique()->slug(), 'title' => 'Шахмат', 'category' => 'Наука', 'summary' => 'Описание', 'age_label' => '1.–4. клас', 'status' => 'published',
            ]);
            $offering = $activity->offerings()->create(['location_id' => $location->id]);
            $group = $offering->groups()->create([
                'name' => 'Група 2', 'capacity_max' => 10, 'status' => 'published', 'enrollment_approval_mode' => 'manual', 'location_id' => $location->id,
                'schedule_pattern' => ['timezone' => 'Europe/Sofia', 'frequency' => 'weekly', 'slots' => [['weekday' => 3, 'starts_at' => '17:30', 'duration_minutes' => 60]]],
            ]);
            foreach (['2026-11-04', '2026-11-11', '2026-11-18', '2026-11-25'] as $day) {
                $group->sessions()->create(['starts_at' => "{$day}T15:30:00Z", 'ends_at' => "{$day}T16:30:00Z", 'status' => 'scheduled']);
            }
            $price = $offering->priceOptions()->create([
                'group_id' => $group->id, 'enrollment_mode' => 'monthly', 'unit' => 'month', 'amount_cents' => 4500, 'currency' => 'EUR', 'is_available' => true,
            ]);
            $application = EnrollmentApplication::query()->create([
                'parent_user_id' => $parent->id, 'child_id' => $child->id, 'group_id' => $group->id, 'price_option_id' => $price->id,
                'scope' => 'full_period', 'source' => 'organization_catalog', 'status' => 'submitted', 'idempotency_key' => fake()->uuid(),
                'accepted_terms_version' => 'v1', 'accepted_terms_at' => now(), 'amount_cents_snapshot' => 4500, 'currency_snapshot' => 'EUR',
                'unit_snapshot' => 'month', 'parent_note' => 'Обадете се след 17:00.',
            ]);

            return compact('organization', 'parent', 'child', 'group', 'application');
        });
    }

    /**
     * @param  array<string, mixed>  $f
     * @param  array<string, mixed>  $payload
     */
    private function event(array $f, string $type, array $payload, ?Enrollment $enrollment = null, ?EnrollmentTransition $transition = null, mixed $at = null): EnrollmentEvent
    {
        return $this->withTenant($f['organization'], fn () => EnrollmentEvent::query()->create([
            'type' => $type, 'application_id' => $f['application']->id, 'enrollment_id' => $enrollment?->id,
            'payload' => $transition === null ? $payload : [...$payload, 'transition_id' => $transition->id],
            'occurred_at' => $at ?? now(),
        ]));
    }

    /** @param  array<string, mixed>  $f */
    private function transition(array $f, Enrollment $enrollment, User $actor, ?string $reason): EnrollmentTransition
    {
        return $this->withTenant($f['organization'], fn () => EnrollmentTransition::query()->create([
            'enrollment_id' => $enrollment->id, 'actor_user_id' => $actor->id, 'from_status' => 'active', 'to_status' => 'ended', 'public_reason' => $reason,
        ]));
    }

    /** @param  array<string, mixed>  $f */
    private function activeEnrollment(array $f): Enrollment
    {
        return $this->withTenant($f['organization'], function () use ($f): Enrollment {
            $f['application']->forceFill(['status' => 'approved'])->save();

            return Enrollment::query()->create([
                'application_id' => $f['application']->id, 'child_id' => $f['child']->id, 'group_id' => $f['group']->id,
                'price_option_id' => $f['application']->price_option_id, 'scope' => 'full_period', 'status' => 'active', 'starts_on' => '2026-10-01',
            ]);
        });
    }

    /** The HTML without the word joiners that keep "17:30–18:30" on one line. */
    private function html(EnrollmentMail $mail): string
    {
        return str_replace("\u{2060}", '', $mail->render());
    }

    private function text(EnrollmentMail $mail): string
    {
        return view('mail.enrollment-text', ['mail' => $mail->mail])->render();
    }

    private function member(Organization $organization, string $role): User
    {
        $user = User::factory()->create();
        OrganizationMembership::query()->withoutGlobalScopes()->create([
            'organization_id' => $organization->id, 'user_id' => $user->id, 'role' => $role, 'status' => 'active',
        ]);

        return $user;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withTenant(Organization $organization, callable $callback): mixed
    {
        app(TenantContext::class)->select($organization);

        try {
            return $callback();
        } finally {
            app(TenantContext::class)->clear();
        }
    }
}

/** Records what would be sent; can fail like a mail server. */
final class RecordingMailSender extends MailSender
{
    /** @var list<array{to: string, mail: EnrollmentMail}> */
    public array $sent = [];

    public ?Throwable $failWith = null;

    public function send(string $to, EnrollmentMail $mail): void
    {
        if ($this->failWith !== null) {
            throw $this->failWith;
        }
        $this->sent[] = ['to' => $to, 'mail' => $mail];
    }
}
