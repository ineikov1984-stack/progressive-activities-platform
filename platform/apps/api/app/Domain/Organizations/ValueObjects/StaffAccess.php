<?php

namespace App\Domain\Organizations\ValueObjects;

use App\Domain\Organizations\Enums\AbilityKind;
use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Enums\StaffScope;
use App\Domain\Organizations\Services\TeamAbilities;

/**
 * What a user has in the selected organization (docs/product/team-rights.md, т. 4.4): the role, the person of the team,
 * the rights of the role and the added ones with what they bring along, and the scope — the selected groups and
 * activities as bare identifiers, never interpreted here (Catalog does, through `AccessScope`).
 */
final readonly class StaffAccess
{
    /**
     * @param  list<string>  $abilities  the effective rights: the role's, the added ones and what they bring along
     * @param  list<string>  $roleAbilities  the rights of the role alone
     * @param  array<string, array{id: string, expires_at: string|null}>  $grants  the active added rights that hold, by right
     * @param  list<string>  $groupIds  with the scope "groups" only
     * @param  list<string>  $activityIds  with the scope "groups" only
     */
    public function __construct(
        public ?OrganizationRole $role,
        public ?string $staffMemberId,
        public StaffScope $scope,
        public array $abilities,
        public array $roleAbilities,
        public array $grants,
        public array $groupIds,
        public array $activityIds,
    ) {}

    /** No active membership: nothing at all. */
    public static function none(): self
    {
        return new self(null, null, StaffScope::Own, [], [], [], [], []);
    }

    public function has(string $ability): bool
    {
        return in_array(TeamAbilities::canonical($ability), $this->abilities, true);
    }

    public function fromRole(string $ability): bool
    {
        return in_array(TeamAbilities::canonical($ability), $this->roleAbilities, true);
    }

    /** The right holds only because it was added — "with an added right, not oneself" (т. 2). */
    public function grantedOnly(string $ability): bool
    {
        return $this->has($ability) && ! $this->fromRole($ability);
    }

    public function grantId(string $ability): ?string
    {
        return $this->grants[TeamAbilities::canonical($ability)]['id'] ?? null;
    }

    /**
     * The check for the whole organization (`Gate::…($organization)`, т. 2.2): a right of a group holds there only with
     * the scope "all". A route that also works with a narrower scope asks `AccessScope` for the record's group instead.
     */
    public function allowsAcrossOrganization(string $ability): bool
    {
        return $this->has($ability)
            && (TeamAbilities::kind($ability) !== AbilityKind::Group || $this->scope === StaffScope::All);
    }
}
