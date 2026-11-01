<?php

namespace App\Domain\Attendance\Enums;

enum MarkKind: string
{
    case Mark = 'mark';
    case AllPresent = 'all_present';
    case Late = 'late';
}
