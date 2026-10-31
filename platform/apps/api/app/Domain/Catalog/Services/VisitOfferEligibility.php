<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Enums\VisitOfferStatus;
use App\Domain\Catalog\Enums\VisitOfferType;
use App\Domain\Catalog\Models\Session;
use App\Domain\Catalog\Models\VisitOffer;
use App\Domain\Enrollment\Models\Child;
use App\Domain\Enrollment\Services\VisitBooking;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;

final class VisitOfferEligibility
{
    public function __construct(private readonly VisitBooking $visits, private readonly AccessScope $scope) {}

    /**
     * A visit on chosen dates follows the same rules as a visit from the schedule (docs/product/visits.md, т. 8a.5):
     * a trial once in the school year for the activity, single visits and open days without a limit. The team may
     * still make an exception to the trial rule with a reason.
     */
    public function assertEligible(
        Child $child,
        VisitOffer $offer,
        Session $session,
        ?User $overrideActor = null,
        ?string $overrideReason = null,
        ?string $exceptApplicationId = null,
    ): void {
        $organizationId = app(TenantContext::class)->organizationId();
        if ($offer->organization_id !== $organizationId || $session->organization_id !== $organizationId
            || $offer->group_id !== $session->group_id || $offer->status !== VisitOfferStatus::Published
            || $session->status !== 'scheduled' || ! $offer->sessions()->whereKey($session->id)->exists()) {
            throw ValidationException::withMessages(['visit_offer_id' => 'Предложението не е достъпно за това занятие.']);
        }

        if ($offer->type === VisitOfferType::Trial) {
            try {
                $this->visits->assertTrialOnce($child, $offer->group, $session, $exceptApplicationId);
            } catch (ValidationException $exception) {
                if (! $this->hasReasonedOverride($overrideActor, $overrideReason, $organizationId, $offer->group_id)) {
                    throw $exception;
                }
            }
        }
    }

    /** @param  string|null  $groupId  the group of the visit; the exception is made by who enrolls there */
    public function assertOverride(User $actor, ?string $reason, ?string $groupId = null): void
    {
        if (! $this->hasReasonedOverride($actor, $reason, app(TenantContext::class)->organizationId(), $groupId)) {
            throw ValidationException::withMessages(['eligibility_override_reason' => 'Изключението изисква оторизиран служител и основание.']);
        }
    }

    private function hasReasonedOverride(?User $actor, ?string $reason, string $organizationId, ?string $groupId): bool
    {
        if ($actor === null || trim((string) $reason) === '') {
            return false;
        }

        // The team makes the exception with "enrollment.enroll" in the visit's group (docs/product/team-rights.md,
        // т. 2.2, 9); without a known group, for the whole organization. The platform operator does not, as before.
        return $actor->exists && $actor->is_active
            && app(TenantContext::class)->organizationId() === $organizationId
            && $this->scope->access($actor)->role?->isTeam() === true
            && ($groupId === null
                ? $this->scope->allowsEverywhere($actor, 'enrollment.enroll')
                : $this->scope->allows($actor, 'enrollment.enroll', $groupId));
    }
}
