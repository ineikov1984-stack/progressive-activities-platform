<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;

/**
 * An assignment gives rights (docs/product/team-rights.md, т. 2, 8): who leads or assists a group, teaches a session or
 * leads an offering is placed with "team.assign", for the whole organization only. With "team.assign" from an added
 * right alone, a person never places themselves. Changes that place nobody new and take nobody away need nothing.
 */
final readonly class TeamAssignments
{
    public function __construct(private AccessScope $scope, private TeachingScope $teaching) {}

    /**
     * @param  list<string>  $placed  the teacher profiles the change puts somewhere
     */
    public function assertMayAssign(User $actor, array $placed): void
    {
        if (! $this->scope->allowsEverywhere($actor, 'team.assign')) {
            throw new AuthorizationException('Учителите в групите слагат собственикът, администраторът и координаторът.');
        }
        $this->assertNotSelf($actor, $placed);
    }

    /**
     * The identifiers are compared in lower case: PostgreSQL finds a profile by its UUID in any case.
     *
     * @param  list<string>  $placed
     */
    public function assertNotSelf(User $actor, array $placed): void
    {
        if ($placed === [] || ! $this->scope->access($actor)->grantedOnly('team.assign')) {
            return;
        }
        $own = array_map(Str::lower(...), $this->teaching->profilesFor($actor));
        if (array_intersect(array_map(Str::lower(...), $placed), $own) !== []) {
            throw new AuthorizationException('Не можете да се назначите сами.');
        }
    }
}
