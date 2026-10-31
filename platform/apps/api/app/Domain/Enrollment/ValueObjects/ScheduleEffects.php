<?php

namespace App\Domain\Enrollment\ValueObjects;

/**
 * What a change of the schedule does to the enrollments, worked out once for the preview and again, under the locks,
 * for the change (docs/product/schedule-changes.md, т. 5, 7.2): what each family hears, by child and session, who comes
 * to each session, the starts that move, and the warning of too many children. Identifiers and numbers only.
 *
 * @phpstan-type Item array{parent_user_id: string, session_id: string, effect: string, enrollment_id: string|null, application_id: string|null, child_id: string}
 * @phpstan-type Warning array{type: string, session_id: string, other_session_id: string|null, needs_confirmation: bool, message: string}
 */
final readonly class ScheduleEffects
{
    /**
     * @param  list<Item>  $items  what each family hears: `cancelled`, `visit_cancelled`, `application_rejected`, `restored`, `visit_day_free_again`
     * @param  array<string, array{children: int, visits: int, families: int}>  $sessions  session => who comes before or after the change
     * @param  array<string, array{from: string, to: string}>  $starts  enrollment => its start before and after (т. 5.4)
     * @param  array<string, string>  $statuses  enrollment => its status now, for the history
     * @param  array<string, string>  $applicationStatuses  application => its status now, for the history
     * @param  array<string, bool>  $confirmedAddress  parent => the account has a confirmed address
     * @param  list<Warning>  $warnings
     */
    public function __construct(
        public array $items,
        public array $sessions,
        public array $starts,
        public array $statuses,
        public array $applicationStatuses,
        public array $confirmedAddress,
        public array $warnings,
        public int $children,
        /** @var array<string, list<string>> enrollment => moved sessions whose skips are dropped */
        public array $droppedSkips = [],
        /** @var array<string, array{from: string, to: string}> visit => new local day */
        public array $visitStarts = [],
        public bool $notifyParents = true,
    ) {}

    public static function none(): self
    {
        return new self([], [], [], [], [], [], [], 0);
    }

    /** @return list<Item> Only invitations still go when ordinary notifications are switched off. */
    public function letterItems(): array
    {
        return $this->notifyParents ? $this->items : array_values(array_filter($this->items,
            fn (array $item): bool => in_array($item['effect'], ['visit_cancelled', 'application_rejected'], true)));
    }

    /** @return list<string> the families told, by parent */
    public function families(): array
    {
        $families = array_values(array_unique(array_column($this->letterItems(), 'parent_user_id')));
        sort($families);

        return $families;
    }

    /** The letters that will go: one per family with a confirmed address (т. 6.3). */
    public function letters(): int
    {
        return count(array_filter($this->families(), fn (string $parent): bool => $this->confirmedAddress[$parent] ?? false));
    }

    /** The families without a confirmed address: the office calls them (т. 7.2). */
    public function withoutConfirmedAddress(): int
    {
        return count($this->families()) - $this->letters();
    }

    public function count(string $effect): int
    {
        return count(array_filter($this->items, fn (array $item): bool => $item['effect'] === $effect));
    }
}
