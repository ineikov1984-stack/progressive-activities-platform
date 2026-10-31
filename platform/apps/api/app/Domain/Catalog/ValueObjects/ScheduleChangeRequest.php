<?php

namespace App\Domain\Catalog\ValueObjects;

/**
 * What the office asks of the schedule (docs/product/schedule-changes.md, т. 7.1), its form already checked: the action,
 * the sessions with the versions that were seen, the reason and whether it goes to the parents, and the warnings that
 * were seen and confirmed. Identifiers in lower case, the reason without the spaces around it. The preview and the
 * change are worked out from the same request, so they say the same.
 */
final readonly class ScheduleChangeRequest
{
    /** @var array<string, true> */
    private array $confirmedKeys;

    /**
     * @param  list<array{id: string, version: int}>  $sessions
     * @param  list<array{type: string, session_id: string, other_session_id: string|null}>  $confirmedWarnings
     */
    public function __construct(
        public string $action,
        public array $sessions,
        public ?string $reason,
        public bool $reasonToParents,
        public bool $notifyParents,
        public array $confirmedWarnings,
        public ?string $date = null,
        public ?string $from = null,
        public ?string $to = null,
        public bool $locationProvided = false,
        public ?string $locationId = null,
        public ?string $groupId = null,
        /** @var list<string> */
        public array $days = [],
        /** @var list<string> */
        public array $locations = [],
        /** @var list<string> */
        public array $activities = [],
    ) {
        $this->confirmedKeys = array_fill_keys(array_map(
            fn (array $key): string => $key['type'].':'.$key['session_id'].':'.($key['other_session_id'] ?? ''), $confirmedWarnings), true);
    }

    /** @return list<string> */
    public function sessionIds(): array
    {
        return array_values(array_unique(array_column($this->sessions, 'id')));
    }

    public function versionOf(string $sessionId): ?int
    {
        foreach ($this->sessions as $session) {
            if ($session['id'] === $sessionId) {
                return $session['version'];
            }
        }

        return null;
    }

    /**
     * The person saw this warning and confirmed it: its key is among those sent (т. 4.8). A key whose warning is gone
     * does no harm; a new warning is never covered by an old key.
     *
     * @param  array{type: string, session_id: string, other_session_id: string|null}  $warning
     */
    public function confirms(array $warning): bool
    {
        return isset($this->confirmedKeys[$warning['type'].':'.$warning['session_id'].':'.($warning['other_session_id'] ?? '')]);
    }

    /**
     * SHA-256 of the normalized body (т. 7.3): sorted keys, the sessions and the confirmed warnings in a fixed order.
     * A repeated request with the same body gets the stored answer; another body under the same key is refused.
     */
    public function hash(): string
    {
        $sessions = $this->sessions;
        usort($sessions, fn (array $a, array $b): int => [$a['id'], $a['version']] <=> [$b['id'], $b['version']]);
        $warnings = $this->confirmedWarnings;
        usort($warnings, fn (array $a, array $b): int => [$a['type'], $a['session_id'], (string) $a['other_session_id']]
            <=> [$b['type'], $b['session_id'], (string) $b['other_session_id']]);

        $criteria = ['days' => $this->days, 'locations' => $this->locations, 'activities' => $this->activities];
        foreach ($criteria as &$values) {
            sort($values);
        }
        unset($values);

        return hash('sha256', json_encode(self::sorted([
            'action' => $this->action,
            'confirmed_warnings' => $warnings,
            'notify_parents' => $this->notifyParents,
            'reason' => $this->reason,
            'reason_to_parents' => $this->reasonToParents,
            'sessions' => $sessions,
            'date' => $this->date, 'from' => $this->from, 'to' => $this->to,
            'location_provided' => $this->locationProvided, 'location_id' => $this->locationId,
            ...($this->action === 'add' ? ['group_id' => $this->groupId] : []),
            ...($this->action === 'holiday' ? ['criteria' => $criteria] : []),
        ]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function sorted(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $item): mixed => is_array($item) ? self::sorted($item) : $item, $value);
    }
}
