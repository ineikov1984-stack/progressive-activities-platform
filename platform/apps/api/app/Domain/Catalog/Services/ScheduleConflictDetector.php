<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Enums\LocationType;
use App\Domain\Catalog\Enums\SpaceUsage;
use App\Domain\Catalog\Models\Group;
use App\Domain\Catalog\Models\Session;
use App\Domain\Catalog\Models\SessionChange;
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

        if ($conflicts === []) {
            return [];
        }
        // An overlap the office confirmed in the latest change of either session no longer stops anything
        // (docs/product/schedule-changes.md, т. 4.8): otherwise one confirmed overlap would stop publishing for good.
        $confirmed = $this->confirmed(array_values(array_unique(array_merge(
            array_column($conflicts, 'session_id'), array_column($conflicts, 'conflicting_session_id'),
        ))));

        return array_values(array_filter($conflicts, fn (array $conflict): bool => ! isset(
            $confirmed[self::pair($conflict['type'], $conflict['session_id'], $conflict['conflicting_session_id'])],
        )));
    }

    /**
     * The overlaps confirmed in the latest change of each session.
     *
     * @param  list<string>  $sessionIds
     * @return array<string, true>
     */
    private function confirmed(array $sessionIds): array
    {
        $latest = [];
        foreach (SessionChange::query()->whereIn('session_id', $sessionIds)->orderByDesc('schedule_change_id')
            ->get(['session_id', 'schedule_change_id', 'confirmed_overlaps']) as $change) {
            $latest[(string) $change->session_id] ??= $change;
        }
        $confirmed = [];
        foreach ($latest as $change) {
            foreach ($change->confirmed_overlaps as $key) {
                if (is_string($key['other_session_id'] ?? null)) {
                    $confirmed[self::pair((string) $key['type'], (string) $key['session_id'], $key['other_session_id'])] = true;
                }
            }
        }

        return $confirmed;
    }

    private static function pair(string $type, string $first, string $second): string
    {
        $ids = [$first, $second];
        sort($ids);

        return $type.':'.implode(':', $ids);
    }
}
