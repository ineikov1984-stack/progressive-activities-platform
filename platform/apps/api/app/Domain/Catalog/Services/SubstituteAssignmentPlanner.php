<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\InstructorProfile;
use App\Domain\Catalog\Models\Session;
use App\Domain\Catalog\Models\SubstituteRequest;
use App\Domain\Catalog\Models\SubstituteRequestSession;
use App\Domain\Catalog\ValueObjects\PlannedSessionChange;
use App\Domain\Catalog\ValueObjects\ScheduleChangeRequest;
use App\Domain\Organizations\Models\StaffMember;
use App\Domain\Organizations\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** The original teacher remains the reference through chains, releases and returns. */
final readonly class SubstituteAssignmentPlanner
{
    public function __construct(private GroupTeachers $teachers, private SubstituteWarnings $warnings, private TenantContext $context) {}

    /**
     * @param  Collection<int, Session>  $sessions
     * @return list<PlannedSessionChange>
     */
    public function plan(ScheduleChangeRequest $request, Collection $sessions, CarbonImmutable $now): array
    {
        if (count($request->sessions) > 50 || $request->sessions === []) {
            throw ValidationException::withMessages(['sessions' => 'Изберете най-много 50 занимания за заместник.']);
        }
        $original = InstructorProfile::query()->find($request->replacesProfileId);
        if ($original === null) {
            throw ValidationException::withMessages(['replaces_profile_id' => 'Преподавателят не е намерен.']);
        }
        $incoming = $request->substituteProfileId === null ? null : InstructorProfile::query()->find($request->substituteProfileId);
        if ($request->substituteProfileId !== null) {
            $person = $incoming === null || $incoming->staff_member_id === null ? null : StaffMember::query()->find($incoming->staff_member_id);
            if ($person === null) {
                throw ValidationException::withMessages(['substitute_profile_id' => 'Преподавателят не е намерен.']);
            }
            if ($person->archived_at !== null) {
                throw ValidationException::withMessages(['substitute_profile_id' => 'Преподавателят е архивиран.']);
            }
        }
        $history = DB::table('session_changes')->where('organization_id', $this->context->organizationId())->whereIn('session_id', $sessions->modelKeys())->where('replaced_profile_id', $original->id)
            ->orderByDesc('created_at')->orderByDesc('id')->get()->unique('session_id')->keyBy('session_id');
        $source = null;
        $items = collect();
        if ($request->requestId !== null) {
            $source = SubstituteRequest::query()->find($request->requestId);
            abort_if($source === null, 404, 'Заявката не е намерена.');
            abort_unless($source->version === $request->requestVersion, 409, 'Заявката е променена междувременно. Заредете отново.');
            $items = SubstituteRequestSession::query()->where('request_id', $source->id)->whereIn('session_id', $sessions->modelKeys())->get()->keyBy('session_id');
        }
        $planned = [];
        foreach ($sessions as $session) {
            if ($session->group->status === 'archived' || $session->status !== 'scheduled' || $session->starts_at->lte($now)) {
                throw ValidationException::withMessages(['sessions' => $session->starts_at->lte($now) ? 'Заниманието вече е започнало.' : 'Заниманието не може да бъде променено.']);
            }
            $rows = [];
            foreach ($session->instructors as $teacher) {
                /** @var Pivot $pivot */
                $pivot = $teacher->getRelation('pivot');
                $rows[$teacher->id] = ['role' => (string) $pivot->getAttribute('role'), 'replaces_instructor_profile_id' => $pivot->getAttribute('replaces_instructor_profile_id')];
            }
            $removed = isset($rows[$original->id]) && $rows[$original->id]['replaces_instructor_profile_id'] === null ? $original->id : null;
            if ($removed === null) {
                foreach ($rows as $id => $row) {
                    if ($row['replaces_instructor_profile_id'] === $original->id) {
                        $removed = $id;
                        break;
                    }
                }
            }
            if ($removed === null && (! isset($history[$session->id]) || $history[$session->id]->added_profile_id !== null)) {
                throw ValidationException::withMessages(['replaces_profile_id' => $original->public_name.' не води това занимание.']);
            }
            if ($source !== null) {
                $item = $items->get($session->id);
                if ($removed !== $source->requester_profile_id || $item === null || $item->status !== 'open' || SubstituteRequestLocks::expiredReason($item, $session, $now) !== null) {
                    throw ValidationException::withMessages(['request' => 'Заниманието не е в заявката или вече е решено.']);
                }
            }
            if ($incoming !== null && isset($rows[$incoming->id])) {
                throw ValidationException::withMessages(['substitute_profile_id' => $incoming->public_name.' вече е на това занимание.']);
            }
            $beforeIds = array_keys($rows);
            $assigned = $this->teachers->forSession($session->group, $session->starts_at, $session->group->assignments);
            $role = $removed === null ? ($assigned[$original->id]['role'] ?? 'lead') : ($removed === $original->id ? ($assigned[$original->id]['role'] ?? $rows[$removed]['role']) : $rows[$removed]['role']);
            if ($removed !== null) {
                unset($rows[$removed]);
            }
            if ($incoming === null) {
                if ($removed === null || $rows === []) {
                    throw ValidationException::withMessages(['substitute_profile_id' => $removed === null ? 'Нищо не е променено.' : 'Заниманието ще остане без преподавател. Изберете заместник или отменете заниманието.']);
                }
            } else {
                $returning = $incoming->id === $original->id;
                $rows[$incoming->id] = ['role' => $returning ? ($assigned[$original->id]['role'] ?? $role) : $role, 'replaces_instructor_profile_id' => $returning ? null : $original->id];
            }
            $roles = array_map(fn ($r) => ['role' => $r['role']], $rows);
            ksort($roles);
            ksort($assigned);
            $after = clone $session;
            $after->version++;
            $after->instructors_source = $roles === $assigned && ! array_filter(array_column($rows, 'replaces_instructor_profile_id')) ? 'assignment' : 'manual';
            $planned[] = new PlannedSessionChange($session, $after, $rows, $beforeIds, array_keys($rows), 'substituted', $original->id, $removed, $incoming?->id);
        }

        return $planned;
    }

    /** @param list<PlannedSessionChange> $planned
     * @return list<array{type:string,session_id:string,other_session_id:string|null,needs_confirmation:bool,message:string}>
     */
    public function warnings(array $planned): array
    {
        $id = $planned[0]->addedProfileId ?? null;
        if ($id === null) {
            return [];
        }
        $profile = InstructorProfile::query()->findOrFail($id);
        $person = StaffMember::query()->findOrFail($profile->staff_member_id);
        $userId = $person->user_id;
        $aliases = InstructorProfile::query()->where(fn ($q) => $q->where('staff_member_id', $profile->staff_member_id)->when($userId !== null, fn ($q) => $q->orWhere('user_id', $userId)))->pluck('id')->all();
        $result = $this->warnings->read(array_map(fn ($p) => $p->after, $planned), [$id => ['name' => $profile->public_name, 'aliases' => $aliases]]);

        return $result[$id] ?? [];
    }
}
