<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\InstructorProfile;
use App\Domain\Catalog\Models\Session;
use App\Domain\Catalog\Models\SubstituteRequest;
use App\Domain\Catalog\Models\SubstituteRequestSession;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Office projections share no teacher ownership restriction. Reads never persist expiry. */
final readonly class OfficeSubstituteRequests
{
    public function __construct(private AccessScope $scope, private TenantContext $context, private SubstituteRequests $reader, private InstructorNames $names) {}

    public function authorize(User $actor): void
    {
        $access = $this->scope->access($actor);
        abort_unless($access->role?->isTeam() && $access->allowsAcrossOrganization('schedule.manage') && $access->allowsAcrossOrganization('team.assign'), 403, 'Заместник назначават собственикът, администраторът и координаторът.');
    }

    /** @return array<string, mixed> */
    public function listing(User $actor): array
    {
        $this->authorize($actor);
        $now = CarbonImmutable::now('UTC');
        $org = $this->context->organizationId();
        $ids = DB::table('substitute_request_sessions as items')
            ->join('activity_sessions as sessions', 'sessions.id', '=', 'items.session_id')
            ->join('activity_groups as groups', 'groups.id', '=', 'sessions.group_id')
            ->where('items.organization_id', $org)->where('sessions.organization_id', $org)->where('groups.organization_id', $org)
            ->where('items.status', 'open')->where('sessions.status', 'scheduled')->where('sessions.starts_at', '>', $now)->where('groups.status', '!=', 'archived')
            ->groupBy('items.request_id')->orderByRaw('MIN(sessions.starts_at)')->orderBy('items.request_id')->limit(100)->pluck('items.request_id');
        $requests = SubstituteRequest::query()->whereIn('id', $ids)->get()->keyBy('id');
        $items = SubstituteRequestSession::query()->whereIn('request_id', $ids)->where('status', 'open')->get();
        $sessions = Session::query()->whereIn('id', $items->pluck('session_id'))->with(['group.offering.activity', 'location'])->get()->keyBy('id');
        $profiles = InstructorProfile::query()->whereIn('id', $requests->pluck('requester_profile_id')->merge($requests->pluck('suggested_profile_id'))->filter())->get()->keyBy('id');
        $names = $this->names->forIds($profiles->modelKeys());
        $result = [];
        foreach ($ids as $id) {
            $request = $requests[$id];
            $rows = [];
            foreach ($items->where('request_id', $id)->sortBy(fn ($i) => $sessions[$i->session_id]->starts_at->format('c').$i->session_id) as $item) {
                $session = $sessions[$item->session_id];
                if (SubstituteRequestLocks::expiredReason($item, $session, $now) !== null) {
                    continue;
                }
                $rows[] = ['session_id' => $session->id, 'version' => $session->version, 'starts_at' => $session->starts_at->toIso8601String(), 'ends_at' => $session->ends_at->toIso8601String(), 'activity' => $session->group->offering->activity->title, 'group' => $session->group->name, 'age_label' => $session->group->age_label, 'location' => $session->location?->name];
            }
            $result[] = ['id' => $request->id, 'version' => $request->version, 'created_at' => $request->created_at->toIso8601String(), 'requester' => ['profile_id' => $request->requester_profile_id, 'name' => $names[$request->requester_profile_id]], 'reason' => $request->reason, 'note' => $request->note,
                'suggested' => $request->suggested_profile_id === null ? null : ['profile_id' => $request->suggested_profile_id, 'name' => $names[$request->suggested_profile_id]], 'sessions' => $rows];
        }

        return ['requests' => $result, 'now' => $now->toIso8601String()];
    }

    /** @return array<string, mixed> */
    public function detail(User $actor, string $requestId): array
    {
        $this->authorize($actor);
        $request = SubstituteRequest::query()->whereKey(strtolower($requestId))->first();
        abort_if($request === null, 404, 'Заявката не е намерена.');
        $result = $this->reader->request($request);
        $profile = InstructorProfile::query()->findOrFail($request->requester_profile_id);
        $versions = Session::query()->whereIn('id', array_column($result['sessions'], 'session_id'))->pluck('version', 'id');
        foreach ($result['sessions'] as &$session) {
            $session['version'] = $versions[$session['session_id']];
        }
        unset($session);

        return [...$result, 'requester' => ['profile_id' => $profile->id, 'name' => $this->names->forIds([$profile->id])[$profile->id]], 'now' => CarbonImmutable::now('UTC')->toIso8601String()];
    }
}
