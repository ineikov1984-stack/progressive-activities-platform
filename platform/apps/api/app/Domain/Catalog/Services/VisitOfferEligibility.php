<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Enums\VisitOfferStatus;
use App\Domain\Catalog\Enums\VisitOfferType;
use App\Domain\Catalog\Models\Session;
use App\Domain\Catalog\Models\VisitOffer;
use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Models\Child;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Organizations\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class VisitOfferEligibility
{
    public function assertEligible(
        Child $child,
        VisitOffer $offer,
        Session $session,
        ?User $overrideActor = null,
        ?string $overrideReason = null,
    ): void {
        $organizationId = app(TenantContext::class)->organizationId();
        if ($offer->organization_id !== $organizationId || $session->organization_id !== $organizationId
            || $offer->group_id !== $session->group_id || $offer->status !== VisitOfferStatus::Published
            || $session->status !== 'scheduled' || ! $offer->sessions()->whereKey($session->id)->exists()) {
            throw ValidationException::withMessages(['visit_offer_id' => 'Предложението не е достъпно за това занятие.']);
        }

        $timezone = $session->group->schedule_pattern['timezone'] ?? 'Europe/Sofia';
        $visitDate = $session->starts_at->setTimezone($timezone)->startOfDay();
        $history = Enrollment::query()->where('organization_id', $organizationId)
            ->where('child_id', $child->id)
            ->whereIn('status', [EnrollmentStatus::Active, EnrollmentStatus::Paused, EnrollmentStatus::Ended])
            ->whereHas('group.offering', fn ($query) => $query->where('activity_id', $offer->activity_id));

        if ($offer->type === VisitOfferType::Trial) {
            $year = $visitDate->month >= 9 ? $visitDate->year : $visitDate->year - 1;
            $first = CarbonImmutable::create($year, 9, 1, 0, 0, 0, $timezone);
            $used = (clone $history)->where('scope', 'trial')
                ->whereDate('starts_on', '>=', $first->toDateString())
                ->whereDate('starts_on', '<', $first->addYear()->toDateString())->count();
            if ($used >= $offer->trial_limit_per_academic_year && ! $this->hasReasonedOverride($overrideActor, $overrideReason, $organizationId)) {
                throw ValidationException::withMessages(['trial_limit' => 'Лимитът за пробни посещения е достигнат.']);
            }
        } elseif ($offer->type === VisitOfferType::Single && ! $offer->unlimited_single_visits) {
            $windowDays = $offer->single_visit_window_days;
            $targetDate = $visitDate->toDateString();
            $dates = (clone $history)->where('scope', 'single')
                ->whereDate('starts_on', '>=', $visitDate->subDays($windowDays - 1)->toDateString())
                ->whereDate('starts_on', '<=', $visitDate->addDays($windowDays - 1)->toDateString())
                ->pluck('starts_on')->map(fn ($date): string => CarbonImmutable::parse($date, $timezone)->toDateString())
                ->all();
            $dates[] = $targetDate;
            sort($dates);

            $limitReached = false;
            $right = 0;
            foreach ($dates as $left => $firstDate) {
                $endDate = CarbonImmutable::parse($firstDate, $timezone)->addDays($windowDays)->toDateString();
                while ($right < count($dates) && $dates[$right] < $endDate) {
                    $right++;
                }
                if ($firstDate <= $targetDate && $targetDate < $endDate && $right - $left > $offer->single_visit_limit) {
                    $limitReached = true;
                    break;
                }
            }

            if ($limitReached && ! $this->hasReasonedOverride($overrideActor, $overrideReason, $organizationId)) {
                throw ValidationException::withMessages(['single_visit_limit' => 'Лимитът за еднократни посещения е достигнат.']);
            }
        }
    }

    private function hasReasonedOverride(?User $actor, ?string $reason, string $organizationId): bool
    {
        if ($actor === null || trim((string) $reason) === '') {
            return false;
        }

        return $actor->exists && $actor->is_active
            && OrganizationMembership::query()->where('user_id', $actor->id)
                ->where('organization_id', $organizationId)
                ->where('status', 'active')
                ->whereIn('role', ['organization_owner', 'organization_admin', 'coordinator'])->exists();
    }
}
