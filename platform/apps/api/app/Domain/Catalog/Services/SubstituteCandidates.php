<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class SubstituteCandidates
{
    public function __construct(private TenantContext $context, private AccessScope $scope, private SubstituteWarnings $warnings, private InstructorNames $names) {}

    public function authorize(User $actor): void
    {
        $member = DB::table('organization_memberships')->where('organization_id', $this->context->organizationId())->where('user_id', $actor->id)->where('status', 'active')->first();
        $role = $member === null ? null : OrganizationRole::tryFrom($member->role);
        abort_unless($role?->isTeam() && $this->scope->allowsEverywhere($actor, 'schedule.manage') && $this->scope->allowsEverywhere($actor, 'team.assign'), 403, 'Заместник назначават собственикът, администраторът и координаторът.');
    }

    /**
     * @param  array<mixed>  $sessionIds
     * @return array<string, mixed>
     */
    public function read(User $actor, array $sessionIds, ?string $replacesId = null): array
    {
        $this->authorize($actor);
        if (count($sessionIds) < 1 || count($sessionIds) > 50 || ! array_all($sessionIds, fn ($id) => is_string($id) && Str::isUuid($id))) {
            throw ValidationException::withMessages(['sessions' => 'Изберете от 1 до 50 занимания.']);
        }
        $ids = array_values(array_unique(array_map(strtolower(...), $sessionIds)));
        $org = $this->context->organizationId();
        $sessions = DB::table('activity_sessions as s')->join('activity_groups as g', 'g.id', '=', 's.group_id')->join('offerings as o', 'o.id', '=', 'g.offering_id')->join('activities as a', 'a.id', '=', 'o.activity_id')
            ->leftJoin('locations as l', fn ($q) => $q->on('l.id', '=', 's.location_id')->where('l.organization_id', $org))
            ->where('s.organization_id', $org)->where('g.organization_id', $org)->where('o.organization_id', $org)->where('a.organization_id', $org)->whereIn('s.id', $ids)->orderBy('s.starts_at')->orderBy('s.id')
            ->get(['s.*', 'g.status as group_status', 'g.name as group_name', 'g.age_label', 'o.activity_id', 'o.lead_instructor_profile_id', 'a.title as activity', 'l.name as location']);
        abort_unless($sessions->count() === count($ids) && ! $sessions->contains('group_status', 'draft'), 404);
        foreach ($sessions as $session) {
            $error = match (true) {
                $session->group_status === 'archived' => 'Групата е в архива.',
                CarbonImmutable::parse($session->starts_at, 'UTC')->lte(CarbonImmutable::now('UTC')) => 'Заниманието вече е започнало.',
                $session->status !== 'scheduled' => 'Заниманието вече е отменено.',
                default => null,
            };
            if ($error !== null) {
                throw ValidationException::withMessages(['sessions' => $error]);
            }
        }
        // All profiles, including old user-linked aliases, participate in person matching.
        $profiles = DB::table('instructor_profiles')->where('organization_id', $org)->get(['id', 'public_name', 'staff_member_id', 'user_id'])->keyBy('id');
        $names = $this->names->forIds($profiles->keys()->all());
        $staff = DB::table('staff_members')->where('organization_id', $org)->whereNull('archived_at')->get(['id', 'user_id'])->keyBy('id');
        $teachers = DB::table('activity_session_instructors')->where('organization_id', $org)->whereIn('session_id', $ids)->get()->groupBy('session_id');
        $history = DB::table('session_changes as c')->join('schedule_changes as h', 'h.id', '=', 'c.schedule_change_id')->where('c.organization_id', $org)->where('h.organization_id', $org)->where('h.action', 'substitute')->whereIn('c.session_id', $ids)->orderByDesc('h.created_at')->orderByDesc('c.schedule_change_id')->get(['c.*']);
        $common = null;
        $states = [];
        $steps = [];
        foreach ($sessions as $s) {
            $options = [];
            foreach ($teachers->get($s->id, collect()) as $t) {
                $original = $t->replaces_instructor_profile_id ?? $t->instructor_profile_id;
                $options[$original] = $t->replaces_instructor_profile_id === null ? 'teaching' : 'replaced';
                $steps[$s->id][$original] = $t->instructor_profile_id;
            }
            foreach ($history->where('session_id', $s->id)->unique('replaced_profile_id') as $h) {
                if ($h->replaced_profile_id !== null && $h->added_profile_id === null && ! isset($options[$h->replaced_profile_id])) {
                    $options[$h->replaced_profile_id] = 'released';
                }
            }
            $common = $common === null ? array_keys($options) : array_values(array_intersect($common, array_keys($options)));
            foreach ($options as $id => $state) {
                $states[$id] = $state;
            }
        }
        $person = fn (string $id): array => ['profile_id' => $id, 'name' => $names[$id] ?? ''];
        if ($common === [] || ($replacesId !== null && ! in_array(strtolower($replacesId), $common, true))) {
            throw ValidationException::withMessages(['replaces' => 'Изберете занимания на един и същ преподавател.']);
        }
        if ($replacesId === null && count($common ?? []) > 1) {
            return ['sessions' => [], 'choose_replaced' => array_map(fn ($id) => [...$person($id), 'state' => $states[$id]], $common), 'candidates' => []];
        }
        $replacesId = strtolower($replacesId ?? $common[0]);
        $requests = DB::table('substitute_request_sessions as i')->join('substitute_requests as r', 'r.id', '=', 'i.request_id')->where('i.organization_id', $org)->where('r.organization_id', $org)->whereIn('i.session_id', $ids)->where('i.status', 'open')->get(['r.id', 'r.version', 'r.suggested_profile_id', 'i.session_id', 'i.requester_profile_id']);
        $suggested = $requests->pluck('suggested_profile_id')->filter()->all();
        $assignments = DB::table('activity_group_instructors')->where('organization_id', $org)->whereIn('group_id', $sessions->pluck('group_id'))->get()->groupBy('group_id');
        $sessionRows = [];
        $canGoWithout = true;
        foreach ($sessions as $s) {
            $down = $steps[$s->id][$replacesId] ?? null;
            $on = $teachers->get($s->id, collect());
            $canGoWithout = $canGoWithout && $down !== null && $on->count() > 1;
            $sessionRows[] = ['id' => $s->id, 'version' => (int) $s->version, 'starts_at' => CarbonImmutable::parse($s->starts_at, 'UTC')->toIso8601String(), 'ends_at' => CarbonImmutable::parse($s->ends_at, 'UTC')->toIso8601String(), 'activity' => $s->activity, 'group' => $s->group_name, 'age_label' => $s->age_label, 'location' => $s->location,
                'teachers' => SessionRoles::derive($on->map(fn ($t) => [...$person($t->instructor_profile_id), 'replaces_instructor_profile_id' => $t->replaces_instructor_profile_id, 'replaces_name' => $t->replaces_instructor_profile_id === null ? null : ($names[$t->replaces_instructor_profile_id] ?? '')])->all(), $assignments->get($s->group_id, collect())->map(fn ($a) => [...$person($a->instructor_profile_id), 'role' => $a->role, 'starts_on' => $a->starts_on, 'ends_on' => $a->ends_on])->all(), $s->lead_instructor_profile_id, CarbonImmutable::parse($s->starts_at, 'UTC')->setTimezone('Europe/Sofia')->toDateString()), 'steps_down' => $down === null ? null : $person($down)];
        }
        $members = DB::table('organization_memberships as m')->join('users as u', 'u.id', '=', 'm.user_id')->where('m.organization_id', $org)->where('m.status', 'active')->get(['m.user_id', 'm.role', 'u.email_verified_at', 'u.is_active'])->keyBy('user_id');
        $links = DB::table('activity_group_instructors as i')->join('activity_groups as g', 'g.id', '=', 'i.group_id')->join('offerings as o', 'o.id', '=', 'g.offering_id')->join('activities as a', 'a.id', '=', 'o.activity_id')->where('i.organization_id', $org)->where('g.organization_id', $org)->where('o.organization_id', $org)->where('a.organization_id', $org)->where('g.status', '!=', 'archived')->get(['i.*', 'g.name', 'o.activity_id', 'a.title']);
        $leads = DB::table('offerings as o')->join('activity_groups as g', 'g.offering_id', '=', 'o.id')->join('activities as a', 'a.id', '=', 'o.activity_id')->where('o.organization_id', $org)->where('g.organization_id', $org)->where('a.organization_id', $org)->where('g.status', '!=', 'archived')->whereNotNull('o.lead_instructor_profile_id')->get(['o.lead_instructor_profile_id', 'o.activity_id', 'g.name', 'a.title']);
        $activityIds = $sessions->pluck('activity_id')->all();
        $days = $sessions->groupBy('activity_id')->map(fn ($rows) => $rows->map(fn ($row) => CarbonImmutable::parse($row->starts_at, 'UTC')->setTimezone('Europe/Sofia')->toDateString())->all());
        $allDays = $days->flatten()->all();
        $candidates = [];
        $warningPeople = [];
        foreach ($profiles as $p) {
            if ($p->staff_member_id === null || ! isset($staff[$p->staff_member_id])) {
                continue;
            }
            $userId = $staff[$p->staff_member_id]->user_id;
            $aliases = $profiles->filter(fn ($alias) => $alias->staff_member_id === $p->staff_member_id || ($userId !== null && $alias->user_id === $userId))->keys()->all();
            $isOriginal = in_array($replacesId, $aliases, true);
            $present = $teachers->flatten(1)->contains(fn ($t) => in_array($t->instructor_profile_id, $aliases, true));
            if ($present || ($isOriginal && $p->id !== $replacesId)) {
                continue;
            }
            $groups = [];
            $section = 'others';
            foreach ($links->whereIn('instructor_profile_id', $aliases) as $link) {
                if ($link->ends_on !== null && ! array_any($days->get($link->activity_id, $allDays), fn ($day) => $link->ends_on >= $day)) {
                    continue;
                }
                $activity = in_array($link->activity_id, $activityIds, true);
                $section = $activity ? 'activity' : $section;
                $groups[] = ($link->role === 'assistant' ? 'помага в ' : 'води ').($activity ? '' : $link->title.' · ').$link->name;
            }
            foreach ($leads->whereIn('lead_instructor_profile_id', $aliases) as $lead) {
                $activity = in_array($lead->activity_id, $activityIds, true);
                $section = $activity ? 'activity' : $section;
                $groups[] = 'води '.($activity ? '' : $lead->title.' · ').$lead->name;
            }
            $member = $userId === null ? null : $members->get($userId);
            $hasAccess = $member !== null && $member->is_active && OrganizationRole::tryFrom($member->role)?->isTeam();
            $candidates[$p->id] = ['profile_id' => $p->id, 'name' => $names[$p->id], 'section' => $section, 'groups' => array_values(array_unique($groups)), 'return' => $isOriginal, 'has_access' => (bool) $hasAccess, 'email_confirmed' => $hasAccess && $member->email_verified_at !== null, 'suggested' => count(array_intersect($aliases, $suggested)) > 0];
            $warningPeople[$p->id] = ['name' => $names[$p->id], 'aliases' => $aliases];
        }
        $warnings = $this->warnings->read($sessions->all(), $warningPeople);
        foreach ($candidates as $id => &$candidate) {
            $candidate['warnings'] = $warnings[$id] ?? [];
            $types = array_column($candidate['warnings'], 'type');
            $candidate['state'] = in_array('substitute_away', $types, true) ? 'away' : (in_array('instructor', $types, true) ? 'busy' : 'free');
            $candidate['busy_in'] = count(array_unique(array_column($candidate['warnings'], 'session_id')));
        }
        unset($candidate);
        usort($candidates, fn ($a, $b) => [! $a['return'], $a['section'] !== 'activity', $a['state'] !== 'free', mb_strtolower($a['name']), $a['profile_id']] <=> [! $b['return'], $b['section'] !== 'activity', $b['state'] !== 'free', mb_strtolower($b['name']), $b['profile_id']]);

        return ['sessions' => $sessionRows, 'replaces' => $person($replacesId), 'can_go_without' => $canGoWithout, 'open_requests' => $requests->filter(fn ($row) => $row->requester_profile_id === ($steps[$row->session_id][$replacesId] ?? null))->groupBy('id')->map(fn ($rows) => ['id' => $rows->first()->id, 'version' => (int) $rows->first()->version, 'session_ids' => $rows->pluck('session_id')->unique()->values()->all()])->values()->all(), 'candidates' => $candidates];
    }
}
