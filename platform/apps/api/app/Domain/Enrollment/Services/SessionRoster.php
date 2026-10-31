<?php

namespace App\Domain\Enrollment\Services;

use App\Domain\Catalog\Models\Session;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\EnrollmentSessionException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

/**
 * Who is at a session, for the schedule (docs/product/schedule.md, т. 5, 6): the children who take a place — the same
 * rule as the places taken (`SessionApplicability::appliesTo`), trials and single visits for it included — and those
 * who would, but are on a pause or skip it, said in words. A cancelled session takes no place; its panel still names
 * whom it concerned, as if it ran. Name and class only: never contacts, health or money.
 */
final readonly class SessionRoster
{
    public function __construct(private SessionApplicability $applicability) {}

    /**
     * The children of the session, by name; `child_id` opens the card of the child (docs/product/schedule.md, т. 4.1).
     *
     * @return list<array{enrollment_id: string, child_id: string, name: string, class: string|null, state: string, scope: string}>
     */
    public function children(Session $session): array
    {
        $probe = self::asScheduled($session);
        $enrollments = $this->applicability->candidates(new SupportCollection([$probe]));
        $enrollments->load('child');
        $rows = [];
        foreach ($enrollments as $enrollment) {
            $state = $this->state($enrollment, $probe);
            if ($state === null) {
                continue;
            }
            $child = $enrollment->child;
            $rows[] = [
                'enrollment_id' => (string) $enrollment->id,
                'child_id' => (string) $enrollment->child_id,
                'name' => $child === null ? '' : trim($child->first_name.' '.$child->last_name),
                'class' => $child?->class_label,
                'state' => $state,
                'scope' => $enrollment->scope->value,
            ];
        }
        usort($rows, fn (array $a, array $b): int => [mb_strtolower($a['name']), $a['enrollment_id']] <=> [mb_strtolower($b['name']), $b['enrollment_id']]);

        return $rows;
    }

    /**
     * The sessions where the child is among the children — only through the child's enrollments in these groups (null —
     * every group), so that the schedule never tells where else the child goes (docs/product/schedule.md, т. 6.1).
     *
     * @param  list<string>|null  $groupIds
     * @param  SupportCollection<int, Session>  $sessions
     * @return list<string>
     */
    public function sessionsWithChild(string $childId, ?array $groupIds, SupportCollection $sessions): array
    {
        $probes = [];
        foreach ($sessions as $session) {
            if ($groupIds === null || in_array((string) $session->group_id, $groupIds, true)) {
                $probes[] = self::asScheduled($session);
            }
        }
        $byGroup = [];
        foreach ($this->applicability->candidates(new SupportCollection($probes), $childId) as $enrollment) {
            $byGroup[(string) $enrollment->group_id][] = $enrollment;
        }
        $found = [];
        foreach ($probes as $session) {
            foreach ($byGroup[(string) $session->group_id] ?? [] as $enrollment) {
                if ($this->state($enrollment, $session) !== null) {
                    $found[] = (string) $session->id;
                    break;
                }
            }
        }

        return $found;
    }

    /** "active" — takes a place; "skipped" — skips this session; "paused" — on a pause that day; null — not at it. */
    private function state(Enrollment $enrollment, Session $session): ?string
    {
        if ($this->applicability->appliesTo($enrollment, $session)) {
            return 'active';
        }
        // Without the skipped sessions and the pauses: would the child be at it?
        $free = clone $enrollment;
        $free->setRelation('sessionExceptions', new Collection);
        $free->setRelation('pauses', new Collection);
        if (! $this->applicability->appliesTo($free, $session)) {
            return null;
        }

        return $enrollment->sessionExceptions->contains(
            fn (EnrollmentSessionException $exception): bool => $exception->kind === 'excluded' && $exception->session_id === $session->id,
        ) ? 'skipped' : 'paused';
    }

    /** The session as if it ran: who it concerns, also when it was cancelled. */
    private static function asScheduled(Session $session): Session
    {
        if ($session->status === 'scheduled') {
            return $session;
        }
        $probe = clone $session;
        $probe->setAttribute('status', 'scheduled');

        return $probe;
    }
}
