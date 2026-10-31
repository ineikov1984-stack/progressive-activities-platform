<?php

namespace Tests\Support;

use App\Domain\Enrollment\Delivery\MailSender;
use App\Mail\EnrollmentMail;

/** Keeps what would be sent, for the tests of the letters. */
final class CapturingMailSender extends MailSender
{
    /** @var list<array{to: string, mail: EnrollmentMail}> */
    public array $sent = [];

    public function send(string $to, EnrollmentMail $mail): void
    {
        $this->sent[] = ['to' => $to, 'mail' => $mail];
    }

    /** @return list<EnrollmentMail> */
    public function to(string $address): array
    {
        return array_values(array_map(fn (array $sent): EnrollmentMail => $sent['mail'],
            array_filter($this->sent, fn (array $sent): bool => $sent['to'] === $address)));
    }

    /** The HTML without the word joiners that keep "17:30–18:30" on one line. */
    public static function html(EnrollmentMail $mail): string
    {
        return str_replace("\u{2060}", '', $mail->render());
    }

    public static function text(EnrollmentMail $mail): string
    {
        return view('mail.enrollment-text', ['mail' => $mail->mail])->render();
    }
}
