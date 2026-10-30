<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Enums\ActivityStatus;
use App\Domain\Catalog\Enums\VisitOfferStatus;
use App\Domain\Catalog\Enums\VisitOfferType;
use App\Domain\Catalog\Models\Group;
use App\Domain\Catalog\Models\Session;
use App\Domain\Catalog\Models\VisitOffer;
use App\Domain\Enrollment\Services\EnrollmentCapacity;
use App\Domain\PaymentTerms\Services\PaymentTermsResolver;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Support\Collection;

final class PublicAvailabilityPresenter
{
    private const STATUS_PRIORITY = ['closed', 'full', 'last_place', 'limited', 'available'];

    // A campaign label wins when a card has more than one current special offer.
    private const SPECIAL_PRIORITY = ['open_day', 'trial', 'single'];

    public function __construct(
        private readonly VisitOfferAvailabilityService $offers,
        private readonly EnrollmentCapacity $capacity,
        private readonly PaymentTermsResolver $paymentTerms,
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
            // A trial or single visit is priced from step 6 and every visit takes the activity's visit terms
            // (docs/product/visits.md, т. 8a): without either it cannot be booked, so it is not shown.
            $price = $offer->stepSixPrice();
            $terms = $this->paymentTerms->forPrice('single', $offer->group->offering->paymentTermsByKind());
            if (($offer->type !== VisitOfferType::OpenDay && $price === null) || $terms === null || $terms->marketplaceViolations() !== []) {
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
                    'timezone' => $this->timezoneFor($offer->group),
                    'fee_type' => $price === null ? $offer->fee_type->value : ($price->amount_cents === 0 ? 'free' : 'paid'),
                    'amount_cents' => $price === null ? $offer->amount_cents : $price->amount_cents,
                    'currency' => $price === null ? $offer->currency : $price->currency,
                    'fee_reason' => $price === null ? $offer->fee_reason : null,
                    'booking_deadline_hours' => $offer->booking_deadline_hours,
                    // What the parent reads and accepts (`payment_terms.version` is `accepted_terms_version`).
                    'payment_terms' => $terms->presentForParent(),
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

    public function timezoneFor(Group $group): string
    {
        $timezone = $group->schedule_pattern['timezone'] ?? null;

        return is_string($timezone) && in_array($timezone, DateTimeZone::listIdentifiers(), true)
            ? $timezone : 'Europe/Sofia';
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
     * The group's next sessions open for a trial or a single visit from a price, with their places (null — no
     * limit, 0 — full). The group comes with its scheduled future sessions, earliest first.
     *
     * @return list<array{session_id: string, starts_at: string, ends_at: string, remaining: int|null}>
     */
    public function visitDates(Group $group, CarbonImmutable $at): array
    {
        return array_map(fn (array $day): array => [
            'session_id' => $day['session']->id,
            'starts_at' => $day['session']->starts_at->toIso8601String(),
            'ends_at' => $day['session']->ends_at->toIso8601String(),
            'remaining' => $day['remaining'],
        ], $this->capacity->visitDays($group, $group->sessions, $at));
    }

    /**
     * @param  Collection<int, Group>  $groups
     * @return array{status: string, remaining_places: ?int}
     */
    public function groupAvailability(Collection $groups, CarbonImmutable $at): array
    {
        $presentations = [];
        foreach ($groups as $group) {
            $sessions = $group->sessions->filter(fn (Session $session): bool => $session->status === 'scheduled'
                && $session->starts_at->greaterThan($at));
            if ($sessions->isEmpty()) {
                continue;
            }
            $remaining = $this->capacity->remainingForGroup($group, $at);
            $capacity = $group->capacity_max;
            $status = match (true) {
                $remaining === 0 => 'full',
                $remaining === 1 => 'last_place',
                $remaining !== null && ($remaining <= 3 || ($capacity !== null && $remaining <= $capacity * 0.25)) => 'limited',
                default => 'available',
            };
            $presentations[] = ['status' => $status, 'remaining_places' => $remaining];
        }

        foreach (self::STATUS_PRIORITY as $status) {
            foreach ($presentations as $presentation) {
                if ($presentation['status'] === $status) {
                    return $presentation;
                }
            }
        }

        return ['status' => 'closed', 'remaining_places' => null];
    }
}
