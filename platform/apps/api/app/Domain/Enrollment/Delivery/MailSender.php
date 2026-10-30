<?php

namespace App\Domain\Enrollment\Delivery;

use App\Mail\EnrollmentMail;
use Illuminate\Support\Facades\Mail;

/**
 * Sends one email now, synchronously — never through a queue, where the finished text would sit in
 * `jobs` / `failed_jobs` without RLS (docs/product/enrollment-contacts-and-emails.md, т. 6.1).
 */
class MailSender
{
    public function send(string $to, EnrollmentMail $mail): void
    {
        Mail::to($to)->send($mail);
    }
}
