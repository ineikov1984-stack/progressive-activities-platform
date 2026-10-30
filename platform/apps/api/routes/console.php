<?php

use App\Domain\Enrollment\Delivery\DeliverEvents;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Enrollment emails (docs/product/enrollment-contacts-and-emails.md, т. 6.1; ADR 0005). Runs as the
// application's database role, never the owner role.
Artisan::command('enrollment:deliver-events {--budget=50 : Seconds to keep sending in one run}', function (DeliverEvents $deliver): int {
    // A production mailer that only writes to the log would put every parent's email in the logs.
    if (app()->environment('production') && in_array(config('mail.default'), ['log', 'array'], true)) {
        $this->error('Set a real mailer (MAIL_MAILER) before sending enrollment emails in production.');

        return 1;
    }
    $stats = $deliver->run(max(1, (int) $this->option('budget')));
    // Counts only: no addresses, no names.
    $this->line(sprintf('expanded %d · sent %d · retry %d · failed %d · skipped %d',
        $stats['expanded'], $stats['sent'], $stats['retried'], $stats['failed'], $stats['skipped']));

    return 0;
})->purpose('Send enrollment emails to parents and teams');

Schedule::command('enrollment:deliver-events')->everyMinute()->withoutOverlapping()->onOneServer();
