<?php

namespace App\Http\Presenters;

/** Only the attendance contract; never Enrollment's contacts, health or financial fields. */
final class AttendancePresenter
{
    /** @phpstan-import-type SessionTeacher from \App\Domain\Catalog\Services\SessionRoles
     * @param  array<string, mixed>  $panel
     * @param  array<string, mixed>  $sheet
     * @param  array<string, mixed>  $abilities
     * @return array<string, mixed>
     */
    public static function sheet(array $panel, array $sheet, array $abilities, bool $cards, bool $skipNames, bool $scheduleReasons): array
    {
        $session = $panel['session'];
        $group = $panel['group'];
        $state = $sheet['state'];
        $names = $sheet['names'];
        $children = [];
        foreach ($sheet['children'] as $row) {
            $children[] = [
                'enrollment_id' => $row['enrollment_id'], ...($cards ? ['child_id' => $row['child_id']] : []),
                'name' => $row['name'], 'class' => $row['class'], 'scope' => $row['scope'], 'expected' => $row['expected'],
                'status' => $row['status'], 'warned' => $row['warned'], 'came_while_paused' => $row['came_while_paused'], 'version' => $row['version'],
                'paused_until' => $row['paused_until'], 'skip' => $row['skip'] === null ? null : ['by' => $row['skip']['by'],
                    ...($skipNames ? ['by_name' => $names[$row['skip']['user_id']] ?? null] : []), 'at' => $row['skip']['at']],
                'late_cancelled' => $row['late_cancelled'], 'marked_by' => $names[$row['marked_by']] ?? null, 'marked_at' => $row['marked_at'],
            ];
        }
        $history = [];
        foreach ($sheet['history'] as $row) {
            $sessionChange = in_array($row['kind'], ['not_held', 'held'], true);
            $history[] = ['id' => $row['id'], 'at' => $row['at'], 'by' => $names[$row['actor']] ?? null,
                ...($sessionChange ? [] : ['enrollment_id' => $row['enrollment_id'], 'from' => $row['from'], 'to' => $row['to']]),
                'kind' => $row['kind'], ...(($sessionChange ? $scheduleReasons : $abilities['reasons']) ? ['reason' => $row['reason']] : [])];
        }

        return [
            'session' => ['id' => $session->id, 'starts_at' => $session->starts_at->toIso8601String(), 'ends_at' => $session->ends_at->toIso8601String(),
                'status' => $session->status, 'extra' => $session->extra, 'moved_from' => $session->original_starts_at?->toIso8601String(),
                'topic' => $state?->topic, 'not_held' => $state?->not_held_at === null ? null : ['at' => $state->not_held_at->toIso8601String(),
                    ...($scheduleReasons ? ['by' => $names[$state->not_held_by] ?? null, 'reason' => $state->not_held_reason] : [])],
                'group' => ['id' => $group['id'], 'name' => $group['name'], 'age_label' => $group['age_label']],
                'activity' => ['id' => $group['activity_id'], 'title' => $group['activity_title']], 'location' => $panel['location'], 'instructors' => $panel['teachers']],
            'abilities' => $abilities, 'counts' => $sheet['counts'], 'last_marked_at' => $sheet['last_marked_at'], 'children' => $children, 'history' => $history,
        ];
    }
}
