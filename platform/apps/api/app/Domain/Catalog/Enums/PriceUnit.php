<?php

namespace App\Domain\Catalog\Enums;

enum PriceUnit: string
{
    case Session = 'session';
    case Month = 'month';
    case Period = 'period';
    case Package = 'package';
}
