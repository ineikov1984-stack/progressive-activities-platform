<?php

namespace App\Domain\Organizations\Enums;

/**
 * Where a person's rights in a group hold (docs/product/team-rights.md, т. 4.1): the groups they lead, assist or stand
 * in for; those and the selected groups and activities; or the whole organization. The same for the rights of the role
 * and the added ones: the role says what, the scope says where.
 */
enum StaffScope: string
{
    case Own = 'own';
    case Groups = 'groups';
    case All = 'all';
}
