<?php

namespace App\Domain\Organizations\Services;

use App\Domain\Identity\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * "Последен вход" and "Последна активност" of a person in an organization (DECISIONS 31.10.2026 „Екип — последен вход
 * и филтър по роли“): written by the system, never audited, never touching `updated_at` (the query builder writes the
 * two columns only). Each row is written with its own organization selected, as RLS requires.
 */
final class MembershipActivity
{
    /** "Последна активност" is written at most once in this many minutes for a membership, not on every request. */
    public const SEEN_EVERY_MINUTES = 5;

    /** A successful sign-in: every active membership of the user, one organization at a time. */
    public function signedIn(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $this->setting('app.current_user_id', (string) $user->id);
            $memberships = DB::table('organization_memberships')->where('user_id', $user->id)->where('status', 'active')
                ->get(['id', 'organization_id']);
            $now = CarbonImmutable::now();
            foreach ($memberships as $membership) {
                $this->setting('app.current_organization_id', (string) $membership->organization_id);
                DB::table('organization_memberships')->where('id', $membership->id)->update(['last_login_at' => $now]);
            }
        });
    }

    /** The user selected the organization (`POST /context/organization`): call with that organization selected. */
    public function selected(User $user, string $organizationId): void
    {
        DB::table('organization_memberships')->where('organization_id', $organizationId)->where('user_id', $user->id)
            ->update(['last_login_at' => CarbonImmutable::now()]);
    }

    /**
     * A request in the organization (`ResolveTenant`, with the organization selected): "Последна активност" when it is
     * empty or older than five minutes — otherwise nothing is written.
     */
    public function seen(string $membershipId, ?DateTimeInterface $lastSeen): void
    {
        $now = CarbonImmutable::now();
        if ($lastSeen !== null && CarbonImmutable::instance($lastSeen)->greaterThan($now->subMinutes(self::SEEN_EVERY_MINUTES))) {
            return;
        }
        DB::table('organization_memberships')->where('id', $membershipId)->update(['last_seen_at' => $now]);
    }

    private function setting(string $setting, string $value): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::select('SELECT set_config(?, ?, true)', [$setting, $value]);
        }
    }
}
