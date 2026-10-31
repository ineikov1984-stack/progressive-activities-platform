<?php

namespace App\Http\Presenters;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Catalog\Services\AccessScope;
use App\Domain\Catalog\Services\StaffTeaching;
use App\Domain\Catalog\Services\TeamVisibility;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Actions\ReconcileStaffAccess;
use App\Domain\Organizations\Actions\SaveStaffAccess;
use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Enums\StaffScope;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\StaffMember;
use App\Domain\Organizations\Services\StaffRights;
use App\Domain\Organizations\Services\TeamAbilities;
use App\Domain\Organizations\Services\TeamRoles;
use App\Domain\Organizations\Tenancy\TenantContext;
use App\Domain\Organizations\ValueObjects\StaffAccess;
use Carbon\CarbonImmutable;

/**
 * The card "Права" of a person (docs/product/team-rights.md, т. 9, 10). The keys of the rights are for the screen to
 * name, never to show. The history holds keys and identifiers only, never a phone, an e-mail or a name of a parent.
 */
final readonly class StaffAccessCard
{
    private const HISTORY_LIMIT = 50;

    private const ASSIGNMENTS = [
        AuditAction::CatalogAssignmentAdded, AuditAction::CatalogAssignmentEnded, AuditAction::CatalogAssignmentRemoved,
    ];

    /** What of an event's metadata the history passes on. */
    private const DETAILS = ['ability', 'reason', 'expires_at', 'from', 'to', 'role', 'starts_on', 'ends_on'];

    public function __construct(
        private TenantContext $context,
        private TeamRoles $roles,
        private StaffRights $rights,
        private SaveStaffAccess $save,
        private ReconcileStaffAccess $reconcile,
        private StaffTeaching $teaching,
        private AccessScope $scope,
        private TeamVisibility $visibility,
    ) {}

    /** @return array<string, mixed> */
    public function present(User $user, StaffMember $member, ?OrganizationRole $preview): array
    {
        $organization = Organization::query()->findOrFail($this->context->organizationId());
        $member = StaffMember::query()->findOrFail($member->id);
        $role = $this->roles->personRole($organization, $member);
        $access = $this->rights->forMember($member, $role);
        $profile = $this->teaching->profilesFor([(string) $member->id])[(string) $member->id] ?? null;
        $teaches = $profile !== null && ($this->teaching->assignments([$profile])[$profile] ?? []) !== [];
        $refusal = $this->save->refusal($user, $member, $organization);
        // Of the person's selected groups and activities only those in the viewer's own scope, as on the team page.
        $visible = $this->visibility->groups($user);
        $groupIds = $visible === null ? $access->groupIds : array_values(array_intersect($access->groupIds, $visible));
        $activityIds = $visible === null ? $access->activityIds : array_values(array_intersect($access->activityIds, $this->teaching->activitiesOf($visible)));
        $labels = $this->teaching->groupLabels($groupIds);
        $titles = $this->teaching->activityTitles($activityIds);

        $body = [
            'member_id' => $member->id,
            'state' => $member->archived_at !== null ? 'archived' : ($role === null ? 'no_access' : 'access'),
            'role' => $role?->value,
            'scope' => $role === null ? null : $access->scope->value,
            'allowed_scopes' => $role === null ? [] : array_map(fn (StaffScope $scope): string => $scope->value, $role->allowedScopes()),
            'groups' => array_map(fn (string $id): array => ['group_id' => $id, 'label' => $labels[$id] ?? null], $groupIds),
            'activities' => array_map(fn (string $id): array => ['activity_id' => $id, 'title' => $titles[$id] ?? null], $activityIds),
            'rights' => $this->rightsOf($access, $teaches),
            'template' => $member->access_template,
            'version' => $member->version,
            'editable' => $refusal === null,
            'reason' => $refusal['message'] ?? null,
        ];
        if ($this->scope->access($user)->allowsAcrossOrganization('team.manage')) {
            $body['grantable'] = $role === null ? [] : $this->save->grantable($user, $role, $organization);
            $body['history'] = $this->history($member, $profile);
            if ($preview !== null) {
                $body['changes'] = $this->changes($member, $preview);
            }
        }

        return $body;
    }

    /**
     * The rights the person has, by area, with where each comes from: the role, an added right (with its end, or
     * brought along by another), or teaching a group.
     *
     * @return list<array{key: string, area: string, source: string, grant_id: string|null, expires_on: string|null, implied: bool}>
     */
    private function rightsOf(StaffAccess $access, bool $teaches): array
    {
        $rights = [];
        foreach (TeamAbilities::AREAS as $area => $keys) {
            foreach ($keys as $key) {
                $grant = $access->grants[$key] ?? null;
                $source = match (true) {
                    $access->fromRole($key) => 'role',
                    $access->has($key) => 'grant',
                    $teaches && TeamAbilities::isTeaching($key) => 'assignment',
                    default => null,
                };
                if ($source === null) {
                    continue;
                }
                $rights[] = [
                    'key' => $key, 'area' => $area, 'source' => $source, 'grant_id' => $source === 'grant' ? ($grant['id'] ?? null) : null,
                    'expires_on' => $source === 'grant' ? $this->lastDay($grant['expires_at'] ?? null) : null,
                    'implied' => $source === 'grant' && $grant === null,
                ];
            }
        }

        return $rights;
    }

    /** "до 30 юни 2027" — the end is kept as the start of the next day in Sofia. */
    private function lastDay(?string $expiresAt): ?string
    {
        return $expiresAt === null ? null : CarbonImmutable::parse($expiresAt)->setTimezone('Europe/Sofia')->subDay()->toDateString();
    }

    /**
     * What a new role would take away (т. 4.6), before "Запази".
     *
     * @return array{revoke: list<array{ability: string, reason: string}>, clear_scope: bool, remove_groups: int, clear_template: bool, loses_finance: bool}
     */
    private function changes(StaffMember $member, OrganizationRole $role): array
    {
        $plan = $this->reconcile->plan($member, $role);

        return [
            'revoke' => array_map(fn (array $grant): array => ['ability' => $grant['ability'], 'reason' => $grant['reason']], $plan['revoke']),
            'clear_scope' => $plan['clear_scope'],
            'remove_groups' => count($plan['remove_groups']),
            'clear_template' => $plan['clear_template'],
            'loses_finance' => $plan['loses_finance'],
        ];
    }

    /**
     * The history of the person's rights, newest first: the added rights, the scope and the template, the groups they
     * were placed in, and the contacts they were shown.
     *
     * @return list<array<string, mixed>>
     */
    private function history(StaffMember $member, ?string $profile): array
    {
        $events = AuditEvent::query()->where('organization_id', $this->context->organizationId())
            ->where(function ($query) use ($member, $profile): void {
                $query->where(fn ($own) => $own->where('subject_type', 'staff_member')->where('subject_id', $member->id)
                    ->whereIn('action', array_map(fn (AuditAction $action): string => $action->value, [
                        AuditAction::TeamAccessGrantAdded, AuditAction::TeamAccessGrantChanged, AuditAction::TeamAccessGrantRevoked,
                        AuditAction::TeamAccessScopeChanged, AuditAction::TeamAccessGroupAdded, AuditAction::TeamAccessGroupRemoved,
                        AuditAction::TeamAccessTemplateChanged,
                    ])));
                if ($profile !== null) {
                    $query->orWhere(fn ($placed) => $placed->whereIn('action', array_map(fn (AuditAction $action): string => $action->value, self::ASSIGNMENTS))
                        ->where('metadata->instructor_profile_id', $profile));
                }
                if ($member->user_id !== null) {
                    $query->orWhere(fn ($shown) => $shown->where('action', AuditAction::EnrollmentContactRevealed->value)->where('actor_id', $member->user_id));
                }
            })
            ->latest('created_at')->limit(self::HISTORY_LIMIT)->get(['action', 'actor_id', 'subject_type', 'subject_id', 'metadata', 'created_at']);
        $names = User::query()->whereKey($events->pluck('actor_id')->filter()->unique()->values()->all())->pluck('name', 'id');
        $groupIds = $events->map(fn (AuditEvent $event): mixed => $event->subject_type === 'group' ? $event->subject_id : ($event->metadata['group_id'] ?? null))
            ->filter(fn (mixed $id): bool => is_string($id))->unique()->values()->all();
        $labels = $this->teaching->groupLabels($groupIds);

        return $events->map(function (AuditEvent $event) use ($names, $labels): array {
            $metadata = $event->metadata;
            $group = $event->subject_type === 'group' ? $event->subject_id : ($metadata['group_id'] ?? null);

            return [
                'action' => $event->action,
                'at' => $event->created_at->toIso8601String(),
                'by' => $event->actor_id === null ? null : ($names[$event->actor_id] ?? null),
                'group_label' => is_string($group) ? ($labels[$group] ?? null) : null,
                ...array_intersect_key($metadata, array_flip(self::DETAILS)),
            ];
        })->values()->all();
    }
}
