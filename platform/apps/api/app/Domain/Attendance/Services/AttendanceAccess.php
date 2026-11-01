<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Models\AttendanceSession;
use App\Domain\Catalog\Models\Session;
use App\Domain\Catalog\Services\AccessScope;
use App\Domain\Catalog\Services\SessionCutoff;
use App\Domain\Catalog\Services\TeachingScope;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/** Rights, the teacher's calendar deadline and the screen's opening are separate (attendance.md 4.1–4.2). */
final readonly class AttendanceAccess
{
    public function __construct(private AccessScope $scope, private TeachingScope $teaching, private TenantContext $context) {}

    public function canView(User $user, Session $session): bool
    {
        if (! $this->eligible($user, $session)) {
            return false;
        }
        $group = (string) $session->group_id;

        return in_array($group, $this->teaching->ownGroupsNow($user), true)
            || ($this->teaching->teachesSession($user, $session) && TeachingScope::standsInNow($session->starts_at))
            || $this->scope->allowsInScope($user, 'attendance.mark', $group)
            || $this->scope->allowsInScope($user, 'attendance.view_month', $group)
            || $this->scope->allowsEverywhere($user, 'attendance.correct_late');
    }

    public function mayMark(User $user, Session $session): bool
    {
        return $this->eligible($user, $session)
            && ($this->teaching->teachesSession($user, $session)
                || $this->scope->allowsInScope($user, 'attendance.mark', (string) $session->group_id)
                || $this->scope->allowsEverywhere($user, 'attendance.correct_late'));
    }

    public function canMark(User $user, Session $session): bool
    {
        return $this->mayMark($user, $session)
            && ($this->withinDeadline($session) || $this->scope->allowsEverywhere($user, 'attendance.correct_late'));
    }

    public function requiresReason(User $user, Session $session): bool
    {
        return $this->eligible($user, $session) && ! $this->withinDeadline($session)
            && $this->scope->allowsEverywhere($user, 'attendance.correct_late');
    }

    public function mayRecordNotHeld(User $user, Session $session): bool
    {
        return $this->eligible($user, $session)
            && $this->scope->allowsEverywhere($user, 'schedule.manage')
            && $this->scope->allowsInScope($user, 'attendance.mark', (string) $session->group_id);
    }

    public function opensAt(Session $session): CarbonImmutable
    {
        return SessionCutoff::at($session);
    }

    /** Exclusive deadline: start of the second following local calendar day. */
    public function deadline(Session $session): CarbonImmutable
    {
        return $session->starts_at->setTimezone('Europe/Sofia')->startOfDay()->addDays(2);
    }

    public function withinDeadline(Session $session): bool
    {
        return CarbonImmutable::now()->lt($this->deadline($session));
    }

    /** @return array{mark: bool, reason_required: bool, opens_at: string, deadline: string, reasons: bool, not_held: bool, held: bool} */
    public function abilities(User $user, Session $session): array
    {
        $view = $this->canView($user, $session);
        $can = $view && $this->canMark($user, $session);
        $open = SessionCutoff::passed($session) && $session->status === 'scheduled';
        $notHeld = $view && AttendanceSession::query()->where('session_id', $session->id)->whereNotNull('not_held_at')->exists();
        $office = $can && $open && $this->mayRecordNotHeld($user, $session);

        return [
            'mark' => $can && $open && ! $notHeld,
            'reason_required' => $view && $this->requiresReason($user, $session),
            'opens_at' => $this->opensAt($session)->toIso8601String(),
            'deadline' => $this->deadline($session)->toIso8601String(),
            'reasons' => $view && $this->scope->allowsEverywhere($user, 'attendance.correct_late'),
            'not_held' => $office && ! $notHeld,
            'held' => $office && $notHeld,
        ];
    }

    private function eligible(User $user, Session $session): bool
    {
        return $this->context->hasOrganization()
            && (string) $session->organization_id === $this->context->organizationId()
            && $this->scope->access($user)->role?->isTeam() === true
            && $session->group !== null && $session->group->status !== 'draft';
    }
}
