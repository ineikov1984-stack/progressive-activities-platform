<?php

namespace App\Domain\Enrollment\Enums;

enum EnrollmentApplicationStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Waitlisted = 'waitlisted';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Withdrawn = 'withdrawn';
    case Expired = 'expired';
}
