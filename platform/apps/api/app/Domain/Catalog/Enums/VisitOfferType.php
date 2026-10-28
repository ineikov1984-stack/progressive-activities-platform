<?php

namespace App\Domain\Catalog\Enums;

enum VisitOfferType: string
{
    case Trial = 'trial';
    case Single = 'single';
    case OpenDay = 'open_day';
}
