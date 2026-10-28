<?php

namespace App\Domain\Catalog\Enums;

enum VisitOfferStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Closed = 'closed';
    case Cancelled = 'cancelled';
}
