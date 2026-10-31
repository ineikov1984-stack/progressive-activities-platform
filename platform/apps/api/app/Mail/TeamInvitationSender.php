<?php

namespace App\Mail;

use App\Domain\Enrollment\Delivery\DeliverEvents;
use App\Domain\Enrollment\Delivery\MailerCheck;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

/**
 * Sends a team invitation synchronously once the invitation is committed (docs/product/team.md, т. 7.2): without a
 * Laravel queue, so the address and the token never sit in `jobs` / `failed_jobs` (ADR 0005). A failure leaves the
 * invitation as it is — the inviter sends it again or copies the link — and reaches the log as the error's class and
 * SMTP code only, never the address, the link or the text.
 */
final class TeamInvitationSender
{
    public function afterCommit(string $invitationId, string $to, TeamInvitationMail $mail): void
    {
        DB::afterCommit(function () use ($invitationId, $to, $mail): void {
            try {
                // A mailer that writes to the log would put the link with the token there.
                if (app()->environment('production') && (MailerCheck::keepsText((string) config('mail.default'))
                    || ! str_starts_with((string) config('platform.web_url'), 'https://'))) {
                    throw new RuntimeException('mailer');
                }
                Mail::to($to)->send($mail);
            } catch (Throwable $exception) {
                Log::warning('The team invitation e-mail was not sent.', [
                    'invitation_id' => $invitationId,
                    'error' => DeliverEvents::errorCode($exception),
                ]);
            }
        });
    }
}
