<?php

namespace App\Mail;

use App\Domain\Enrollment\Delivery\Branding;
use App\Domain\Enrollment\Delivery\BulgarianDates;
use App\Domain\Enrollment\Delivery\DeliverEvents;
use App\Domain\Enrollment\Delivery\MailContent;
use App\Domain\Enrollment\Delivery\MailerCheck;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Mail\Factory;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\SentMessage;
use RuntimeException;
use Throwable;

/**
 * The invitation to a team (docs/product/team.md, т. 7.2), in the layout of the enrollment emails. Queued after the
 * commit and encrypted in the queue, because the link carries the token; the scheduler runs the queue
 * (routes/console.php). A failure reaches the log as a class name and a code only — never the address or the link.
 */
final class TeamInvitationMail extends Mailable implements ShouldBeEncrypted, ShouldQueueAfterCommit
{
    /**
     * @param  string  $role  the role in words ("Преподавател")
     * @param  string  $url  the page that accepts the invitation, with the token
     */
    public function __construct(
        public readonly string $organization,
        public readonly string $inviter,
        public readonly string $role,
        public readonly string $url,
        public readonly CarbonImmutable $expiresAt,
    ) {}

    public function envelope(): Envelope
    {
        $mail = $this->mail();

        return new Envelope(from: new Address((string) config('mail.from.address'), $mail->fromName), subject: $mail->subject);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.enrollment', text: 'mail.enrollment-text', with: ['mail' => $this->mail()]);
    }

    /**
     * Seconds to wait after the 1st … 4th failed attempt.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 1800, 7200];
    }

    /**
     * @param  Factory|Mailer  $mailer
     * @return SentMessage|null
     */
    public function send($mailer)
    {
        // A mailer that writes to the log would put the link with the token there (ADR 0005).
        if (app()->environment('production') && (MailerCheck::keepsText((string) config('mail.default'))
            || ! str_starts_with((string) config('platform.web_url'), 'https://'))) {
            throw new RuntimeException('Set a real mailer (MAIL_MAILER) and the https WEB_URL before sending team invitations in production.');
        }

        try {
            return parent::send($mailer);
        } catch (Throwable $exception) {
            // The mail server's message can hold the address: only the class and the SMTP code go on.
            throw new RuntimeException('The team invitation was not sent: '.DeliverEvents::errorCode($exception));
        }
    }

    private function mail(): MailContent
    {
        $platform = (string) config('platform.name');
        $organization = $this->organization;
        $until = $this->expiresAt->setTimezone('Europe/Sofia');

        return new MailContent(
            team: true,
            fromName: "{$organization} чрез {$platform}",
            replyTo: null,
            subject: "Покана за екипа на {$organization}",
            brand: Branding::platform($organization),
            greeting: 'Здравейте,',
            heading: "{$this->inviter} ви кани в екипа на {$organization}",
            paragraphs: ["Ще влизате в системата на {$organization} с роля „{$this->role}“. Приемете поканата, за да започнете."],
            facts: [
                ['label' => 'Организация', 'value' => $organization],
                ['label' => 'Роля', 'value' => $this->role],
                ['label' => 'Покана от', 'value' => $this->inviter],
                ['label' => 'Поканата важи до', 'value' => BulgarianDates::weekdayDay($until->toDateString()).' '.$until->year.' г., '.$until->format('H:i').' ч.'],
            ],
            callout: null,
            quote: null,
            button: ['label' => 'Приеми поканата', 'url' => $this->url],
            after: [
                'Ако още нямате профил, ще изберете парола при приемането. Имейлът е този, на който получихте писмото.',
                'Не очаквате тази покана? Не правете нищо — без приемане никой не получава достъп.',
            ],
            footer: "Получавате това писмо, защото {$organization} ви покани в екипа си. Изпратено чрез {$platform}.",
        );
    }
}
