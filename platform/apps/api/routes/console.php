<?php

use App\Domain\Catalog\Actions\SanitizeStoredMedia;
use App\Domain\Catalog\Services\ImageRejected;
use App\Domain\Enrollment\Delivery\DeliverEvents;
use App\Domain\Enrollment\Delivery\MailerCheck;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Enrollment emails (docs/product/enrollment-contacts-and-emails.md, т. 6.1; ADR 0005). Runs as the
// application's database role, never the owner role.
Artisan::command('enrollment:deliver-events {--budget=50 : Seconds to keep sending in one run}', function (DeliverEvents $deliver): int {
    if (app()->environment('production')) {
        if (MailerCheck::keepsText((string) config('mail.default'))) {
            $this->error('Set a real mailer (MAIL_MAILER, without "log" or "array" in a chain) before sending enrollment emails in production.');

            return 1;
        }
        if (! str_starts_with((string) config('platform.web_url'), 'https://')) {
            $this->error('Set WEB_URL to the https address of the web app before sending enrollment emails in production.');

            return 1;
        }
    }
    $stats = $deliver->run(max(1, (int) $this->option('budget')));
    // Counts only: no addresses, no names.
    $this->line(sprintf('expanded %d · sent %d · retry %d · failed %d · skipped %d',
        $stats['expanded'], $stats['sent'], $stats['retried'], $stats['failed'], $stats['skipped']));

    return 0;
})->purpose('Send enrollment emails to parents and teams');

// The overlap lock lasts 10 minutes, not a day: a killed run must not stop the emails for long.
Schedule::command('enrollment:deliver-events')->everyMinute()->withoutOverlapping(10)->onOneServer();

// Photos stored before the sanitizer lose the phone's data (docs/product/teacher-content.md, т. 5.2). Run once after
// PR 1a in the `api` container (the worker mounts the photos read-only); a second run changes nothing. Counts and
// identifiers only.
Artisan::command('catalog:sanitize-media', function (SanitizeStoredMedia $sanitize): int {
    try {
        $result = $sanitize->run();
    } catch (ImageRejected) {
        $this->error('PHP needs gd (with JPEG, PNG and WebP) and exif to clean the photos. Nothing was changed.');

        return 1;
    }
    $this->line(sprintf('cleaned %d · already clean %d · could not process %d',
        $result['sanitized'], $result['skipped'], count($result['failed'])));
    if ($result['failed'] !== []) {
        $this->line('Could not process (left as they are; do these by hand):');
        foreach ($result['failed'] as $failed) {
            $this->line(sprintf('  organization %s · photo %s', $failed['organization_id'], $failed['media_id']));
        }
    }

    return 0;
})->purpose('Clean the stored catalog photos of EXIF, GPS and other metadata');
