<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Services\ScheduleChangePlanner;
use App\Domain\Catalog\Services\TeacherRecipients;
use App\Domain\Catalog\ValueObjects\PlannedSessionChange;
use App\Domain\Catalog\ValueObjects\ScheduleChangeRequest;
use App\Domain\Enrollment\Services\ScheduleChangeEffects;
use App\Domain\Enrollment\ValueObjects\ScheduleEffects;
use Carbon\CarbonImmutable;

/**
 * The preview of a change of the schedule (docs/product/schedule-changes.md, т. 7.2): the same checks and numbers as the
 * change, from the same request, without locks — it reads only, writes nothing and nothing to the audit log. Numbers,
 * identifiers and the warnings' words; never a child's or a parent's name.
 */
final readonly class PreviewScheduleChange
{
    public function __construct(
        private ScheduleChangePlanner $planner,
        private ScheduleChangeEffects $effects,
        private TeacherRecipients $teachers,
    ) {}

    /** @return array<string, mixed> */
    public function handle(ScheduleChangeRequest $request, bool $allowEmpty = false): array
    {
        $now = CarbonImmutable::now();
        $this->planner->assertFound($request, $this->planner->found($request));
        $planned = $this->planner->plan($request, false, $now, $allowEmpty || $request->action === 'change_location');
        $effects = $request->action === 'substitute' ? ScheduleEffects::none() : $this->effects->assess(array_map(
            fn (PlannedSessionChange $change): array => ['before' => $change->before, 'after' => $change->after, 'effect' => $change->effect()], $planned,
        ), $now, $request->notifyParents);
        $letters = $this->teachers->letters($planned);
        $verifiedTeachers = $this->teachers->withConfirmedAddress(array_map('strval', array_keys($letters)));
        $single = count($planned) === 1 ? $planned[0] : null;

        return [
            'action' => $request->action,
            'sessions' => array_map(fn (PlannedSessionChange $change): array => [
                'id' => $change->sessionId(),
                // The version to send with the change.
                'version' => $change->before->version,
                'starts_at' => $change->after->starts_at->utc()->toIso8601String(),
                'ends_at' => $change->after->ends_at->utc()->toIso8601String(),
                'group_id' => (string) $change->before->group_id,
                'activity_id' => $change->before->group?->offering?->activity_id,
                'location_id' => $change->after->location_id === null ? null : (string) $change->after->location_id,
                'children' => $effects->sessions[$change->sessionId()]['children'] ?? 0,
                'visits' => $effects->sessions[$change->sessionId()]['visits'] ?? 0,
                'families' => $effects->sessions[$change->sessionId()]['families'] ?? 0,
            ], $planned),
            // One session that cannot be changed is refused, not left out: these are for many sessions at once (т. 4.5, 4.6).
            'skipped' => $this->planner->skipped($request, $now),
            'totals' => [
                'sessions' => count($planned),
                'groups' => count(array_unique(array_map(fn (PlannedSessionChange $change): string => (string) $change->before->group_id, $planned))),
                'children' => $effects->children,
                'families' => count($effects->families()),
                'letters' => $effects->letters(),
                'teachers' => $verifiedTeachers,
                'without_verified_email' => $request->action === 'substitute' ? count($letters) - $verifiedTeachers : $effects->withoutConfirmedAddress(),
                'visits' => array_sum(array_column($effects->sessions, 'visits')),
                'visit_applications' => count(array_filter($effects->items, fn (array $item): bool => $item['application_id'] !== null)),
                'skips_dropped' => array_sum(array_map('count', $effects->droppedSkips)),
                'not_attending_after' => $effects->count('moved_not_attending'),
                'starts_changed' => count($effects->starts),
            ],
            'warnings' => [...$this->planner->warnings($planned), ...$effects->warnings],
            'moved_from' => $single?->before->original_starts_at?->utc()->toIso8601String(),
        ];
    }
}
