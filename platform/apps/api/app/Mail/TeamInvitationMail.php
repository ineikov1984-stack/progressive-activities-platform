<?php

namespace App\Mail;

use App\Domain\Enrollment\Delivery\Branding;
use App\Domain\Enrollment\Delivery\BulgarianDates;
use App\Domain\Enrollment\Delivery\MailContent;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The invitation to a team (docs/product/team.md, т. 7.2), in the layout of the enrollment emails. Not queueable on
 * purpose: the link carries the token, and nothing with an address or a token waits in `jobs` / `failed_jobs`
 * (ADR 0005). TeamInvitationSender sends it right after the commit.
 */
final class TeamInvitationMail extends Mailable
{
    /**
     * @param  string  $role  the role in words ("Преподавател")
     * @param  string  $url  the page that accepts the invitation, with the e-mail's token in the fragment
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
