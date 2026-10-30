<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\ValueObjects\AuditableReference;
use App\Domain\Catalog\Enums\VisitOfferStatus;
use App\Domain\Catalog\Enums\VisitOfferType;
use App\Domain\Catalog\Models\OrganizationCatalogProfile;
use App\Domain\Catalog\Models\VisitOffer;
use App\Domain\Catalog\Services\ScheduleConflictDetector;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Enums\OrganizationStatus;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Tenancy\TenantContext;
use App\Domain\PaymentTerms\Services\PaymentTermsResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final readonly class PublishVisitOffer
{
    public function __construct(
        private TenantContext $context,
        private ScheduleConflictDetector $conflicts,
        private AuditRecorder $audit,
        private PaymentTermsResolver $paymentTerms,
    ) {}

    public function handle(User $actor, VisitOffer $offer, int $expectedVersion, string $transition): VisitOffer
    {
        abort_unless($offer->organization_id === $this->context->organizationId(), 404);
        $organization = Organization::query()->findOrFail($offer->organization_id);
        Gate::forUser($actor)->authorize($transition === 'publish' ? 'catalog.publish' : 'catalog.manage', $organization);

        return DB::transaction(function () use ($offer, $expectedVersion, $transition, $organization): VisitOffer {
            Organization::query()->whereKey($organization->id)->lockForUpdate()->firstOrFail();
            $locked = VisitOffer::query()->whereKey($offer->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->version === $expectedVersion, 409, 'Visit offer version conflict.');

            if ($transition === 'publish') {
                abort_unless($organization->status === OrganizationStatus::Active, 403);
                abort_unless($locked->status === VisitOfferStatus::Draft, 409);
                $sessions = $locked->sessions()->with('group')->get();
                if ($sessions->isEmpty() || $sessions->contains(fn ($session) => $session->status !== 'scheduled'
                    || $session->starts_at->lessThanOrEqualTo(now()))) {
                    throw ValidationException::withMessages(['session_ids' => 'Изберете бъдещи занятия в графика.']);
                }
                if ($locked->visit_limit <= 0 || trim($locked->title) === '') {
                    throw ValidationException::withMessages(['visit_offer' => 'Попълнете заглавие и места преди публикуване.']);
                }
                // A trial or single visit is priced from step 6; an open day has its own fee (docs/product/visits.md, т. 8a.9).
                if ($locked->type === VisitOfferType::OpenDay) {
                    if ($locked->fee_type->value !== 'free' && ($locked->amount_cents <= 0 || trim((string) $locked->fee_reason) === '')) {
                        throw ValidationException::withMessages(['visit_offer' => 'Попълнете цена и основание преди публикуване.']);
                    }
                } elseif ($locked->stepSixPrice() === null) {
                    $mode = $locked->type === VisitOfferType::Trial ? 'Пробно занимание' : 'Еднократно посещение';
                    throw ValidationException::withMessages(['price_option_id' => "Добавете цена „{$mode}“ в стъпка 6 „Цени и записване“."]);
                }
                // In the public catalog the visit terms stay in the platform's frame (payment-policies.md, т. 7.5).
                if (OrganizationCatalogProfile::query()->where('is_published', true)->exists()
                    && $this->paymentTerms->forPrice('single', $locked->group->offering->paymentTermsByKind())?->marketplaceViolations()) {
                    throw ValidationException::withMessages(['payment_terms' => 'Правилата за такси, отсъствия и отказ за посещения не са в рамките на каталога на платформата. Променете ги в „Такси, отсъствия и отказ“.']);
                }
                if (collect($this->conflicts->forGroup($locked->group))->contains('blocking', true)) {
                    throw ValidationException::withMessages(['session_ids' => 'Има конфликт в графика.']);
                }
                $locked->status = VisitOfferStatus::Published;
                $locked->published_at = now()->toImmutable();
                $action = AuditAction::CatalogVisitOfferPublished;
            } elseif ($transition === 'close') {
                abort_unless($locked->status === VisitOfferStatus::Published, 409);
                $locked->status = VisitOfferStatus::Closed;
                $locked->closed_at = now()->toImmutable();
                $action = AuditAction::CatalogVisitOfferClosed;
            } else {
                abort_unless(in_array($locked->status, [VisitOfferStatus::Draft, VisitOfferStatus::Published, VisitOfferStatus::Closed], true), 409);
                $locked->status = VisitOfferStatus::Cancelled;
                $locked->cancelled_at = now()->toImmutable();
                $action = AuditAction::CatalogVisitOfferCancelled;
            }
            $locked->version++;
            $locked->save();
            $this->audit->record($action, new AuditableReference('visit_offer', $locked->id), ['version' => $locked->version]);

            return $locked->refresh()->load('sessions');
        });
    }
}
