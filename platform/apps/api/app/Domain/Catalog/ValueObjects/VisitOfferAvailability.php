<?php

namespace App\Domain\Catalog\ValueObjects;

final readonly class VisitOfferAvailability
{
    public function __construct(
        public string $status,
        public ?int $remainingTotalPlaces,
        public int $remainingOfferPlaces,
        public bool $waitlistAvailable,
    ) {}

    /** @return array{status: string, remaining_total_places: ?int, remaining_offer_places: int, waitlist_available: bool} */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'remaining_total_places' => $this->remainingTotalPlaces,
            'remaining_offer_places' => $this->remainingOfferPlaces,
            'waitlist_available' => $this->waitlistAvailable,
        ];
    }
}
