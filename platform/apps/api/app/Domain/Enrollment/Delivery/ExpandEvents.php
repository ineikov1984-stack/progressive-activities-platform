<?php

namespace App\Domain\Enrollment\Delivery;

use App\Domain\Enrollment\Models\EnrollmentEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turns an organization's new events into one delivery per recipient, in one transaction with the
 * organization's RLS setting. `expanded_at` is set in the same transaction, also when no row is created,
 * so each event is looked at exactly once (docs/product/enrollment-contacts-and-emails.md, т. 4, 6.1).
 */
final class ExpandEvents
{
    private const BATCH = 200;

    public function __construct(private readonly DeliveryRecipients $recipients) {}

    /** How many events were expanded. */
    public function handle(string $organizationId): int
    {
        return TenantTransaction::run($organizationId, function () use ($organizationId): int {
            $events = EnrollmentEvent::query()->whereNull('expanded_at')->orderBy('occurred_at')->limit(self::BATCH)->get();
            foreach ($events as $event) {
                $now = now();
                foreach ($this->recipients->for($event) as $row) {
                    // The unique key (event, audience, recipient) makes a second expansion a no-op.
                    DB::table('enrollment_event_deliveries')->insertOrIgnore([...$row,
                        'id' => (string) Str::uuid(), 'organization_id' => $organizationId, 'event_id' => $event->id,
                        'attempts' => 0, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
                $event->forceFill(['expanded_at' => $now])->save();
            }

            return $events->count();
        });
    }
}
