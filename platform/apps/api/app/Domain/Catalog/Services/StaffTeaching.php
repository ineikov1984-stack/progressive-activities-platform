<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Organizations\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What the people of a team teach, for the team pages (docs/product/team.md, т. 6, 9): their teacher profiles, the
 * groups they lead or assist and what stops archiving them. Read only, always inside the selected organization.
 */
final readonly class StaffTeaching
{
    public function __construct(private TenantContext $context) {}

    /**
     * @param  list<string>  $staffMemberIds
     * @return array<string, string> person → teacher profile
     */
    public function profilesFor(array $staffMemberIds): array
    {
        if ($staffMemberIds === []) {
            return [];
        }

        $profiles = [];
        foreach (DB::table('instructor_profiles')->where('organization_id', $this->context->organizationId())
            ->whereIn('staff_member_id', $staffMemberIds)->get(['id', 'staff_member_id']) as $row) {
            $profiles[(string) $row->staff_member_id] = (string) $row->id;
        }

        return $profiles;
    }

    /**
     * The groups of each profile: current and later assignments, or all of them with the ended ones.
     *
     * @param  list<string>  $profileIds
     * @return array<string, list<array{group_id: string, label: string, role: string, starts_on: string|null, ends_on: string|null}>>
     */
    public function assignments(array $profileIds, bool $withEnded = false): array
    {
        if ($profileIds === []) {
            return [];
        }
        $today = CarbonImmutable::now('Europe/Sofia')->toDateString();
        $rows = DB::table('activity_group_instructors')
            ->join('activity_groups', 'activity_groups.id', '=', 'activity_group_instructors.group_id')
            ->join('offerings', 'offerings.id', '=', 'activity_groups.offering_id')
            ->join('activities', 'activities.id', '=', 'offerings.activity_id')
            ->where('activity_group_instructors.organization_id', $this->context->organizationId())
            ->whereIn('activity_group_instructors.instructor_profile_id', $profileIds)
            ->when(! $withEnded, fn ($query) => $query->where(fn ($open) => $open
                ->whereNull('activity_group_instructors.ends_on')->orWhere('activity_group_instructors.ends_on', '>=', $today)))
            ->orderBy('activities.title')->orderBy('activity_groups.name')
            ->get([
                'activity_group_instructors.instructor_profile_id', 'activity_group_instructors.group_id',
                'activity_group_instructors.role', 'activity_group_instructors.starts_on', 'activity_group_instructors.ends_on',
                'activities.title', 'activity_groups.name',
            ]);
        $day = fn (mixed $date): ?string => $date === null || $date === '' ? null : substr((string) $date, 0, 10);
        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row->instructor_profile_id][] = [
                'group_id' => (string) $row->group_id,
                'label' => $row->title.' · '.$row->name,
                'role' => (string) $row->role,
                'starts_on' => $day($row->starts_on),
                'ends_on' => $day($row->ends_on),
            ];
        }

        return $result;
    }

    /** Why the person with this profile cannot be archived yet, in words for the screen; null when nothing stops it. */
    public function archiveBlocker(string $profileId): ?string
    {
        $open = $this->assignments([$profileId])[$profileId][0] ?? null;
        if ($open !== null) {
            return 'Първо приключете назначението в „'.$open['label'].'“.';
        }
        // The lead of an offering is shown on the public page of the activity (т. 5.3).
        $lead = DB::table('offerings')->join('activities', 'activities.id', '=', 'offerings.activity_id')
            ->where('offerings.organization_id', $this->context->organizationId())
            ->where('offerings.lead_instructor_profile_id', $profileId)
            ->orderBy('activities.title')->first(['activities.title', 'offerings.title as offering_title']);
        if ($lead !== null) {
            $label = trim((string) $lead->offering_title) === '' ? $lead->title : $lead->title.' · '.$lead->offering_title;

            return 'Първо сменете водещия на „'.$label.'“.';
        }
        $session = DB::table('activity_session_instructors')
            ->join('activity_sessions', 'activity_sessions.id', '=', 'activity_session_instructors.session_id')
            ->join('activity_groups', 'activity_groups.id', '=', 'activity_sessions.group_id')
            ->join('offerings', 'offerings.id', '=', 'activity_groups.offering_id')
            ->join('activities', 'activities.id', '=', 'offerings.activity_id')
            ->where('activity_session_instructors.organization_id', $this->context->organizationId())
            ->where('activity_session_instructors.instructor_profile_id', $profileId)
            ->where('activity_sessions.status', 'scheduled')
            ->where('activity_sessions.starts_at', '>', now())
            ->orderBy('activity_sessions.starts_at')
            ->first(['activity_sessions.starts_at', 'activities.title', 'activity_groups.name']);
        if ($session !== null) {
            $day = CarbonImmutable::parse((string) $session->starts_at, 'UTC')->setTimezone('Europe/Sofia')->format('d.m.Y');

            return 'Първо сменете преподавателя на заниманието на '.$day.' г. в „'.$session->title.' · '.$session->name.'“.';
        }

        return null;
    }
}
