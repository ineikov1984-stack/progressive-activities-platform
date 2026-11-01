<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\InstructorProfile;
use App\Domain\Catalog\Models\Session;
use App\Domain\Catalog\Models\SubstituteRequest;
use App\Domain\Catalog\Models\SubstituteRequestSession;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Organizations\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Teacher-facing read projections. Expiry here is a projection, never a write. */
final readonly class SubstituteRequests
{
    public function __construct(private TenantContext $context, private TeachingScope $scope) {}

    public function authorize(User $actor): void
    {
        $membership = OrganizationMembership::query()->where('user_id', $actor->id)->where('status', 'active')->first();
        $role = $membership === null ? null : OrganizationRole::tryFrom($membership->role);
        abort_unless($role?->isTeam(), 403, 'Заместник се иска от хората в екипа.');
    }

    /** @return array<string, mixed> */
    public function mine(User $actor): array
    {
        $this->authorize($actor);
        $profiles = $this->scope->profilesFor($actor);
        $since = CarbonImmutable::now('Europe/Sofia')->startOfDay()->utc();
        $eligible = SubstituteRequestSession::query()->whereIn('session_id', Session::query()->where('ends_at', '>=', $since)->select('id'));
        $ids = (clone $eligible)->select('request_id');
        $requests = SubstituteRequest::query()->where('requester_user_id', $actor->id)->whereIn('requester_profile_id', $profiles)->whereIn('id', $ids)->whereIn('id', SubstituteRequestSession::query()->where('status', '!=', 'withdrawn')->select('request_id'))->orderByDesc('created_at')->orderByDesc('id')->limit(50)->get();
        $items = SubstituteRequestSession::query()->whereIn('request_id', $requests->pluck('id'))->get();
        $standingIds = DB::table('activity_session_instructors')->where('organization_id', $this->context->organizationId())->whereIn('instructor_profile_id', $profiles)->whereNotNull('replaces_instructor_profile_id')->pluck('session_id');
        $standing = Session::query()->whereIn('id', $standingIds)->where('status', 'scheduled')->where('ends_at', '>=', $since->subDay())->with(['group.offering.activity', 'location'])->orderBy('starts_at')->get();
        $sessions = Session::query()->whereIn('id', $items->pluck('session_id'))->with(['group.offering.activity', 'location'])->get()->merge($standing)->keyBy('id');
        $data = $this->data($items, $sessions, $requests);
        $standingRows = [];
        foreach ($standing as $session) {
            foreach ($data['teachers'][$session->id] ?? [] as $teacher) {
                if (! in_array($teacher->instructor_profile_id, $profiles, true) || $teacher->replaces_instructor_profile_id === null) {
                    continue;
                }
                $standingRows[] = [...$this->session($session), 'replaces' => ['name' => $data['profiles'][$teacher->replaces_instructor_profile_id]->public_name ?? ''],
                    'until' => $session->starts_at->setTimezone('Europe/Sofia')->addDay()->endOfDay()->utc()->toIso8601String(), 'children_now' => TeachingScope::standsInNow($session->starts_at)];
            }
        }

        return ['requests' => $requests->map(fn ($request) => $this->serialize($request, $items->where('request_id', $request->id), $sessions, $data))->all(), 'standing_in' => $standingRows,
            'email_confirmed' => $actor->email_verified_at !== null, 'now' => CarbonImmutable::now('UTC')->toIso8601String()];
    }

    /** @return array<string, mixed> */
    public function request(SubstituteRequest $request): array
    {
        abort_unless($request->organization_id === $this->context->organizationId(), 404);
        $items = SubstituteRequestSession::query()->where('request_id', $request->id)->get();
        $sessions = Session::query()->whereIn('id', $items->pluck('session_id'))->with(['group.offering.activity', 'location'])->get()->keyBy('id');

        return $this->serialize($request, $items, $sessions, $this->data($items, $sessions, collect([$request])));
    }

    /** @return array<string, mixed> */
    public function options(User $actor, string $from, string $to): array
    {
        $this->authorize($actor);
        try {
            $start = CarbonImmutable::createFromFormat('!Y-m-d', $from, 'Europe/Sofia');
            $end = CarbonImmutable::createFromFormat('!Y-m-d', $to, 'Europe/Sofia');
        } catch (\Throwable) {
            throw ValidationException::withMessages(['from' => 'Изберете ден от днес до една година напред (най-много 31 дни).']);
        }
        $today = CarbonImmutable::now('Europe/Sofia')->startOfDay();
        if ($start->format('Y-m-d') !== $from || $end->format('Y-m-d') !== $to || $start->lt($today) || $end->lt($start) || $end->gt($today->addDays(365)) || $end->gt($start->addDays(31))) {
            throw ValidationException::withMessages(['from' => 'Изберете ден от днес до една година напред (най-много 31 дни).']);
        }
        $profiles = $this->scope->profilesFor($actor);
        $ids = DB::table('activity_session_instructors')->where('organization_id', $this->context->organizationId())->whereIn('instructor_profile_id', $profiles)->select('session_id');
        $sessions = Session::query()->whereIn('id', $ids)->where('status', 'scheduled')->where('starts_at', '>', CarbonImmutable::now('UTC'))->where('starts_at', '>=', $start->utc())->where('starts_at', '<', $end->addDay()->utc())
            ->whereHas('group', fn ($q) => $q->whereNotIn('status', ['draft', 'archived']))->with(['group.offering.activity', 'group.assignments', 'location'])->orderBy('starts_at')->orderBy('id')->get();
        $items = SubstituteRequestSession::query()->whereIn('session_id', $sessions->pluck('id'))->whereIn('requester_profile_id', $profiles)->where('status', 'open')->get();
        $teachers = DB::table('activity_session_instructors')->where('organization_id', $this->context->organizationId())->whereIn('session_id', $sessions->pluck('id'))->get()->groupBy('session_id');
        $result = [];
        foreach ($sessions as $session) {
            $on = ($teachers[$session->id] ?? collect())->map(fn ($t) => ['profile_id' => $t->instructor_profile_id, 'name' => '', 'replaces_instructor_profile_id' => $t->replaces_instructor_profile_id])->all();
            $assignments = $session->group->assignments->map(fn ($p) => ['profile_id' => $p->id, 'name' => $p->public_name, ...GroupTeachers::assignment($p)])->all();
            $roles = SessionRoles::derive($on, $assignments, $session->group->offering->lead_instructor_profile_id, $session->starts_at->setTimezone('Europe/Sofia')->toDateString());
            $role = collect($roles)->first(fn ($r) => in_array($r['profile_id'], $profiles, true));
            $base = $this->session($session);
            unset($base['session_id'], $base['age_label']);
            $result[] = ['id' => $session->id, 'version' => $session->version, ...$base, 'activity_id' => $session->group->offering->activity_id, 'role' => $role['role'] ?? 'lead', 'requested' => $items->contains('session_id', $session->id)];
        }

        return ['sessions' => $result, 'colleagues' => $this->colleagues($profiles, $sessions), 'now' => CarbonImmutable::now('UTC')->toIso8601String()];
    }

    /** Suggestions from active linked staff in the selected activities, without contact details. */
    /**
     * @param  list<string>  $ownProfiles
     * @param  Collection<int, Session>  $sessions
     * @return list<array{profile_id:string,name:string,groups:list<string>}>
     */
    public function colleagues(array $ownProfiles, Collection $sessions): array
    {
        $org = $this->context->organizationId();
        $activities = $sessions->map(fn ($s) => $s->group->offering->activity_id)->unique()->all();
        $groups = DB::table('activity_groups')->join('offerings', 'offerings.id', '=', 'activity_groups.offering_id')->join('activities', 'activities.id', '=', 'offerings.activity_id')->where('activity_groups.organization_id', $org)->where('offerings.organization_id', $org)->whereIn('offerings.activity_id', $activities)->where('activity_groups.status', '!=', 'archived')->get(['activity_groups.id', 'activity_groups.name', 'activities.title', 'offerings.id as offering_id', 'offerings.lead_instructor_profile_id']);
        $assignments = DB::table('activity_group_instructors')->where('organization_id', $org)->whereIn('group_id', $groups->pluck('id'))->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', CarbonImmutable::now('Europe/Sofia')->toDateString()))->get();
        $links = [];
        foreach ($groups as $group) {
            foreach ($assignments->where('group_id', $group->id)->pluck('instructor_profile_id')->push($sessions->pluck('group.offering_id')->contains($group->offering_id) ? $group->lead_instructor_profile_id : null)->filter() as $id) {
                $links[$id][] = $group->title.' · '.$group->name;
            }
        }
        $profiles = InstructorProfile::query()->whereIn('id', array_keys($links))->whereNotIn('id', $ownProfiles)->whereIn('staff_member_id', DB::table('staff_members')->where('organization_id', $org)->whereNull('archived_at')->select('id'))->orderBy('public_name')->orderBy('id')->limit(50)->get();

        return $profiles->map(fn ($p) => ['profile_id' => $p->id, 'name' => $p->public_name, 'groups' => array_values(array_unique($links[$p->id]))])->all();
    }

    /**
     * @param  Collection<int, SubstituteRequestSession>  $items
     * @param  Collection<string, Session>  $sessions
     * @param  Collection<int, SubstituteRequest>  $requests
     * @return array<string, mixed>
     */
    private function data(Collection $items, Collection $sessions, Collection $requests): array
    {
        $teachers = DB::table('activity_session_instructors')->where('organization_id', $this->context->organizationId())->whereIn('session_id', $sessions->keys())->get();
        $ids = [...$items->pluck('substitute_profile_id'), ...$requests->pluck('suggested_profile_id'), ...$teachers->pluck('instructor_profile_id'), ...$teachers->pluck('replaces_instructor_profile_id')];

        return ['profiles' => InstructorProfile::query()->whereIn('id', array_filter($ids))->get()->keyBy('id'), 'teachers' => $teachers->groupBy('session_id'), 'users' => User::query()->whereIn('id', $items->pluck('decided_by_user_id')->filter())->get()->keyBy('id')];
    }

    /**
     * @param  Collection<int, SubstituteRequestSession>  $items
     * @param  Collection<string, Session>  $sessions
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function serialize(SubstituteRequest $request, Collection $items, Collection $sessions, array $data): array
    {
        $person = fn ($id) => $id !== null && isset($data['profiles'][$id]) ? ['profile_id' => $id, 'name' => $data['profiles'][$id]->public_name] : null;
        $rows = [];
        foreach ($items->sortBy(fn ($i) => $sessions[$i->session_id]->starts_at->format('c').$i->session_id) as $item) {
            $session = $sessions[$item->session_id];
            $expired = SubstituteRequestLocks::expiredReason($item, $session, CarbonImmutable::now('UTC'));
            $teachers = $data['teachers'][$item->session_id] ?? collect();
            $current = $teachers->firstWhere('replaces_instructor_profile_id', $request->requester_profile_id);
            $decider = $data['users'][$item->decided_by_user_id] ?? null;
            $rows[] = [...$this->session($session), 'state' => $expired !== null ? 'expired' : $item->status, 'closed_reason' => $expired ?? $item->closed_reason,
                'substitute' => $person($item->substitute_profile_id), 'decided_by' => $decider === null ? null : ['id' => $decider->id, 'name' => $decider->name], 'decided_at' => $item->closed_at === null ? null : CarbonImmutable::parse($item->closed_at)->toIso8601String(),
                'now' => ['teaching' => $teachers->contains('instructor_profile_id', $request->requester_profile_id), 'substitute' => $current === null ? null : $person($current->instructor_profile_id)]];
        }

        return ['id' => $request->id, 'version' => $request->version, 'created_at' => $request->created_at->toIso8601String(), 'reason' => $request->reason, 'note' => $request->note, 'suggested' => $person($request->suggested_profile_id), 'sessions' => $rows];
    }

    /** @return array<string, mixed> */
    private function session(Session $session): array
    {
        return ['session_id' => $session->id, 'starts_at' => $session->starts_at->toIso8601String(), 'ends_at' => $session->ends_at->toIso8601String(), 'activity' => $session->group->offering->activity->title, 'group' => $session->group->name, 'age_label' => $session->group->age_label, 'location' => $session->location?->name];
    }
}
