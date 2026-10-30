<?php

namespace App\Mail;

use App\Domain\Enrollment\Delivery\MailContent;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/** One enrollment email: HTML and plain text from the same worded content. Not queueable on purpose. */
final class EnrollmentMail extends Mailable
{
    public function __construct(public readonly MailContent $mail, private readonly string $messageId) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address((string) config('mail.from.address'), $this->mail->fromName),
            replyTo: $this->mail->replyTo === null ? [] : [new Address($this->mail->replyTo)],
            subject: $this->mail->subject,
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.enrollment', text: 'mail.enrollment-text', with: ['mail' => $this->mail]);
    }

    /** The Message-ID comes from the delivery, so a retried email is recognisably the same one. */
    public function headers(): Headers
    {
        return new Headers(messageId: $this->messageId);
    }
}
