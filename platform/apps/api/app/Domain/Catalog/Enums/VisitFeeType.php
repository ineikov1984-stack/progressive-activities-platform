<?php

namespace App\Domain\Catalog\Enums;

enum VisitFeeType: string
{
    case Free = 'free';
    case Paid = 'paid';
    case FromPrice = 'from_price';
}
