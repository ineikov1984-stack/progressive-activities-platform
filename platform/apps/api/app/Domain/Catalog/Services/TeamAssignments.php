<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

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

    /** @param  list<string>  $placed */
    public function assertNotSelf(User $actor, array $placed): void
    {
        if ($placed !== [] && $this->scope->access($actor)->grantedOnly('team.assign')
            && array_intersect($placed, $this->teaching->profilesFor($actor)) !== []) {
            throw new AuthorizationException('Не можете да се назначите сами.');
        }
    }
}
