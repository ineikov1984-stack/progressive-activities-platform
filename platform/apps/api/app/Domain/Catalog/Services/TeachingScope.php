<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Enums\ActivityStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\StaffMember;
use App\Domain\Organizations\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Which groups a person teaches (docs/product/team.md, т. 8) — the one rule that Enrollment and, later, Attendance ask.
 * Always inside the selected organization; a person without a teacher profile there teaches nothing.
 *
 * A group is the person's when one of their teacher profiles
 * - (a) has an assignment in it whose period holds today or a later day (Sofia): current, or starting later;
 * - (b) is on one of its sessions that ended no earlier than the start of yesterday (Sofia): a substitute keeps the group
 *   while the teacher may still correct the attendance, until the end of the day after the session (DECISIONS 30.10.2026);
 * - (c) leads its offering (`offerings.lead_instructor_profile_id`): every group of that offering.
 * An ended assignment and an older substitution give nothing. A cancelled session is not taught.
 */
final readonly class TeachingScope
{
    private const TIMEZONE = 'Europe/Sofia';

    public function __construct(private TenantContext $context) {}

    /**
     * The person's teacher profiles here: through their person of the team (not archived), and — for old data — a profile
     * not linked to any person that still names the user (`instructor_profiles.user_id`, docs/product/team.md, т. 3).
     *
     * @return list<string>
     */
    public function profilesFor(User $user): array
    {
        $userId = (string) $user->getKey();
        $people = StaffMember::query()->where('user_id', $userId)->whereNull('archived_at')
            ->pluck('id')->map(fn (mixed $id): string => (string) $id)->all();

        return array_values(DB::table('instructor_profiles')
            ->where('organization_id', $this->context->organizationId())
            ->where(function ($query) use ($people, $userId): void {
                $query->where(fn ($old) => $old->whereNull('staff_member_id')->where('user_id', $userId));
                if ($people !== []) {
                    $query->orWhereIn('staff_member_id', $people);
                }
            })
            ->orderBy('id')->pluck('id')->map(fn (mixed $id): string => (string) $id)->all());
    }

    /** @return list<string> */
    public function groupsFor(User $user): array
    {
        return array_keys($this->teaching($this->profilesFor($user)));
    }

    public function includes(User $user, string $groupId): bool
    {
        return in_array($groupId, $this->groupsFor($user), true);
    }

    /**
     * The person's groups for "Моите групи" (`GET /me/teaching`), without archived groups and activities: activity and
     * group, why the group is theirs, the role, the period and the next session — the one still running or the next one
     * they are on; for the lead of an offering without an assignment, the group's next session.
     *
     * @return list<array{group_id: string, group_name: string, activity_id: string, activity_title: string, label: string, basis: string, role: string, starts_on: string|null, ends_on: string|null, next_session_starts_at: string|null}>
     */
    public function overview(User $user): array
    {
        $profiles = $this->profilesFor($user);
        $teaching = $this->teaching($profiles);
        if ($teaching === []) {
            return [];
        }

        $organizationId = $this->context->organizationId();
        $groupIds = array_keys($teaching);
        $now = CarbonImmutable::now('UTC');
        $mine = DB::table('activity_sessions')
            ->join('activity_session_instructors', 'activity_session_instructors.session_id', '=', 'activity_sessions.id')
            ->where('activity_sessions.organization_id', $organizationId)
            ->where('activity_session_instructors.organization_id', $organizationId)
            ->whereIn('activity_sessions.group_id', $groupIds)
            ->whereIn('activity_session_instructors.instructor_profile_id', $profiles)
            ->where('activity_sessions.status', 'scheduled')
            ->where('activity_sessions.ends_at', '>', $now)
            ->groupBy('activity_sessions.group_id')
            ->selectRaw('activity_sessions.group_id as group_id, min(activity_sessions.starts_at) as starts_at')
            ->pluck('starts_at', 'group_id');
        $led = array_keys(array_filter($teaching, fn (array $row): bool => $row['basis'] === 'offering_lead'));
        $any = $led === [] ? collect() : DB::table('activity_sessions')
            ->where('organization_id', $organizationId)
            ->whereIn('group_id', $led)
            ->where('status', 'scheduled')
            ->where('ends_at', '>', $now)
            ->groupBy('group_id')
            ->selectRaw('group_id, min(starts_at) as starts_at')
            ->pluck('starts_at', 'group_id');

        $groups = DB::table('activity_groups')
            ->join('offerings', 'offerings.id', '=', 'activity_groups.offering_id')
            ->join('activities', 'activities.id', '=', 'offerings.activity_id')
            ->where('activity_groups.organization_id', $organizationId)
            ->whereIn('activity_groups.id', $groupIds)
            ->where('activity_groups.status', '!=', 'archived')
            ->where('activities.status', '!=', ActivityStatus::Archived->value)
            ->orderBy('activities.title')->orderBy('activity_groups.name')->orderBy('activity_groups.id')
            ->get(['activity_groups.id', 'activity_groups.name', 'activities.id as activity_id', 'activities.title']);

        $result = [];
        foreach ($groups as $group) {
            $groupId = (string) $group->id;
            $next = $mine[$groupId] ?? $any[$groupId] ?? null;
            $result[] = [
                'group_id' => $groupId,
                'group_name' => (string) $group->name,
                'activity_id' => (string) $group->activity_id,
                'activity_title' => (string) $group->title,
                'label' => $group->title.' · '.$group->name,
                ...$teaching[$groupId],
                'next_session_starts_at' => $next === null ? null : CarbonImmutable::parse((string) $next, 'UTC')->utc()->toIso8601String(),
            ];
        }

        return $result;
    }

    /**
     * Why each group is the profiles' — the assignment (its role and period), the lead of the offering, or a substitution
     * (the role on the session and the first and last day of those sessions) — in that order of precedence.
     *
     * @param  list<string>  $profiles
     * @return array<string, array{basis: string, role: string, starts_on: string|null, ends_on: string|null}>
     */
    private function teaching(array $profiles): array
    {
        if ($profiles === []) {
            return [];
        }

        $organizationId = $this->context->organizationId();
        $now = CarbonImmutable::now(self::TIMEZONE);
        $today = $now->toDateString();
        $day = fn (mixed $date): ?string => $date === null || $date === '' ? null : substr((string) $date, 0, 10);
        $result = [];

        // (a) An assignment that has not ended: lead before assistant when a person has two profiles in one group.
        $assignments = DB::table('activity_group_instructors')
            ->where('organization_id', $organizationId)
            ->whereIn('instructor_profile_id', $profiles)
            ->where(fn ($open) => $open->whereNull('ends_on')->orWhere('ends_on', '>=', $today))
            ->orderBy('group_id')->orderByDesc('role')->orderBy('instructor_profile_id')
            ->get(['group_id', 'role', 'starts_on', 'ends_on']);
        foreach ($assignments as $row) {
            $result[(string) $row->group_id] ??= [
                'basis' => 'assignment', 'role' => (string) $row->role, 'starts_on' => $day($row->starts_on), 'ends_on' => $day($row->ends_on),
            ];
        }

        // (c) Every group of an offering the person leads.
        $led = DB::table('activity_groups')
            ->join('offerings', 'offerings.id', '=', 'activity_groups.offering_id')
            ->where('activity_groups.organization_id', $organizationId)
            ->where('offerings.organization_id', $organizationId)
            ->whereIn('offerings.lead_instructor_profile_id', $profiles)
            ->orderBy('activity_groups.id')
            ->pluck('activity_groups.id');
        foreach ($led as $groupId) {
            $result[(string) $groupId] ??= ['basis' => 'offering_lead', 'role' => 'lead', 'starts_on' => null, 'ends_on' => null];
        }

        // (b) A session the person is on that ended no earlier than the start of yesterday.
        $since = $now->subDay()->startOfDay()->utc();
        $sessions = DB::table('activity_session_instructors')
            ->join('activity_sessions', 'activity_sessions.id', '=', 'activity_session_instructors.session_id')
            ->where('activity_session_instructors.organization_id', $organizationId)
            ->where('activity_sessions.organization_id', $organizationId)
            ->whereIn('activity_session_instructors.instructor_profile_id', $profiles)
            ->where('activity_sessions.status', 'scheduled')
            ->where('activity_sessions.ends_at', '>=', $since)
            ->orderBy('activity_sessions.starts_at')->orderBy('activity_sessions.id')
            ->get(['activity_sessions.group_id', 'activity_sessions.starts_at', 'activity_session_instructors.role']);
        foreach ($sessions as $row) {
            $groupId = (string) $row->group_id;
            $date = CarbonImmutable::parse((string) $row->starts_at, 'UTC')->setTimezone(self::TIMEZONE)->toDateString();
            if (! isset($result[$groupId])) {
                $result[$groupId] = ['basis' => 'substitute', 'role' => (string) $row->role, 'starts_on' => $date, 'ends_on' => $date];
            } elseif ($result[$groupId]['basis'] === 'substitute') {
                $result[$groupId]['ends_on'] = $date;
            }
        }

        return $result;
    }
}
