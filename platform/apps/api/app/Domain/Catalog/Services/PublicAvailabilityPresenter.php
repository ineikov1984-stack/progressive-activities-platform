<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Enums\ActivityStatus;
use App\Domain\Catalog\Enums\VisitOfferStatus;
use App\Domain\Catalog\Models\Group;
use App\Domain\Catalog\Models\Session;
use App\Domain\Catalog\Models\VisitOffer;
use App\Domain\Enrollment\Services\EnrollmentCapacity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class PublicAvailabilityPresenter
{
    private const STATUS_PRIORITY = ['closed', 'full', 'last_place', 'limited', 'available'];

    // A campaign label wins when a card has more than one current special offer.
    private const SPECIAL_PRIORITY = ['open_day', 'trial', 'single'];

    public function __construct(
        private readonly VisitOfferAvailabilityService $offers,
        private readonly EnrollmentCapacity $capacity,
    ) {}

    /**
     * @param  Collection<int, VisitOffer>  $offers
     * @return list<array<string, mixed>>
     */
    public function rows(Collection $offers, CarbonImmutable $at): array
    {
        $rows = [];
        foreach ($offers as $offer) {
            if ($offer->status !== VisitOfferStatus::Published
                || $offer->activity?->status !== ActivityStatus::Published
                || $offer->group?->status !== 'published'
                || $offer->group->offering?->activity_id !== $offer->activity_id) {
                continue;
            }
            foreach ($offer->sessions as $session) {
                if ($session->organization_id !== $offer->organization_id
                    || $session->group_id !== $offer->group_id
                    || $session->status !== 'scheduled'
                    || $session->starts_at->lessThanOrEqualTo($at)) {
                    continue;
                }
                $availability = $this->offers->for($offer, $session, $at);
                $rows[] = [
                    'visit_offer_id' => $offer->id,
                    'session_id' => $session->id,
                    'campaign_slug' => $offer->campaign?->status === VisitOfferStatus::Published
                        ? $offer->campaign->slug : null,
                    'type' => $offer->type->value,
                    'title' => $offer->title,
                    'description' => $offer->description,
                    'audience' => $this->audience($offer->audience_age_label, $offer->audience_class_label),
                    'starts_at' => $session->starts_at->toIso8601String(),
                    'ends_at' => $session->ends_at->toIso8601String(),
                    'fee_type' => $offer->fee_type->value,
                    'amount_cents' => $offer->amount_cents,
                    'currency' => $offer->currency,
                    'fee_reason' => $offer->fee_reason,
                    'cancellation_policy' => $offer->cancellation_policy,
                    'cancellation_outcome' => $offer->cancellation_outcome,
                    'cancellation_deadline_hours' => $offer->cancellation_deadline_hours,
                    'booking_deadline_hours' => $offer->booking_deadline_hours,
                    'availability' => $availability->toArray(),
                    'action' => match ($availability->status) {
                        'closed' => 'closed',
                        'full' => $availability->waitlistAvailable ? 'waitlist' : 'closed',
                        default => 'apply',
                    },
                ];
            }
        }

        usort($rows, fn (array $a, array $b): int => [$a['starts_at'], $a['visit_offer_id']]
            <=> [$b['starts_at'], $b['visit_offer_id']]);

        return $rows;
    }

    public function audience(?string $age, ?string $class): ?string
    {
        $parts = array_values(array_filter([trim((string) $age), trim((string) $class)], fn (string $part): bool => $part !== ''));

        return $parts === [] ? null : implode(' · ', $parts);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{status: string, remaining_places: ?int}|null
     */
    public function cardAvailability(array $rows): ?array
    {
        foreach (self::STATUS_PRIORITY as $status) {
            foreach ($rows as $row) {
                if ($row['availability']['status'] === $status) {
                    return [
                        'status' => $status,
                        'remaining_places' => $row['availability']['remaining_offer_places'],
                    ];
                }
            }
        }

        return null;
    }

    /** @param list<array<string, mixed>> $rows */
    public function specialOffer(array $rows): ?string
    {
        foreach (self::SPECIAL_PRIORITY as $type) {
            foreach ($rows as $row) {
                if ($row['type'] === $type) {
                    return $type;
                }
            }
        }

        return null;
    }

    /**
     * @param  Collection<int, Group>  $groups
     * @return array{status: string, remaining_places: ?int}
     */
    public function groupAvailability(Collection $groups, CarbonImmutable $at): array
    {
        $remaining = null;
        $capacity = null;
        $found = false;
        foreach ($groups as $group) {
            $sessions = $group->sessions->filter(fn (Session $session): bool => $session->status === 'scheduled'
                && $session->starts_at->greaterThan($at));
            if ($sessions->isEmpty()) {
                continue;
            }
            $found = true;
            $places = $this->capacity->remainingForGroup($group, $at);
            $remaining = $places === null ? $remaining : ($remaining === null ? $places : min($remaining, $places));
            $capacity = $group->capacity_max === null ? $capacity : ($capacity === null
                ? $group->capacity_max : min($capacity, $group->capacity_max));
        }

        $status = match (true) {
            ! $found => 'closed',
            $remaining === 0 => 'full',
            $remaining === 1 => 'last_place',
            $remaining !== null && ($remaining <= 3 || ($capacity !== null && $remaining <= $capacity * 0.25)) => 'limited',
            default => 'available',
        };

        return ['status' => $status, 'remaining_places' => $remaining];
    }
}
