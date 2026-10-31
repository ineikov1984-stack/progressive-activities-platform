<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\ValueObjects\PlannedSessionChange;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Organizations\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Who of the teachers of a session hears of its change (docs/product/schedule-changes.md, т. 6.1, decision 6): the user
 * of the teacher profile's person of the team (not archived) — or, for old data, a profile not linked to anybody that
 * still names its user — with an active membership of the team here. Without a login, nobody: the session is in
 * "Моят график" all the same. Whether the address is confirmed is told by the letter itself, when it is expanded.
 */
final readonly class TeacherRecipients
{
    public function __construct(private TenantContext $context) {}

    /**
     * The letters to the teachers of a change: a cancelled session to whom taught it, a session back to whom teaches it
     * now — its teachers are worked out again (т. 4.9). One letter per teacher, with each of their sessions.
     *
     * @param  list<PlannedSessionChange>  $planned
     * @return array<string, list<array{session_id: string, effect: string}>> user => sessions
     */
    public function letters(array $planned): array
    {
        $profiles = [];
        foreach ($planned as $change) {
            array_push($profiles, ...$change->teachersBefore, ...$change->teachersAfter);
        }
        $users = $this->users(array_values(array_unique($profiles)));
        $letters = [];
        foreach ($planned as $change) {
            $effect = $change->effect();
            $teachers = $effect === 'cancelled' ? $change->teachersBefore : $change->teachersAfter;
            $told = [];
            foreach ($teachers as $profileId) {
                $userId = $users[$profileId] ?? null;
                if ($userId !== null && ! isset($told[$userId])) {
                    $told[$userId] = true;
                    $letters[$userId][] = ['session_id' => $change->sessionId(), 'effect' => $effect];
                }
            }
        }
        ksort($letters);

        return $letters;
    }

    /**
     * @param  list<string>  $profileIds
     * @return array<string, string> profile => user
     */
    public function users(array $profileIds): array
    {
        if ($profileIds === []) {
            return [];
        }
        $organizationId = $this->context->organizationId();
        $users = [];
        foreach (DB::table('instructor_profiles')
            ->leftJoin('staff_members', fn ($join) => $join->on('staff_members.id', '=', 'instructor_profiles.staff_member_id')
                ->where('staff_members.organization_id', '=', $organizationId))
            ->where('instructor_profiles.organization_id', $organizationId)
            ->whereIn('instructor_profiles.id', $profileIds)
            ->orderBy('instructor_profiles.id')
            ->get(['instructor_profiles.id', 'instructor_profiles.user_id as own_user_id', 'instructor_profiles.staff_member_id',
                'staff_members.user_id as person_user_id', 'staff_members.archived_at']) as $row) {
            $userId = $row->staff_member_id === null ? $row->own_user_id : ($row->archived_at === null ? $row->person_user_id : null);
            if ($userId !== null && $userId !== '') {
                $users[(string) $row->id] = (string) $userId;
            }
        }
        if ($users === []) {
            return [];
        }
        $team = self::team($organizationId, array_values(array_unique($users)));
        $active = User::query()->whereKey($team)->where('is_active', true)->pluck('id')->map(fn (mixed $id): string => (string) $id)->all();

        return array_filter($users, fn (string $userId): bool => in_array($userId, $active, true));
    }

    /**
     * The users with an active membership of the team here — not the platform operator, who is not a person of the team.
     *
     * @param  list<string>  $userIds
     * @return list<string>
     */
    public static function team(string $organizationId, array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }
        $team = [];
        foreach (OrganizationMembership::query()->withoutGlobalScopes()->where('organization_id', $organizationId)
            ->whereIn('user_id', $userIds)->where('status', 'active')->get(['user_id', 'role']) as $membership) {
            if (OrganizationRole::tryFrom((string) $membership->role)?->isTeam() === true) {
                $team[] = (string) $membership->user_id;
            }
        }

        return array_values(array_unique($team));
    }

    /**
     * How many of these users will get the letter: a confirmed address.
     *
     * @param  list<string>  $userIds
     */
    public function withConfirmedAddress(array $userIds): int
    {
        return $userIds === [] ? 0 : User::query()->whereKey($userIds)->whereNotNull('email_verified_at')->count();
    }
}
