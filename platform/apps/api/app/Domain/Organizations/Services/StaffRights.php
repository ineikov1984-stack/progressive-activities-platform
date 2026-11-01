<?php

namespace App\Domain\Organizations\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Enums\AbilityKind;
use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Enums\StaffScope;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Organizations\Models\StaffGrant;
use App\Domain\Organizations\Models\StaffMember;
use App\Domain\Organizations\Models\StaffScopeGroup;
use App\Domain\Organizations\Tenancy\TenantContext;
use App\Domain\Organizations\ValueObjects\StaffAccess;
use Illuminate\Support\Facades\DB;

/**
 * What a user has in the selected organization (docs/product/team-rights.md, т. 4.4):
 * 1. an active membership gives the role R — without one, nothing;
 * 2. the person of the team with the user's login, not archived — none (old data, the platform operator): the role only;
 * 3. the person's added rights not revoked and not expired — expiry is worked out at every check, no scheduled task;
 * 4. the person's scope if R allows it, otherwise R's default; with "groups" the selected groups and activities;
 * 5. rights = R ∪ the added ones ∪ what the added ones bring along;
 * 6. with a scope narrower than "all", the rights for the whole organization only fall away — also a row put straight
 *    into the database.
 * Inside a request of an organization (`ResolveTenant`) it is read once per user; anywhere else at every check, so that
 * a membership changed in the meantime counts. `forget()` after a change of rights in the same request.
 */
final class StaffRights
{
    /** @var array<string, StaffAccess> */
    private array $memo = [];

    private int $remembering = 0;

    public function __construct(private readonly TenantContext $context) {}

    public function for(User $user): StaffAccess
    {
        if (! $this->context->hasOrganization()) {
            return StaffAccess::none();
        }
        $organizationId = $this->context->organizationId();
        if ($this->remembering === 0) {
            return $this->resolve($organizationId, (string) $user->getKey());
        }

        return $this->memo[$organizationId.'|'.$user->getKey()] ??= $this->resolve($organizationId, (string) $user->getKey());
    }

