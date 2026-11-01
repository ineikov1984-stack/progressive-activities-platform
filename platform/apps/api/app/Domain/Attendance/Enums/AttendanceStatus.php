<?php

namespace App\Domain\Attendance\Enums;

enum AttendanceStatus: string
{
    case Present = 'present';
    case Absent = 'absent';
}
