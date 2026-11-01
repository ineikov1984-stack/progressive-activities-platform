<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Organizations\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Current-state warnings shared by candidate previews and assignment validation. */
final readonly class SubstituteWarnings
{
    public function __construct(private TenantContext $context) {}

    /**
     * @param  list<object>  $sessions  Objects with id, starts_at and ends_at.
     * @param  array<string, array{name: string, aliases: list<string>}>  $people  Candidate profile => all aliases.
     * @return array<string, list<array<string, mixed>>>
     */
    public function read(array $sessions, array $people): array
    {
        if ($sessions === [] || $people === []) {
            return [];
        }
        $org = $this->context->organizationId();
        $time = DB::getDriverName() === 'pgsql' ? 'CAST(? AS timestamptz)' : '?';
        $uuid = DB::getDriverName() === 'pgsql' ? 'CAST(? AS uuid)' : '?';
        $values = [];
        $bindings = [];
        foreach ($sessions as $session) {
            $start = CarbonImmutable::parse($session->starts_at, 'UTC');
            $day = $start->setTimezone('Europe/Sofia')->startOfDay();
            $values[] = "($uuid, $time, $time, $time, $time)";
            array_push($bindings, $session->id, $start->format('Y-m-d H:i:s'), CarbonImmutable::parse($session->ends_at, 'UTC')->format('Y-m-d H:i:s'), $day->utc()->format('Y-m-d H:i:s'), $day->addDay()->utc()->format('Y-m-d H:i:s'));
        }
        $cte = 'WITH selected(id, starts_at, ends_at, day_start, day_end) AS (VALUES '.implode(',', $values).') ';
        $aliases = array_values(array_unique(array_merge(...array_column($people, 'aliases'))));
        $marks = implode(',', array_fill(0, count($aliases), '?'));
        $base = ' FROM selected s JOIN activity_sessions x ON x.starts_at < s.day_end AND x.starts_at >= s.day_start
            JOIN activity_groups g ON g.id = x.group_id AND g.organization_id = ?
            WHERE x.organization_id = ? AND x.status = \'scheduled\' AND g.status NOT IN (\'draft\', \'archived\')
            AND x.id NOT IN (SELECT id FROM selected) ';
        $busy = DB::select($cte.'SELECT s.id AS session_id, x.id AS other_session_id, i.instructor_profile_id AS profile_id, x.starts_at, x.ends_at, a.title, g.name
            FROM selected s JOIN activity_sessions x ON x.starts_at < s.ends_at AND x.ends_at > s.starts_at
            JOIN activity_session_instructors i ON i.session_id = x.id AND i.organization_id = ?
            JOIN activity_groups g ON g.id = x.group_id AND g.organization_id = ?
            JOIN offerings o ON o.id = g.offering_id AND o.organization_id = ?
            JOIN activities a ON a.id = o.activity_id AND a.organization_id = ?
            WHERE x.organization_id = ? AND x.status = \'scheduled\' AND g.status NOT IN (\'draft\', \'archived\')
            AND x.id NOT IN (SELECT id FROM selected) AND i.instructor_profile_id IN ('.$marks.')', [...$bindings, $org, $org, $org, $org, $org, ...$aliases]);
        $awaySource = " JOIN (SELECT r.session_id, r.requester_profile_id AS profile_id, 1 AS is_request FROM substitute_request_sessions r
            JOIN substitute_requests q ON q.id = r.request_id AND q.organization_id = ?
            WHERE r.organization_id = ? AND r.status = 'open'
            UNION SELECT i.session_id, i.replaces_instructor_profile_id AS profile_id, 0 AS is_request FROM activity_session_instructors i
            WHERE i.organization_id = ? AND i.replaces_instructor_profile_id IS NOT NULL) away ON away.session_id = x.id ";
        $awaySql = str_replace('            WHERE x.organization_id', $awaySource.' WHERE x.organization_id', $base);
        // JOIN bindings appear before WHERE bindings.
        $away = DB::select($cte.'SELECT s.id AS session_id, x.id AS other_session_id, away.profile_id, x.starts_at'.$awaySql.' AND (away.is_request = 0 OR x.starts_at > ?) AND away.profile_id IN ('.$marks.')', [...$bindings, $org, $org, $org, $org, $org, CarbonImmutable::now('UTC')->format('Y-m-d H:i:s'), ...$aliases]);
        $result = [];
        foreach ($people as $id => $person) {
            $warnings = [];
            foreach ([['instructor', $busy], ['substitute_away', $away]] as [$type, $rows]) {
                foreach ($rows as $row) {
                    if (! in_array($row->profile_id, $person['aliases'], true)) {
                        continue;
                    }
                    $start = CarbonImmutable::parse($row->starts_at, 'UTC');
                    $warning = ['type' => $type, 'session_id' => $row->session_id, 'other_session_id' => $row->other_session_id, 'needs_confirmation' => true];
                    if ($type === 'instructor') {
                        $end = CarbonImmutable::parse($row->ends_at, 'UTC');
                        $label = $row->title.' · '.$row->name;
                        $warning += ['label' => $label, 'starts_at' => $start->toIso8601String(), 'ends_at' => $end->toIso8601String(),
                            'message' => $person['name'].' води друго занимание по това време: '.$label.', '.$start->setTimezone('Europe/Sofia')->format('H:i').'–'.$end->setTimezone('Europe/Sofia')->format('H:i').'.'];
                    } else {
                        $warning['message'] = $person['name'].' търси заместник или е заместен на '.$start->setTimezone('Europe/Sofia')->locale('bg')->isoFormat('D MMMM').'.';
                    }
                    $warnings[$type.'|'.$row->session_id.'|'.$row->other_session_id] = $warning;
                }
            }
            ksort($warnings);
            $result[$id] = array_values($warnings);
        }

        return $result;
    }
}
