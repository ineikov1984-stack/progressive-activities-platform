<?php

namespace App\Domain\Catalog\Enums;

enum ActivityStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';
}
