<?php

namespace App\Domain\Enrollment\Delivery;

use App\Domain\Enrollment\Models\EnrollmentEvent;
use App\Domain\Enrollment\Models\EnrollmentEventDelivery;
use App\Mail\EnrollmentMail;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

/**
 * The sender (docs/product/enrollment-contacts-and-emails.md, т. 6.1, ADR 0005): for each organization with
 * work — expand new events, then claim a batch, compose each email under RLS, send it outside any
 * transaction, and mark it. "At least once": a duplicate is possible only if the process stops between
 * sending and marking. Logs never hold an address or a text.
 */
final class DeliverEvents
{
    public const BATCH = 10;

    public const MAX_ATTEMPTS = 5;

    /** Minutes to wait after the 1st … 4th failed attempt. */
    private const BACKOFF = [1, 5, 30, 120, 720];

    private const LEASE_MINUTES = 5;

    private const MAX_AGE_HOURS = 72;

    public function __construct(
        private readonly ExpandEvents $expand,
        private readonly ComposeDelivery $compose,
        private readonly MailSender $sender,
    ) {}

    /** @return array{expanded: int, sent: int, retried: int, failed: int, skipped: int} */
    public function run(int $budgetSeconds = 50): array
    {
        $deadline = microtime(true) + $budgetSeconds;
        $stats = ['expanded' => 0, 'sent' => 0, 'retried' => 0, 'failed' => 0, 'skipped' => 0];

        foreach ($this->tenants() as $organizationId) {
            $stats['expanded'] += $this->expand->handle($organizationId);
        }
        // Batches of 10, round after round, until there is nothing left or the time is up (10 is not a limit per minute).
        do {
            $worked = false;
            foreach ($this->tenants() as $organizationId) {
                foreach ($this->claim($organizationId, $stats) as $deliveryId) {
                    $worked = true;
                    $this->deliver($organizationId, $deliveryId, $stats);
                }
                if (microtime(true) >= $deadline) {
                    return $stats;
                }
            }
        } while ($worked && microtime(true) < $deadline);

        return $stats;
    }

    /**
     * Organizations with new events or with emails to send — identifiers only. On PostgreSQL the
     * SECURITY DEFINER function; elsewhere (tests) the same query directly.
     *
     * @return list<string>
     */
    public function tenants(): array
    {
        if (DB::getDriverName() === 'pgsql') {
            return array_values(array_map(fn (object $row): string => (string) current((array) $row),
                DB::select('SELECT * FROM public.pending_enrollment_delivery_tenants()')));
        }

        $events = DB::table('enrollment_events')->whereNull('expanded_at')->select('organization_id');
        $deliveries = DB::table('enrollment_event_deliveries')
            ->where(fn ($query) => $query->where('status', 'pending')
                ->orWhere(fn ($sending) => $sending->where('status', 'sending')->where('locked_until', '<', now())))
            ->select('organization_id');

        return DB::table('organizations')->where('status', 'active')
            ->where(fn ($query) => $query->whereIn('id', $events)->orWhereIn('id', $deliveries))
            ->orderBy('id')->pluck('id')->map(fn (mixed $id): string => (string) $id)->values()->all();
    }

