<?php

namespace App\Domain\Catalog\ValueObjects;

/**
 * Which sessions of the schedule a person sees (docs/product/schedule.md, т. 2): every session of the organization, or
 * every session of some groups and, in a group where the person only stands in, just the sessions they are on.
 */
final readonly class ScheduleScope
{
    /**
     * @param  list<string>  $groupIds  every session of these groups (not with `all`)
     * @param  list<string>  $standInGroupIds  groups where the person only stands in
     * @param  list<string>  $sessionIds  the person's sessions in those groups — only these of them
     */
    public function __construct(
        public bool $all,
        public array $groupIds,
        public array $standInGroupIds,
        public array $sessionIds,
    ) {}

    public static function everything(): self
    {
        return new self(true, [], [], []);
    }

    public static function nothing(): self
    {
        return new self(false, [], [], []);
    }

    public function isEmpty(): bool
    {
        return ! $this->all && $this->groupIds === [] && $this->sessionIds === [];
    }

    public function covers(string $groupId, string $sessionId): bool
    {
        return $this->coversGroup($groupId) || in_array($sessionId, $this->sessionIds, true);
    }

    /** Every session of the group is seen: its places, its teachers and its lead belong to what the person may know. */
    public function coversGroup(string $groupId): bool
    {
        return $this->all || in_array($groupId, $this->groupIds, true);
    }

    /**
     * The groups with something to see; null — every group.
     *
     * @return list<string>|null
     */
    public function groups(): ?array
    {
        return $this->all ? null : array_values(array_unique([...$this->groupIds, ...$this->standInGroupIds]));
    }
}