    /**
     * Active team memberships holding every requested right across the selected organization.
     * Uses one membership/person query and one active-grant query. Archived or missing people retain
     * role rights, as in for(); delivery callers apply their own verified-email requirements.
     *
     * @param  list<string>  $abilities
     * @return list<string> sorted user identifiers
     */
    public function holdersEverywhere(array $abilities): array
    {
        if (! $this->context->hasOrganization()) {
            return [];
        }
        $organizationId = $this->context->organizationId();
        $members = DB::table('organization_memberships as memberships')
            ->leftJoin('staff_members as people', function ($join) use ($organizationId): void {
                $join->on('people.user_id', '=', 'memberships.user_id')
                    ->where('people.organization_id', $organizationId)->whereNull('people.archived_at');
            })
            ->where('memberships.organization_id', $organizationId)->where('memberships.status', 'active')
            ->get(['memberships.user_id', 'memberships.role', 'people.id as person_id', 'people.access_scope']);
        $grants = StaffGrant::query()->where('organization_id', $organizationId)
            ->whereIn('staff_member_id', $members->pluck('person_id')->filter()->all())
            ->whereNull('revoked_at')
            ->where(fn ($open) => $open->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderBy('granted_at')->get(['id', 'staff_member_id', 'ability', 'expires_at'])
            ->groupBy('staff_member_id');
        $holders = [];
        foreach ($members as $member) {
            $role = OrganizationRole::tryFrom($member->role);
            if ($role === null || ! $role->isTeam()) {
                continue;
            }
            $chosen = $member->access_scope === null ? null : StaffScope::tryFrom($member->access_scope);
            $scope = $chosen !== null && in_array($chosen, $role->allowedScopes(), true) ? $chosen : $role->defaultScope();
            $added = [];
            foreach ($grants->get($member->person_id, []) as $grant) {
                if (TeamAbilities::isGrantable($grant->ability)) {
                    $added[$grant->ability] = ['id' => (string) $grant->id, 'expires_at' => $grant->expires_at?->utc()->toIso8601String()];
                }
            }
            $access = $this->access($role, $member->person_id, $scope, self::canonical($role->abilities()), $added, [], []);
            if (array_all($abilities, $access->allowsAcrossOrganization(...))) {
                $holders[] = (string) $member->user_id;
            }
        }
        sort($holders, SORT_STRING);

        return array_values(array_unique($holders));
    }

    /**
     * Remembers what was read while the callback runs — one request — and forgets it after.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function remembering(callable $callback): mixed
    {
        $this->remembering++;
        try {
            return $callback();
        } finally {
            $this->remembering--;
            if ($this->remembering === 0) {
                $this->memo = [];
            }
        }
    }

    public function forget(): void
    {
        $this->memo = [];
    }

    private function resolve(string $organizationId, string $userId): StaffAccess
    {
        $value = OrganizationMembership::query()->withoutGlobalScopes()
            ->where('organization_id', $organizationId)->where('user_id', $userId)->where('status', 'active')
            ->value('role');
        $role = is_string($value) ? OrganizationRole::tryFrom($value) : null;
        if ($role === null) {
            return StaffAccess::none();
        }

        $person = StaffMember::query()->where('user_id', $userId)->whereNull('archived_at')->first();
        if ($person === null) {
            return $this->access($role, null, $role->defaultScope(), self::canonical($role->abilities()), [], [], []);
        }

        return $this->describe($role, $person);
    }

    /**
     * What a person of the team would have with the role — for their card "Права" (docs/product/team-rights.md, т. 9),
     * also before they log in, with the role of their invitation. Without a role, nothing. Not a check of rights: those
     * ask `for()`, which needs an active membership.
     */
    public function forMember(StaffMember $member, ?OrganizationRole $role): StaffAccess
    {
        return $role === null || $member->archived_at !== null ? StaffAccess::none() : $this->describe($role, $member);
    }

    private function describe(OrganizationRole $role, StaffMember $person): StaffAccess
    {
        $roleAbilities = self::canonical($role->abilities());
        $grants = [];
        foreach (StaffGrant::query()->where('staff_member_id', $person->id)->whereNull('revoked_at')
            ->where(fn ($open) => $open->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderBy('granted_at')->get(['id', 'ability', 'expires_at']) as $grant) {
            if (TeamAbilities::isGrantable($grant->ability)) {
                $grants[$grant->ability] = ['id' => (string) $grant->id, 'expires_at' => $grant->expires_at?->utc()->toIso8601String()];
            }
        }

        $chosen = $person->access_scope === null ? null : StaffScope::tryFrom($person->access_scope);
        $scope = $chosen !== null && in_array($chosen, $role->allowedScopes(), true) ? $chosen : $role->defaultScope();
        $groupIds = [];
        $activityIds = [];
        if ($scope === StaffScope::Groups) {
            foreach (StaffScopeGroup::query()->where('staff_member_id', $person->id)->whereNull('removed_at')
                ->orderBy('added_at')->get(['group_id', 'activity_id']) as $row) {
                if ($row->group_id !== null) {
                    $groupIds[] = (string) $row->group_id;
                } elseif ($row->activity_id !== null) {
                    $activityIds[] = (string) $row->activity_id;
                }
            }
        }

        return $this->access($role, (string) $person->id, $scope, $roleAbilities, $grants, $groupIds, $activityIds);
    }

    /**
     * @param  list<string>  $roleAbilities
     * @param  array<string, array{id: string, expires_at: string|null}>  $grants
     * @param  list<string>  $groupIds
     * @param  list<string>  $activityIds
     */
    private function access(OrganizationRole $role, ?string $personId, StaffScope $scope, array $roleAbilities, array $grants, array $groupIds, array $activityIds): StaffAccess
    {
        // With a narrower scope the rights for the whole organization only fall away first, so that what they would
        // bring along does not stay behind them.
        if ($scope !== StaffScope::All) {
            $whole = fn (string $ability): bool => TeamAbilities::kind($ability) === AbilityKind::WholeOrganization;
            $roleAbilities = array_values(array_filter($roleAbilities, fn (string $ability): bool => ! $whole($ability)));
            $grants = array_filter($grants, fn (string $ability): bool => ! $whole($ability), ARRAY_FILTER_USE_KEY);
        }
        // What the added rights bring along; the roles are complete sets by themselves — the platform operator places
        // teachers without seeing the team, the accountant has no catalog, as before (т. 2.1).
        $abilities = array_values(array_unique([...$roleAbilities, ...TeamAbilities::withImplied(array_keys($grants))]));

        return new StaffAccess($role, $personId, $scope, $abilities, $roleAbilities, $grants, $groupIds, $activityIds);
    }

    /**
     * @param  list<string>  $abilities
     * @return list<string>
     */
    private static function canonical(array $abilities): array
    {
        return array_values(array_unique(array_map(TeamAbilities::canonical(...), $abilities)));
    }
}
