<?php

namespace App\Domain\Catalog\Enums;

enum EnrollmentMode: string
{
    case Trial = 'trial';
    case Single = 'single';
    case FullPeriod = 'full_period';
    case Monthly = 'monthly';
    case Package = 'package';
}
