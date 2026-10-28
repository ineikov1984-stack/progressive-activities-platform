<?php

namespace App\Domain\Enrollment\Enums;

enum EnrollmentScope: string
{
    case Trial = 'trial';
    case Single = 'single';
    case FullPeriod = 'full_period';
}
