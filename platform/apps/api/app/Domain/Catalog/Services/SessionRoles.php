<?php

namespace App\Domain\Catalog\Services;

/**
 * Who leads, assists and stands in at a session (docs/product/schedule.md, т. 5). The role written on the session
 * (`activity_session_instructors.role`) is not trusted — a change by hand in the editor writes "lead" for everyone:
 * - an assignment in the group that holds on the session's day: its role, "lead" or "assistant";
 * - otherwise the lead of the offering: "lead";
 * - anyone else stands in ("substitute") for the leads with an assignment for that day who are not on the session; with
 *   nobody to stand in for, they simply lead.
 * Pure: the caller gives the day (Sofia) and the rows, so the rule is the same wherever it is asked.
 *
 * @phpstan-type SessionTeacher array{profile_id: string, name: string, role: string, replaces?: list<array{profile_id: string, name: string}>}
 */
final class SessionRoles
{
    /** The leads first, then those who stand in, then the assistants. */
    private const ORDER = ['lead' => 0, 'substitute' => 1, 'assistant' => 2];

    /**
     * @param  list<array{profile_id: string, name: string, replaces_instructor_profile_id?: string|null, replaces_name?: string|null}>  $teachers  on the session
     * @param  list<array{profile_id: string, name: string, role: string, starts_on: string|null, ends_on: string|null}>  $assignments  the group's, ended ones included
     * @return list<SessionTeacher>
     */
    public static function derive(array $teachers, array $assignments, ?string $offeringLead, string $day): array
    {
        $roles = [];
        $leads = [];
        foreach ($assignments as $assignment) {
            if (! GroupTeachers::holds($assignment, $day)) {
                continue;
            }
            $role = $assignment['role'] === 'assistant' ? 'assistant' : 'lead';
            $roles[$assignment['profile_id']] = $role;
            if ($role === 'lead') {
                $leads[] = ['profile_id' => $assignment['profile_id'], 'name' => $assignment['name']];
            }
        }
        $present = [...array_column($teachers, 'profile_id'), ...array_column($teachers, 'replaces_instructor_profile_id')];
        $absent = array_values(array_filter($leads, fn (array $lead): bool => ! in_array($lead['profile_id'], $present, true)));
        usort($absent, fn (array $a, array $b): int => self::key($a) <=> self::key($b));

        $result = [];
        foreach ($teachers as $teacher) {
            if (($teacher['replaces_instructor_profile_id'] ?? null) !== null) {
                $result[] = ['profile_id' => $teacher['profile_id'], 'name' => $teacher['name'], 'role' => 'substitute',
                    'replaces' => [['profile_id' => $teacher['replaces_instructor_profile_id'], 'name' => $teacher['replaces_name'] ?? '']]];

                continue;
            }
            $role = $roles[$teacher['profile_id']] ?? ($teacher['profile_id'] === $offeringLead ? 'lead' : null);
            $result[] = $role === null && $absent !== []
                ? ['profile_id' => $teacher['profile_id'], 'name' => $teacher['name'], 'role' => 'substitute', 'replaces' => $absent]
                : ['profile_id' => $teacher['profile_id'], 'name' => $teacher['name'], 'role' => $role ?? 'lead'];
        }
        usort($result, fn (array $a, array $b): int => [self::ORDER[$a['role']], ...self::key($a)] <=> [self::ORDER[$b['role']], ...self::key($b)]);

        return $result;
    }

    /**
     * By name, then by profile so that the order is always the same.
     *
     * @param  array{profile_id: string, name: string}  $person
     * @return array{string, string}
     */
    private static function key(array $person): array
    {
        return [mb_strtolower($person['name']), $person['profile_id']];
    }
}
