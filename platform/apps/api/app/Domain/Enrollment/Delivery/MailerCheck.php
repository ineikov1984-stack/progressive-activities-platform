<?php

namespace App\Domain\Enrollment\Delivery;

/**
 * A mailer that writes emails to the log or keeps them in memory — directly or anywhere in a failover or
 * round-robin chain — would put parents' addresses and children's names in the logs (ADR 0005).
 */
final class MailerCheck
{
    public static function keepsText(string $mailer, int $depth = 0): bool
    {
        $config = config("mail.mailers.{$mailer}");
        if (! is_array($config) || $depth > 5) {
            return true;
        }
        $transport = $config['transport'] ?? null;
        if (in_array($transport, ['log', 'array'], true)) {
            return true;
        }
        if (in_array($transport, ['failover', 'roundrobin'], true)) {
            foreach ((array) ($config['mailers'] ?? []) as $inner) {
                if (! is_string($inner) || self::keepsText($inner, $depth + 1)) {
                    return true;
                }
            }
        }

        return false;
    }
}
