<?php

namespace App\Domain\Organizations\Enums;

/** How a right meets the person's scope (docs/product/team-rights.md, т. 2). */
enum AbilityKind: string
{
    /** Holds in the groups of the scope; asked for the whole organization, only with the scope "all". */
    case Group = 'group';
    /** Holds with every scope. */
    case Organization = 'organization';
    /** Given and holding only with the scope "all": finance, settings, assignments, changes to the schedule. */
    case WholeOrganization = 'whole_organization';
}
