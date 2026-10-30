<?php

namespace App\Domain\Enrollment\Actions;

use App\Domain\Catalog\Enums\ActivityStatus;
use App\Domain\Catalog\Enums\EnrollmentMode;
use App\Domain\Catalog\Enums\VisitOfferType;
use App\Domain\Catalog\Models\Group;
use App\Domain\Catalog\Models\OrganizationCatalogProfile;
use App\Domain\Catalog\Models\Session;
use App\Domain\Catalog\Models\VisitOffer;
use App\Domain\Catalog\Services\VisitOfferEligibility;
use App\Domain\Enrollment\Enums\EnrollmentApplicationStatus;
use App\Domain\Enrollment\Enums\EnrollmentScope;
use App\Domain\Enrollment\Enums\EnrollmentSource;
use App\Domain\Enrollment\Models\EnrollmentApplication;
use App\Domain\Enrollment\Models\EnrollmentEvent;
use App\Domain\Enrollment\Models\EnrollmentTransition;
use App\Domain\Enrollment\Services\EnrollmentCapacity;
use App\Domain\Enrollment\Services\SessionAllocation;
use App\Domain\Enrollment\Services\VisitBooking;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Enums\OrganizationStatus;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Tenancy\TenantContext;
use App\Domain\PaymentTerms\Services\AppliedTerms;
use App\Domain\PaymentTerms\Services\PaymentTermsResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SubmitEnrollmentApplication
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly ReviewEnrollmentApplication $review,
        private readonly VisitOfferEligibility $eligibility,
        private readonly EnrollmentCapacity $capacity,
        private readonly SessionAllocation $allocation,
        private readonly PaymentTermsResolver $paymentTerms,
        private readonly VisitBooking $visits,
    ) {}

    /** @param array<string, mixed> $payload */
    public function handle(User $actor, array $payload, string $idempotencyKey, ?User $overrideActor = null, ?string $overrideReason = null): EnrollmentApplication
    {
        return DB::transaction(function () use ($actor, $payload, $idempotencyKey, $overrideActor, $overrideReason): EnrollmentApplication {
            try {
                if (DB::getDriverName() === 'pgsql') {
                    DB::select("SELECT set_config('app.current_user_id', ?, true)", [$actor->id]);
                    $tenant = DB::selectOne('SELECT public.enrollment_catalog_tenant(?::uuid, ?::uuid, ?::uuid) AS id', [
                        isset($payload['visit_offer_id']) ? null : ($payload['group_id'] ?? null),
                        $payload['visit_offer_id'] ?? null, $actor->id,
                    ])?->id;
                    if ($tenant === null) {
                        throw ValidationException::withMessages(isset($payload['visit_offer_id'])
                            ? ['visit_offer_id' => 'Това посещение вече не е достъпно.']
                            : ['group_id' => 'Избраната група не е достъпна.']);
                    }
                    $this->context->select($tenant);
                    $this->setDatabaseTenant();
                }

                return $this->submit($actor, $payload, $idempotencyKey, $overrideActor, $overrideReason);
            } finally {
                $this->context->clear();
            }
        });
    }

    /** @param array<string, mixed> $payload */
    private function submit(User $actor, array $payload, string $idempotencyKey, ?User $overrideActor, ?string $overrideReason): EnrollmentApplication
    {
        $child = $actor->children()->where('status', 'active')->findOrFail($payload['child_id']);
        $offerId = $payload['visit_offer_id'] ?? null;
        $selectedOffer = $offerId === null ? null : VisitOffer::query()->withoutGlobalScopes()->find($offerId);
        if ($offerId !== null && $selectedOffer === null) {
            throw ValidationException::withMessages(['visit_offer_id' => 'Това посещение вече не е достъпно.']);
        }
        $groupId = $selectedOffer !== null ? $selectedOffer->group_id : ($payload['group_id'] ?? null);
        $group = Group::query()->withoutGlobalScopes()->find($groupId);

        if ($group === null) {
            throw ValidationException::withMessages(['group_id' => 'Избраната група не е достъпна.']);
        }

        $organization = Organization::query()->withoutGlobalScopes()->find($group->organization_id);
        if ($organization === null || $organization->status !== OrganizationStatus::Active) {
            throw ValidationException::withMessages(['group_id' => 'Избраната група не е достъпна.']);
        }

        $this->context->select($organization);

        try {
            return DB::transaction(function () use ($actor, $child, $payload, $idempotencyKey, $offerId, $groupId, $overrideActor, $overrideReason): EnrollmentApplication {
                $this->setDatabaseTenant();
                // Serialize a child's eligibility across groups, then follow the capacity lock order.
                $child->newQuery()->whereKey($child->id)->lockForUpdate()->firstOrFail();
                $group = Group::query()->with('offering.activity')->lockForUpdate()->find($groupId);

                $existing = EnrollmentApplication::query()
                    ->where('parent_user_id', $actor->id)
                    ->where('source', EnrollmentSource::OrganizationCatalog)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing !== null) {
                    return $existing->load('enrollment');
                }
                // The organization must be able to call back (docs/product/enrollment-contacts-and-emails.md, т. 5).
                if (blank($actor->phone)) {
                    throw ValidationException::withMessages(['phone' => 'Добавете телефон за връзка в профила си — така организацията може да ви се обади.']);
                }

                if ($group === null || $group->status !== 'published'
                    || $group->offering->activity->status !== ActivityStatus::Published
                    || ! OrganizationCatalogProfile::query()->where('is_published', true)->exists()) {
                    throw ValidationException::withMessages(['group_id' => 'Избраната група не е достъпна.']);
                }

                $offer = $offerId === null ? null : VisitOffer::query()->whereKey($offerId)->lockForUpdate()->firstOrFail();
                if ($offer !== null && isset($payload['price_option_id'])) {
                    throw ValidationException::withMessages(['price_option_id' => 'Цената на това посещение е определена от организацията.']);
                }
                // The group's own prices and the activity's for the ways of enrolling the group takes; a trial or single
                // visit on chosen dates — the activity's price for it from step 6 (docs/product/visits.md, т. 8a.1).
                $price = $offer === null ? $group->pricesForEnrollment()->where('is_available', true)
                    ->firstWhere('id', $payload['price_option_id'] ?? null) : $offer->stepSixPrice();
                if ($offer === null && $price === null) {
                    throw ValidationException::withMessages(['price_option_id' => 'Избраната цена не е достъпна.']);
                }
                if ($offer !== null && $offer->type !== VisitOfferType::OpenDay && $price === null) {
                    throw ValidationException::withMessages(['visit_offer_id' => 'Това посещение вече не е достъпно.']);
                }

                $scope = $offer !== null
                    ? ($offer->type === VisitOfferType::Trial ? EnrollmentScope::Trial : EnrollmentScope::Single)
                    : $this->scopeFor(EnrollmentMode::from($price->enrollment_mode));
                $session = $this->resolveSession($payload['session_id'] ?? null, $group, $scope);
                // Every visit, on chosen dates too, keeps a copy of the activity's visit terms (т. 8a.3).
                $terms = $this->acceptedTerms($offer === null ? $price->enrollment_mode : 'single', $group->offering->paymentTermsByKind(), $payload, $offer !== null);
                $acceptedAt = now()->toImmutable();
                $status = EnrollmentApplicationStatus::Submitted;
                $metadata = [];
                if ($offer !== null && $session !== null) {
                    $this->allocation->assertAvailable($child, $session);
                    $this->eligibility->assertEligible($child, $offer, $session, $overrideActor, $overrideReason);
                    if (now()->greaterThanOrEqualTo($session->starts_at->subHours($offer->booking_deadline_hours))) {
                        throw ValidationException::withMessages(['session_id' => 'Срокът за записване е изтекъл.']);
                    }
                    if ($this->capacity->remainingForOffer($offer, $session, forAllocation: true) < 1) {
                        if (! $offer->waitlist_enabled || ! $group->waitlist_enabled) {
                            throw ValidationException::withMessages(['capacity' => 'Няма свободни места.']);
                        }
                        $status = EnrollmentApplicationStatus::Waitlisted;
                    }
                    // The start the parent booked for: cancellation deadlines count from it (т. 5.4, 8a.4).
                    $metadata = [
                        'visit_offer_type' => $offer->type->value,
                        'session_starts_at' => $session->starts_at->toIso8601String(),
                    ];
                    if ($overrideActor !== null && trim((string) $overrideReason) !== '') {
                        $this->eligibility->assertOverride($overrideActor, $overrideReason);
                        $metadata['eligibility_override_reason'] = trim($overrideReason);
                    }
                } elseif ($session !== null) {
                    // A trial or a single visit from a price and a date (docs/product/visits.md, т. 5.1).
                    if (! VisitBooking::isOpen($session, now()->toImmutable())) {
                        throw ValidationException::withMessages(['session_id' => 'Записването за тази дата е затворено.']);
                    }
                    $this->allocation->assertAvailable($child, $session);
                    if ($scope === EnrollmentScope::Trial) {
                        $this->visits->assertTrialOnce($child, $group, $session);
                    }
                    $remaining = $this->capacity->remainingForVisit($session, forAllocation: true);
                    if ($remaining !== null && $remaining < 1) {
                        throw ValidationException::withMessages(['capacity' => 'Няма свободни места на тази дата. Изберете друга дата.']);
                    }
                    // The start the parent booked for: cancellation deadlines count from it (т. 5.4).
                    $metadata = ['session_starts_at' => $session->starts_at->toIso8601String()];
                }

                $application = EnrollmentApplication::query()->create([
                    'parent_user_id' => $actor->id,
                    'parent_note' => $this->note($payload['parent_note'] ?? null),
                    'child_id' => $child->id,
                    'group_id' => $group->id,
                    'price_option_id' => $price?->id,
                    'session_id' => $session?->id,
                    'scope' => $scope,
                    'source' => EnrollmentSource::OrganizationCatalog,
                    'status' => $status,
                    'idempotency_key' => $idempotencyKey,
                    'accepted_terms_version' => $payload['accepted_terms_version'],
                    'accepted_terms_at' => $acceptedAt,
                    // The copy of the terms the parent accepted. A dated visit has no right of withdrawal, so no early
                    // start (ЗЗП, чл. 57, т. 12).
                    'payment_terms_version_id' => $terms->versionId,
                    'payment_terms_snapshot' => $terms->snapshot('parent', $acceptedAt, null,
                        $scope === EnrollmentScope::FullPeriod && ($payload['early_start'] ?? false) === true),
                    // An open day keeps its own fee; everything else is priced from step 6.
                    'amount_cents_snapshot' => $price !== null ? $price->amount_cents : $offer->amount_cents,
                    'currency_snapshot' => $price !== null ? $price->currency : $offer->currency,
                    'unit_snapshot' => $price !== null ? $price->unit : 'session',
                    'visit_offer_id' => $offer?->id,
                    'visit_offer_title_snapshot' => $offer?->title,
                    'fee_reason_snapshot' => $price === null ? $offer->fee_reason : null,
                    'cancellation_policy_snapshot' => null,
                ]);
                $transition = null;
                if ($metadata !== []) {
                    $transition = EnrollmentTransition::query()->create([
                        'application_id' => $application->id,
                        'actor_user_id' => isset($metadata['eligibility_override_reason']) ? $overrideActor->id : $actor->id,
                        'to_status' => $status->value,
                        'metadata' => $metadata,
                    ]);
                }
                EnrollmentEvent::query()->create([
                    'type' => 'enrollment.application_submitted',
                    'application_id' => $application->id,
                    'payload' => ['status' => $status->value, ...$metadata, ...($transition === null ? [] : ['transition_id' => $transition->id])],
                    'occurred_at' => now(),
                ]);

                if ($group->enrollment_approval_mode !== 'automatic' || $status === EnrollmentApplicationStatus::Waitlisted) {
                    return $application->load('enrollment');
                }

                $reviewed = $this->review->handle($overrideActor ?? $actor, $application, 'approve', 1, null, null, $overrideReason);
                $reviewed->wasRecentlyCreated = true;

                return $reviewed->load('enrollment');
            });
        } finally {
            $this->context->clear();
        }
    }

    /**
     * The terms for the chosen price, as the parent saw them (docs/product/payment-policies.md, т. 7.3): a
     * changed set asks the parent to look again instead of holding them to numbers they did not read.
     *
     * @param  array<string, string>  $choices  the activity's sets by way of paying
     * @param  array<string, mixed>  $payload
     */
    private function acceptedTerms(string $enrollmentMode, array $choices, array $payload, bool $offer): AppliedTerms
    {
        $terms = $this->paymentTerms->forPrice($enrollmentMode, $choices);
        // The organization is in the public catalog (checked above), so its frame applies (т. 7.5).
        if ($terms === null || $terms->marketplaceViolations() !== []) {
            throw ValidationException::withMessages($offer
                ? ['visit_offer_id' => 'Това посещение вече не е достъпно.']
                : ['price_option_id' => 'Избраната цена не е достъпна.']);
        }
        if (($payload['accepted_terms_version'] ?? null) !== $terms->key()) {
            throw ValidationException::withMessages(['accepted_terms_version' => 'Условията са променени — прегледайте ги отново.']);
        }

        return $terms;
    }

    private function scopeFor(EnrollmentMode $mode): EnrollmentScope
    {
        return match ($mode) {
            EnrollmentMode::Trial => EnrollmentScope::Trial,
            EnrollmentMode::Single => EnrollmentScope::Single,
            EnrollmentMode::FullPeriod, EnrollmentMode::Monthly, EnrollmentMode::Package => EnrollmentScope::FullPeriod,
        };
    }

    private function resolveSession(?string $sessionId, Group $group, EnrollmentScope $scope): ?Session
    {
        if ($scope === EnrollmentScope::FullPeriod) {
            if ($sessionId !== null) {
                throw ValidationException::withMessages(['session_id' => 'За записване за целия период не се избира отделна дата.']);
            }

            return null;
        }

        if ($sessionId === null) {
            throw ValidationException::withMessages(['session_id' => 'Изберете предстояща дата.']);
        }

        $session = Session::query()->whereKey($sessionId)->where('group_id', $group->id)
            ->where('status', 'scheduled')->where('starts_at', '>', now())->first();
        if ($session === null) {
            throw ValidationException::withMessages(['session_id' => 'Избраната дата не е достъпна.']);
        }

        return $session;
    }

    private function setDatabaseTenant(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::select("SELECT set_config('app.current_organization_id', ?, true)", [$this->context->organizationId()]);
        }
    }

    private function note(mixed $value): ?string
    {
        $note = is_string($value) ? trim($value) : '';

        return $note === '' ? null : $note;
    }
}
