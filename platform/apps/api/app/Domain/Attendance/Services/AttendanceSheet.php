<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Models\AttendanceMark;
use App\Domain\Attendance\Models\AttendanceMarkChange;
use App\Domain\Attendance\Models\AttendanceSession;
use App\Domain\Attendance\Models\AttendanceSessionChange;
use App\Domain\Catalog\Models\Session;
use App\Domain\Enrollment\Services\SessionRoster;
use App\Domain\Organizations\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Attendance's current state, derived from Enrollment's roster; no writes or personal contacts. */
final readonly class AttendanceSheet
{
    public function __construct(private SessionRoster $roster, private TenantContext $context) {}

    /** @param Collection<int, Session> $sessions
     * @return array<string, array<string, int>>
     */
    public function countsFor(Collection $sessions): array
    {
        return array_map(fn (array $sheet): array => $sheet['counts'], $this->sheets($sessions));
    }

    /** @param Collection<int, Session> $sessions
     * @return array<string, AttendanceSession>
     */
    public function notHeld(Collection $sessions): array
    {
        return AttendanceSession::query()->whereIn('session_id', $sessions->pluck('id'))->whereNotNull('not_held_at')->get()->keyBy('session_id')->all();
    }

    /** @return array<string, mixed> */
    public function forSession(Session $session): array
    {
        $sheet = $this->sheets(collect([$session]))[(string) $session->id];
        $changes = AttendanceMarkChange::query()->join('attendance_marks', function ($join): void {
            $join->on('attendance_marks.id', '=', 'attendance_mark_changes.mark_id')->on('attendance_marks.organization_id', '=', 'attendance_mark_changes.organization_id');
        })->where('attendance_marks.session_id', $session->id)->orderByDesc('attendance_mark_changes.created_at')->orderByDesc('attendance_mark_changes.id')->limit(200)
            ->toBase()->get(['attendance_mark_changes.*', 'attendance_marks.enrollment_id']);
        $history = $changes->map(fn ($change): array => ['id' => $change->id, 'at' => CarbonImmutable::parse($change->created_at)->toIso8601String(), 'actor' => $change->actor_user_id,
            'enrollment_id' => $change->enrollment_id, 'from' => $change->from_status, 'to' => $change->to_status,
            'kind' => $change->kind, 'reason' => $change->reason])->all();
        if ($sheet['state'] !== null) {
            foreach (AttendanceSessionChange::query()->where('attendance_session_id', $sheet['state']->id)->orderByDesc('created_at')->orderByDesc('id')->limit(200)->get() as $change) {
                $history[] = ['id' => $change->id, 'at' => $change->created_at->toIso8601String(), 'actor' => $change->actor_user_id, 'kind' => $change->action, 'reason' => $change->reason];
            }
        }
        usort($history, fn (array $a, array $b): int => [$b['at'], $b['id']] <=> [$a['at'], $a['id']]);
        $sheet['history'] = array_slice($history, 0, 200);
        $actors = [];
        foreach ($sheet['children'] as $child) {
            $actors[] = $child['marked_by'];
            $actors[] = $child['skip']['user_id'] ?? null;
        }
        foreach ($sheet['history'] as $row) {
            $actors[] = $row['actor'];
        }
        $actors[] = $sheet['state']?->not_held_by;
        $actors = array_values(array_unique(array_filter($actors)));
        $names = DB::table('users')->whereIn('id', $actors)->pluck('name', 'id')->all();
        foreach (DB::table('staff_members')->where('organization_id', $this->context->organizationId())->whereIn('user_id', $actors)->whereNull('archived_at')->get(['user_id', 'full_name']) as $person) {
            if ($person->full_name !== '') {
                $names[$person->user_id] = $person->full_name;
            }
        }
        $sheet['names'] = $names;

        return $sheet;
    }

    /** @param Collection<int, Session> $sessions
     * @return array<string, array<string, mixed>>
     */
    private function sheets(Collection $sessions): array
    {
        $rosters = $this->roster->forSessions($sessions);
        $marks = AttendanceMark::query()->whereIn('session_id', $sessions->pluck('id'))->get()->groupBy('session_id');
        $states = AttendanceSession::query()->whereIn('session_id', $sessions->pluck('id'))->get()->keyBy('session_id');
        $result = [];
        foreach ($sessions as $session) {
            $state = $states->get((string) $session->id);
            $hidden = $session->status !== 'scheduled' || $state?->not_held_at !== null;
            $counts = ['expected' => 0, 'present' => 0, 'absent' => 0, 'warned' => 0, 'unmarked' => 0, 'trial' => 0, 'single' => 0, 'paused' => 0, 'present_paused' => 0];
            $children = [];
            $last = null;
            $byEnrollment = ($marks->get((string) $session->id) ?? collect())->keyBy('enrollment_id');
            foreach ($hidden ? [] : ($rosters[(string) $session->id] ?? []) as $row) {
                /** @var AttendanceMark|null $mark */
                $mark = $byEnrollment->get($row['enrollment_id']);
                $expected = $row['state'] !== 'paused';
                $recorded = $mark?->status;
                $value = $recorded?->value;
                $warned = $expected && $value !== 'present' && ($row['skip'] !== null || $row['late_cancelled']);
                $status = $value ?? ($warned ? 'absent' : null);
                $came = ! $expected && $status === 'present';
                $children[] = [...$row, 'expected' => $expected, 'status' => $status, 'warned' => $warned, 'came_while_paused' => $came,
                    'version' => $mark->version ?? 0, 'marked_by' => $mark?->marked_by, 'marked_at' => $mark?->marked_at?->toIso8601String()];
                if ($mark?->marked_at !== null && ($last === null || $mark->marked_at->gt($last))) {
                    $last = $mark->marked_at;
                }
                if ($expected) {
                    $counts['expected']++;
                    $counts[$status ?? 'unmarked']++;
                    if ($warned) {
                        $counts['warned']++;
                    }
                    if (in_array($row['scope'], ['trial', 'single'], true)) {
                        $counts[$row['scope']]++;
                    }
                } else {
                    $counts['paused']++;
                    if ($came) {
                        $counts['present_paused']++;
                    }
                }
            }
            usort($children, fn (array $a, array $b): int => [! $a['expected'], mb_strtolower($a['name']), $a['enrollment_id']] <=> [! $b['expected'], mb_strtolower($b['name']), $b['enrollment_id']]);
            $result[(string) $session->id] = ['children' => $children, 'counts' => $counts, 'state' => $state, 'last_marked_at' => $last?->toIso8601String()];
        }

        return $result;
    }
}
