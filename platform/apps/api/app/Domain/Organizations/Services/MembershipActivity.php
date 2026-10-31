<?php

namespace App\Domain\Organizations\Services;

use App\Domain\Identity\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * "Последен вход" and "Последна активност" of a person in an organization (DECISIONS 31.10.2026 „Екип — последен вход
 * и филтър по роли“). Written by the system, never audited, never touching `updated_at` (the query builder writes the
 * two columns only). Each write is a short transaction of its own, never the request's: the membership row is not held
 * while a request runs, so it cannot wait in a cycle with the order organization → person → membership of the team's
 * actions. The organization (and the user) are set explicitly for RLS and put back as they were after the write. A
 * failed write is reported without personal data and never fails the request.
 */
final class MembershipActivity
{
    /** "Последна активност" is written at most once in this many minutes for a membership, not on every request. */
    public const SEEN_EVERY_MINUTES = 5;

    /** A successful sign-in or a new profile from an invitation: every active membership of the user. */
    public function signedIn(User $user): void
    {
        $userId = (string) $user->getKey();
        $this->transaction(['app.current_user_id' => $userId, 'app.current_organization_id' => ''], function () use ($userId): void {
            $memberships = DB::table('organization_memberships')->where('user_id', $userId)->where('status', 'active')
                ->get(['id', 'organization_id']);
            $now = CarbonImmutable::now();
            foreach ($memberships as $membership) {
                $organizationId = (string) $membership->organization_id;
                // Each row with its own organization: RLS lets the row be written only there.
                $this->set('app.current_organization_id', $organizationId);
                DB::table('organization_memberships')->where('id', (string) $membership->id)->where('organization_id', $organizationId)
                    ->update(['last_login_at' => $now]);
            }
        });
    }

    /** The user selected the organization (`POST /context/organization`), after its membership was checked. */
    public function selected(User $user, string $organizationId): void
    {
        $userId = (string) $user->getKey();
        $this->transaction(['app.current_user_id' => $userId, 'app.current_organization_id' => $organizationId], function () use ($userId, $organizationId): void {
            DB::table('organization_memberships')->where('organization_id', $organizationId)->where('user_id', $userId)
                ->where('status', 'active')->update(['last_login_at' => CarbonImmutable::now()]);
        });
    }

    /** Whether "Последна активност" is due: empty, or older than five minutes. */
    public function isDue(?DateTimeInterface $lastSeen): bool
    {
        return $lastSeen === null
            || CarbonImmutable::instance($lastSeen)->lessThanOrEqualTo(CarbonImmutable::now()->subMinutes(self::SEEN_EVERY_MINUTES));
    }

    /**
     * "Последна активност" after a request in the organization (`ResolveTenant::terminate`, with the membership the
     * request checked). Requests at the same moment write it once: the first takes the key for five minutes, and the
     * UPDATE itself writes only a row that is still due.
     */
    public function seen(string $organizationId, string $membershipId): void
    {
        try {
            if (! Cache::add("membership-seen:{$membershipId}", 1, self::SEEN_EVERY_MINUTES * 60)) {
                return;
            }
        } catch (Throwable $exception) {
            $this->report($exception);

            return;
        }
        $this->transaction(['app.current_organization_id' => $organizationId], function () use ($organizationId, $membershipId): void {
            $now = CarbonImmutable::now();
            DB::table('organization_memberships')->where('id', $membershipId)->where('organization_id', $organizationId)
                ->where(fn ($due) => $due->whereNull('last_seen_at')->orWhere('last_seen_at', '<=', $now->subMinutes(self::SEEN_EVERY_MINUTES)))
                ->update(['last_seen_at' => $now]);
        });
    }

    /**
     * A short transaction of its own with these settings for RLS; what was set before is put back after it, also
     * inside a caller's transaction. A failure is reported and the caller goes on.
     *
     * @param  array<string, string>  $settings
     * @param  callable(): void  $work
     */
    private function transaction(array $settings, callable $work): void
    {
        try {
            DB::transaction(function () use ($settings, $work): void {
                $previous = [];
                foreach ($settings as $name => $value) {
                    $previous[$name] = $this->current($name);
                    $this->set($name, $value);
                }
                try {
                    $work();
                } finally {
                    foreach ($previous as $name => $value) {
                        $this->set($name, $value);
                    }
                }
            });
        } catch (Throwable $exception) {
            $this->report($exception);
        }
    }

    private function current(string $name): string
    {
        if (DB::getDriverName() !== 'pgsql') {
            return '';
        }
        $row = DB::selectOne('SELECT current_setting(?, true) AS value', [$name]);

        return is_object($row) && isset($row->value) ? (string) $row->value : '';
    }

    private function set(string $name, string $value): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::select('SELECT set_config(?, ?, true)', [$name, $value]);
        }
    }

    /** A failed write is reported with its kind only — never a row, an identifier or a name — and the request goes on. */
    private function report(Throwable $exception): void
    {
        report(new RuntimeException('Recording the last sign-in or activity failed ('.$exception::class.', code '.$exception->getCode().').'));
    }
}
