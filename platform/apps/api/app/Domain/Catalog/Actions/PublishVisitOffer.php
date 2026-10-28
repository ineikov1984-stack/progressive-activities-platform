<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\ValueObjects\AuditableReference;
use App\Domain\Catalog\Enums\VisitOfferStatus;
use App\Domain\Catalog\Models\VisitOffer;
use App\Domain\Catalog\Services\ScheduleConflictDetector;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Enums\OrganizationStatus;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final readonly class PublishVisitOffer
{
    public function __construct(
        private TenantContext $context,
        private ScheduleConflictDetector $conflicts,
        private AuditRecorder $audit,
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
                if ($locked->visit_limit <= 0 || trim($locked->title) === ''
                    || ($locked->fee_type->value !== 'free' && ($locked->amount_cents <= 0 || trim((string) $locked->fee_reason) === ''))) {
                    throw ValidationException::withMessages(['visit_offer' => 'Попълнете лимит, цена и основание преди публикуване.']);
                }
                if (trim((string) $locked->terms_version) === '') {
                    throw ValidationException::withMessages(['terms_version' => 'Посочете версия на условията преди публикуване.']);
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
