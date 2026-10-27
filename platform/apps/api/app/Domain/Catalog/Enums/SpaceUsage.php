<?php

namespace App\Domain\Catalog\Enums;

enum SpaceUsage: string
{
    case Exclusive = 'exclusive';
    case Shared = 'shared';
}