    /**
     * A short transaction: give back expired leases, drop emails about events older than 72 hours, then
     * take up to 10 ready ones (skipping rows another sender holds). The attempt is counted here, so an
     * email that crashes the sender does not come back forever.
     *
     * @param  array{expanded: int, sent: int, retried: int, failed: int, skipped: int}  $stats
     * @return list<string>
     */
    private function claim(string $organizationId, array &$stats): array
    {
        return TenantTransaction::run($organizationId, function () use (&$stats): array {
            $now = CarbonImmutable::now();
            EnrollmentEventDelivery::query()->where('status', 'sending')->where('locked_until', '<', $now)
                ->where('attempts', '>=', self::MAX_ATTEMPTS)
                ->update(['status' => 'failed', 'locked_until' => null, 'updated_at' => $now]);
            EnrollmentEventDelivery::query()->where('status', 'sending')->where('locked_until', '<', $now)
                ->update(['status' => 'pending', 'locked_until' => null, 'next_attempt_at' => $now, 'updated_at' => $now]);
            $stats['skipped'] += EnrollmentEventDelivery::query()->where('status', 'pending')
                ->whereIn('event_id', EnrollmentEvent::query()->where('occurred_at', '<', $now->subHours(self::MAX_AGE_HOURS))->select('id'))
                ->update(['status' => 'skipped', 'skipped_reason' => 'too_old', 'updated_at' => $now]);

            $ids = EnrollmentEventDelivery::query()->where('status', 'pending')
                ->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', $now))
                ->orderBy('created_at')->orderBy('id')
                ->limit(self::BATCH)
                ->lock(DB::getDriverName() === 'pgsql' ? 'for update skip locked' : true)
                ->pluck('id')->map(fn (mixed $id): string => (string) $id)->values()->all();
            if ($ids !== []) {
                EnrollmentEventDelivery::query()->whereKey($ids)->update([
                    'status' => 'sending', 'locked_until' => $now->addMinutes(self::LEASE_MINUTES),
                    'attempts' => DB::raw('attempts + 1'), 'updated_at' => $now,
                ]);
            }

            return $ids;
        });
    }

    /** @param  array{expanded: int, sent: int, retried: int, failed: int, skipped: int}  $stats */
    private function deliver(string $organizationId, string $deliveryId, array &$stats): void
    {
        $composed = TenantTransaction::run($organizationId, function () use ($deliveryId): ?array {
            $delivery = EnrollmentEventDelivery::query()->whereKey($deliveryId)->where('status', 'sending')->first();

            return $delivery === null ? null : ['delivery' => $delivery, ...$this->compose->handle($delivery)];
        });
        if ($composed === null) {
            return;
        }
        if (isset($composed['skip'])) {
            $this->mark($organizationId, $deliveryId, ['status' => 'skipped', 'skipped_reason' => $composed['skip'], 'locked_until' => null]);
            $stats['skipped']++;

            return;
        }

        /** @var EnrollmentEventDelivery $delivery */
        $delivery = $composed['delivery'];
        $domain = substr((string) strrchr((string) config('mail.from.address'), '@'), 1) ?: 'localhost';
        try {
            // After the read transaction has ended: a slow mail server never holds a database transaction.
            $this->sender->send($composed['to'], new EnrollmentMail($composed['mail'], $deliveryId.'@'.$domain));
        } catch (Throwable $exception) {
            $failed = $delivery->attempts >= self::MAX_ATTEMPTS;
            $this->mark($organizationId, $deliveryId, [
                'status' => $failed ? 'failed' : 'pending',
                'locked_until' => null,
                'next_attempt_at' => $failed ? null : now()->addMinutes(self::BACKOFF[max(0, $delivery->attempts - 1)] ?? 720),
                'last_error' => self::errorCode($exception),
            ]);
            $stats[$failed ? 'failed' : 'retried']++;

            return;
        }
        $this->mark($organizationId, $deliveryId, ['status' => 'sent', 'sent_at' => now(), 'locked_until' => null, 'last_error' => null]);
        $stats['sent']++;
    }

    /** @param  array<string, mixed>  $values */
    private function mark(string $organizationId, string $deliveryId, array $values): void
    {
        TenantTransaction::run($organizationId, fn () => EnrollmentEventDelivery::query()->whereKey($deliveryId)
            ->where('status', 'sending')->update([...$values, 'updated_at' => now()]));
    }

    /** A class name or an SMTP code only — never the message, which can hold an address. */
    public static function errorCode(Throwable $exception): string
    {
        $class = class_basename($exception);
        $code = $exception instanceof TransportExceptionInterface && $exception->getCode() > 0 ? ':'.$exception->getCode() : '';

        return substr($class.$code, 0, 100);
    }
}
