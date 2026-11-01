<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Catalog\Models\Session;
use App\Domain\Catalog\Services\ScheduleReader;
use App\Domain\Identity\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/** Month and child summaries share cells and denominator (attendance.md 2.1, 5.6–5.7). */
final readonly class AttendanceMonth
{
    private const TIMEZONE = 'Europe/Sofia';

    public function __construct(private AttendanceAccess $access, private AttendanceSheet $sheet, private ScheduleReader $catalog) {}

    public static function validateMonth(mixed $value): string
    {
        $last = CarbonImmutable::now(self::TIMEZONE)->startOfMonth()->addMonths(12)->format('Y-m');
        if (! is_string($value) || ! preg_match('/\A\d{4}-(0[1-9]|1[0-2])\z/', $value) || $value < '2020-01' || $value > $last) {
            throw ValidationException::withMessages(['month' => 'Изберете месец.']);
        }

        return $value;
    }

    public static function currentMonth(): string
    {
        return CarbonImmutable::now(self::TIMEZONE)->format('Y-m');
    }

    /** Rights and existence precede the roster; archived groups enter only when requested.
     * @return array<string, mixed>
     */
    public function read(User $user, ?string $requested, mixed $month): array
    {
        $allowed = $this->access->monthGroups($user);
        if ($requested !== null) {
            abort_unless($this->catalog->groupExists($requested), 404, 'Групата не е намерена.');
            abort_if($allowed !== null && ! in_array($requested, $allowed, true), 403, 'Нямате достъп до присъствията в тази група.');
        }
        $groups = $this->catalog->groupChoices($allowed, $requested);
        abort_if($groups === [], 403, 'Нямате достъп до присъствията в тази група.');
        $month = self::validateMonth($month);
        $groupId = $requested ?? $groups[0]['id'];
        $group = array_values(array_filter($groups, fn (array $group): bool => $group['id'] === $groupId))[0];
        $sessions = $this->sessions([$groupId], $month);
        $sheets = $this->sheet->monthSheets($sessions);
        $sessionRows = [];
        foreach ($sessions as $session) {
            $sheet = $sheets[(string) $session->id];
            $notHeld = $sheet['state']?->not_held_at !== null;
            $hidden = $session->status !== 'scheduled' || $notHeld;
            $sessionRows[] = ['id' => (string) $session->id, 'starts_at' => $session->starts_at->utc()->toIso8601String(),
                'status' => $session->status, 'not_held' => $notHeld, 'extra' => $session->extra, 'moved' => $session->original_starts_at !== null,
                'topic' => $sheet['state']?->topic, 'present' => $hidden ? null : $sheet['counts']['present'],
                'expected' => $hidden ? null : $sheet['counts']['expected'], 'past' => $session->starts_at->lte(CarbonImmutable::now())];
        }

        return ['groups' => $groups, 'group' => $group, 'month' => $month, 'sessions' => $sessionRows, 'rows' => $this->rows($sessions, $sheets)];
    }

    /** The card's already visible groups, narrowed by attendance rights. One batch for all groups.
     * @param  list<string>  $groupIds
     * @return array<string, array<string, mixed>>
     */
    public function forChild(User $user, string $childId, array $groupIds, string $month): array
    {
        $allowed = $this->access->monthGroups($user, includeMark: true);
        $groupIds = array_values(array_unique(array_filter($groupIds, fn (string $id): bool => $allowed === null || in_array($id, $allowed, true))));
        $visible = $this->catalog->existingGroups($groupIds);
        $sessions = $this->sessions($visible, $month)->filter(fn (Session $session): bool => $session->starts_at->lte(CarbonImmutable::now()))->values();
        $sheets = $this->sheet->monthSheets($sessions, $childId);
        $result = [];
        foreach ($visible as $groupId) {
            $result[$groupId] = ['month' => $month, 'present' => 0, 'absent' => 0, 'percent' => null, 'sessions' => []];
        }
        foreach ($sessions as $session) {
            $sheet = $sheets[(string) $session->id];
            $group = (string) $session->group_id;
            foreach ($sheet['children'] as $child) {
                $state = $this->cellState($session, $sheet, $child);
                $result[$group]['sessions'][] = ['session_id' => (string) $session->id, 'starts_at' => $session->starts_at->utc()->toIso8601String(), 'state' => $state];
                $this->count($result[$group], $state);
            }
        }
        foreach ($result as &$summary) {
            $summary['percent'] = self::percent($summary['present'], $summary['absent']);
        }

        return $result;
    }

    /** @param list<string> $groups
     * @return Collection<int, Session>
     */
    private function sessions(array $groups, string $month): Collection
    {
        $start = CarbonImmutable::parse($month.'-01', self::TIMEZONE)->startOfDay();

        return $this->catalog->sessionsInGroups($groups, $start, $start->addMonth());
    }

    /** @param Collection<int, Session> $sessions
     * @param  array<string, array<string, mixed>>  $sheets
     * @return list<array<string, mixed>>
     */
    private function rows(Collection $sessions, array $sheets): array
    {
        $rows = [];
        foreach ($sessions as $session) {
            $sheet = $sheets[(string) $session->id];
            foreach ($sheet['children'] as $child) {
                $kind = $child['scope'] === 'full_period' ? 'period' : $child['scope'];
                $key = $child['child_id'].'|'.$kind;
                $rows[$key] ??= ['child_id' => $child['child_id'], 'name' => $child['name'], 'kind' => $kind, 'cells' => [], 'present' => 0, 'absent' => 0, 'percent' => null];
                $state = $this->cellState($session, $sheet, $child);
                $rows[$key]['cells'][(string) $session->id] = ['enrollment_id' => $child['enrollment_id'], 'state' => $state];
                $this->count($rows[$key], $state);
            }
        }
        foreach ($rows as &$row) {
            $row['percent'] = self::percent($row['present'], $row['absent']);
        }
        $rows = array_values($rows);
        usort($rows, fn (array $a, array $b): int => [mb_strtolower($a['name']), $a['kind'], $a['child_id']] <=> [mb_strtolower($b['name']), $b['kind'], $b['child_id']]);

        return $rows;
    }

    /** @param array<string, mixed> $sheet
     * @param  array<string, mixed>  $child
     */
    private function cellState(Session $session, array $sheet, array $child): string
    {
        return match (true) {
            $session->status === 'cancelled' => 'cancelled',
            $sheet['state']?->not_held_at !== null => 'not_held',
            $session->starts_at->gt(CarbonImmutable::now()) => 'future',
            $child['came_while_paused'] => 'paused_present',
            ! $child['expected'] => 'paused',
            $child['warned'] => 'warned',
            default => $child['status'] ?? 'unmarked',
        };
    }

    /** @param array<string, mixed> $totals */
    private function count(array &$totals, string $state): void
    {
        if ($state === 'present') {
            $totals['present']++;
        } elseif (in_array($state, ['absent', 'warned'], true)) {
            $totals['absent']++;
        }
    }

    public static function percent(int $present, int $absent): ?int
    {
        return $present + $absent === 0 ? null : (int) round(100 * $present / ($present + $absent), 0, PHP_ROUND_HALF_UP);
    }
}
