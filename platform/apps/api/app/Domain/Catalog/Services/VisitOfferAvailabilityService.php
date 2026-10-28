<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Enums\VisitOfferStatus;
use App\Domain\Catalog\Models\Session;
use App\Domain\Catalog\Models\VisitOffer;
use App\Domain\Catalog\ValueObjects\VisitOfferAvailability;
use App\Domain\Enrollment\Services\EnrollmentCapacity;
use Carbon\CarbonImmutable;

final class VisitOfferAvailabilityService
{
    public function __construct(private readonly EnrollmentCapacity $capacity) {}

    public function for(VisitOffer $offer, Session $session, CarbonImmutable $at): VisitOfferAvailability
    {
        $total = $this->capacity->remainingForSession($session);
        $offerRemaining = $this->capacity->remainingForOffer($offer, $session);
        $timezone = $session->group->schedule_pattern['timezone'] ?? 'Europe/Sofia';
        $start = $session->starts_at->setTimezone($timezone);
        $deadline = $start->subHours($offer->booking_deadline_hours);
        $closed = $offer->status !== VisitOfferStatus::Published
            || $session->status !== 'scheduled'
            || $at->setTimezone($timezone)->greaterThanOrEqualTo($deadline)
            || ! $offer->sessions()->whereKey($session->id)->exists();

        if ($closed) {
            $status = 'closed';
        } elseif ($offerRemaining === 0 || $total === 0) {
            $status = 'full';
        } elseif ($offerRemaining === 1 || $total === 1) {
            $status = 'last_place';
        } elseif ($offerRemaining <= 3 || $offerRemaining <= $offer->visit_limit * 0.25
            || ($total !== null && ($total <= 3 || $total <= ($session->capacity ?? $session->group->capacity_max) * 0.25))) {
            $status = 'limited';
        } else {
            $status = 'available';
        }

        return new VisitOfferAvailability(
            $status, $total, $offerRemaining,
            $status === 'full' && $offer->waitlist_enabled && $session->group->waitlist_enabled,
        );
    }
}
