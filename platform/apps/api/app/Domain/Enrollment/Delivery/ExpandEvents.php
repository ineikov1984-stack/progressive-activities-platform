<?php

namespace App\Domain\Enrollment\Delivery;

use App\Domain\Enrollment\Models\EnrollmentEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Turns an organization's new events into one delivery per recipient, in transactions with the
 * organization's RLS setting. `expanded_at` is set in the event's transaction, also when no row is created,
 * so each event is looked at exactly once (docs/product/enrollment-contacts-and-emails.md, т. 4, 6.1).
 */
final class ExpandEvents
{
    private const BATCH = 200;

    public function __construct(private readonly DeliveryRecipients $recipients) {}

    /**
     * How many events were looked at. Each event is expanded in its own transaction: one that cannot be
     * expanded is set aside with identifiers only in the log, so it never blocks the organization's other
     * events or any other organization.
     */
    public function handle(string $organizationId): int
    {
        $ids = TenantTransaction::run($organizationId, fn (): array => EnrollmentEvent::query()->whereNull('expanded_at')
            ->orderBy('occurred_at')->limit(self::BATCH)->pluck('id')->map(fn (mixed $id): string => (string) $id)->all());

        foreach ($ids as $eventId) {
            try {
                TenantTransaction::run($organizationId, fn () => $this->expandOne($organizationId, $eventId));
            } catch (Throwable $exception) {
                TenantTransaction::run($organizationId, fn () => EnrollmentEvent::query()->whereKey($eventId)->whereNull('expanded_at')
                    ->update(['expanded_at' => now(), 'updated_at' => now()]));
                Log::warning('An enrollment event could not be turned into emails.', [
                    'organization_id' => $organizationId, 'event_id' => $eventId, 'error' => DeliverEvents::errorCode($exception),
                ]);
            }
        }

        return count($ids);
    }

    private function expandOne(string $organizationId, string $eventId): void
    {
        $event = EnrollmentEvent::query()->whereKey($eventId)->whereNull('expanded_at')->first();
        if ($event === null) {
            return;
        }
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
}
