<?php

namespace App\Domain\Enrollment\Enums;

enum EnrollmentStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Ended = 'ended';
    case Cancelled = 'cancelled';
}
