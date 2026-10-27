<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Enums\LocationType;
use App\Domain\Catalog\Enums\SpaceUsage;
use App\Domain\Catalog\Models\Group;
use App\Domain\Catalog\Models\Session;
use App\Domain\Organizations\Tenancy\TenantContext;

final readonly class ScheduleConflictDetector
{
    public function __construct(private TenantContext $context) {}

    /** @return list<array{type: string, session_id: string, conflicting_session_id: string, blocking: bool, message: string}> */
    public function forGroup(Group $group): array
    {
        if (! $this->context->hasOrganization() || $group->organization_id !== $this->context->organizationId() || $group->status === 'archived') {
            return [];
        }
        $conflicts = [];
        $seen = [];
        foreach ($group->sessions()->where('status', 'scheduled')->with(['location', 'instructors'])->orderBy('starts_at')->get() as $session) {
            $others = Session::query()->where('status', 'scheduled')->whereKeyNot($session->id)
                ->whereHas('group', fn ($query) => $query->where('status', '!=', 'archived'))
                ->where('starts_at', '<', $session->ends_at)->where('ends_at', '>', $session->starts_at)
                ->with('instructors')->orderBy('starts_at')->orderBy('id')->get();
            foreach ($others as $other) {
                $pair = [$session->id, $other->id];
                sort($pair);
                $key = implode(':', $pair);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $base = ['session_id' => $session->id, 'conflicting_session_id' => $other->id];
                if ($session->instructors->modelKeys() !== [] && array_intersect($session->instructors->modelKeys(), $other->instructors->modelKeys()) !== []) {
                    $conflicts[] = [...$base, 'type' => 'instructor', 'blocking' => true, 'message' => 'Преподавател има друго занятие по същото време.'];
                }
                if ($session->location_id !== null && $session->location_id === $other->location_id && $session->location?->getAttribute('type') === LocationType::Physical) {
                    $exclusive = $session->location->getAttribute('space_usage') === SpaceUsage::Exclusive;
                    $conflicts[] = [...$base, 'type' => $exclusive ? 'exclusive_location' : 'shared_location', 'blocking' => $exclusive,
                        'message' => $exclusive ? 'Мястото за самостоятелно ползване е заето.' : 'Общото пространство се използва и от друга група.'];
                }
            }
        }

        return $conflicts;
    }
}
