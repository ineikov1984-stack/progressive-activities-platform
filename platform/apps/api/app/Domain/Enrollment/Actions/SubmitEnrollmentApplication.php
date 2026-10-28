<?php

namespace App\Domain\Enrollment\Actions;

use App\Domain\Catalog\Enums\ActivityStatus;
use App\Domain\Catalog\Enums\EnrollmentMode;
use App\Domain\Catalog\Enums\VisitOfferType;
use App\Domain\Catalog\Models\Group;
use App\Domain\Catalog\Models\PriceOption;
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
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Enums\OrganizationStatus;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SubmitEnrollmentApplication
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly ReviewEnrollmentApplication $review,
        private readonly VisitOfferEligibility $eligibility,
        private readonly EnrollmentCapacity $capacity,
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
                        throw ValidationException::withMessages([
                            isset($payload['visit_offer_id']) ? 'visit_offer_id' : 'group_id' => 'Избраното предложение не е достъпно.',
                        ]);
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
            throw ValidationException::withMessages(['visit_offer_id' => 'Предложението не е достъпно.']);
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
                    return $existing;
                }

                if ($group === null || $group->status !== 'published'
                    || $group->offering->activity->status !== ActivityStatus::Published) {
                    throw ValidationException::withMessages(['group_id' => 'Избраната група не е достъпна.']);
                }

                $offer = $offerId === null ? null : VisitOffer::query()->whereKey($offerId)->lockForUpdate()->firstOrFail();
                $price = $offer === null ? PriceOption::query()->whereKey($payload['price_option_id'] ?? null)
                    ->where('group_id', $group->id)->where('is_available', true)->first()
                    : null;
                if ($offer === null && $price === null) {
                    throw ValidationException::withMessages(['price_option_id' => 'Избраната цена не е достъпна.']);
                }

                $scope = $offer !== null
                    ? ($offer->type === VisitOfferType::Trial ? EnrollmentScope::Trial : EnrollmentScope::Single)
                    : $this->scopeFor(EnrollmentMode::from($price->enrollment_mode));
                if ($offer === null && $scope !== EnrollmentScope::FullPeriod) {
                    throw ValidationException::withMessages(['visit_offer_id' => 'Изберете публикувано предложение за посещение.']);
                }
                $session = $this->resolveSession($payload['session_id'] ?? null, $group, $scope);
                $status = EnrollmentApplicationStatus::Submitted;
                $metadata = [];
                if ($offer !== null && $session !== null) {
                    $this->eligibility->assertEligible($child, $offer, $session, $overrideActor, $overrideReason);
                    if (now()->greaterThanOrEqualTo($session->starts_at->subHours($offer->booking_deadline_hours))) {
                        throw ValidationException::withMessages(['session_id' => 'Срокът за записване е изтекъл.']);
                    }
                    if ($offer->terms_version !== null && $payload['accepted_terms_version'] !== $offer->terms_version) {
                        throw ValidationException::withMessages(['accepted_terms_version' => 'Приемете актуалните условия.']);
                    }
                    if ($this->capacity->remainingForOffer($offer, $session) < 1) {
                        if (! $offer->waitlist_enabled || ! $group->waitlist_enabled) {
                            throw ValidationException::withMessages(['capacity' => 'Няма свободни места.']);
                        }
                        $status = EnrollmentApplicationStatus::Waitlisted;
                    }
                    $metadata = [
                        'cancellation_deadline_hours' => $offer->cancellation_deadline_hours,
                        'cancellation_outcome' => $offer->cancellation_outcome,
                        'session_starts_at' => $session->starts_at->toIso8601String(),
                    ];
                    if ($overrideActor !== null && trim((string) $overrideReason) !== '') {
                        $this->eligibility->assertOverride($overrideActor, $overrideReason);
                        $metadata['eligibility_override_reason'] = trim($overrideReason);
                    }
                }

                $application = EnrollmentApplication::query()->create([
                    'parent_user_id' => $actor->id,
                    'child_id' => $child->id,
                    'group_id' => $group->id,
                    'price_option_id' => $price?->id,
                    'session_id' => $session?->id,
                    'scope' => $scope,
                    'source' => EnrollmentSource::OrganizationCatalog,
                    'status' => $status,
                    'idempotency_key' => $idempotencyKey,
                    'accepted_terms_version' => $payload['accepted_terms_version'],
                    'accepted_terms_at' => now(),
                    'amount_cents_snapshot' => $offer !== null ? $offer->amount_cents : $price->amount_cents,
                    'currency_snapshot' => $offer !== null ? $offer->currency : $price->currency,
                    'unit_snapshot' => $offer !== null ? 'session' : $price->unit,
                    'visit_offer_id' => $offer?->id,
                    'visit_offer_title_snapshot' => $offer?->title,
                    'fee_reason_snapshot' => $offer?->fee_reason,
                    'cancellation_policy_snapshot' => $offer?->cancellation_policy,
                ]);
                if ($offer !== null) {
                    EnrollmentTransition::query()->create([
                        'application_id' => $application->id,
                        'actor_user_id' => isset($metadata['eligibility_override_reason']) ? $overrideActor->id : $actor->id,
                        'to_status' => $status->value,
                        'metadata' => $metadata,
                    ]);
                }
                EnrollmentEvent::query()->create([
                    'type' => 'enrollment.application_submitted',
                    'application_id' => $application->id,
                    'payload' => ['status' => $status->value, ...$metadata],
                    'occurred_at' => now(),
                ]);

                if ($group->enrollment_approval_mode !== 'automatic' || $status === EnrollmentApplicationStatus::Waitlisted) {
                    return $application;
                }

                $reviewed = $this->review->handle($overrideActor ?? $actor, $application, 'approve', 1, null, null, $overrideReason);
                $reviewed->wasRecentlyCreated = true;

                return $reviewed;
            });
        } finally {
            $this->context->clear();
        }
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
}
