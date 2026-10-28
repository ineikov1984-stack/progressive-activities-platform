<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\ValueObjects\AuditableReference;
use App\Domain\Catalog\Enums\VisitOfferStatus;
use App\Domain\Catalog\Models\OpenDayCampaign;
use App\Domain\Catalog\Models\VisitOffer;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Enums\OrganizationStatus;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Tenancy\TenantContext;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class SaveOpenDayCampaign
{
    private const FIELDS = ['slug', 'title', 'description', 'starts_on', 'ends_on', 'image_path'];

    public function __construct(private TenantContext $context, private AuditRecorder $audit) {}

    /** @param array<string, mixed> $data */
    public function create(User $actor, array $data): OpenDayCampaign
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($data): OpenDayCampaign {
            Organization::query()->whereKey($this->context->organizationId())->lockForUpdate()->firstOrFail();
            $fields = Arr::only($data, self::FIELDS);
            $base = Str::slug($fields['slug'] ?? $fields['title'], '-', 'bg') ?: Str::lower(Str::random(12));
            $base = rtrim(Str::substr($base, 0, 255), '-');
            $fields['slug'] = $base;
            $suffix = 2;
            while (OpenDayCampaign::query()->where('slug', $fields['slug'])->exists()) {
                $ending = '-'.$suffix++;
                $fields['slug'] = rtrim(Str::substr($base, 0, 255 - strlen($ending)), '-').$ending;
            }
            $campaign = OpenDayCampaign::query()->create($fields);
            $this->syncOffers($campaign, $data['visit_offer_ids'] ?? []);
            $this->audit->record(AuditAction::CatalogOpenDayCampaignCreated, new AuditableReference('open_day_campaign', $campaign->id), ['version' => 1]);

            return $campaign->load('visitOffers');
        });
    }

    /** @param array<string, mixed> $data */
    public function update(User $actor, OpenDayCampaign $campaign, int $expectedVersion, array $data): OpenDayCampaign
    {
        $this->authorize($actor, $campaign);

        return DB::transaction(function () use ($campaign, $expectedVersion, $data): OpenDayCampaign {
            $locked = OpenDayCampaign::query()->whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->version === $expectedVersion, 409, 'Campaign version conflict.');
            abort_if($locked->status === VisitOfferStatus::Cancelled, 409);
            $locked->fill(Arr::only($data, self::FIELDS));
            $locked->version++;
            $locked->save();
            if (array_key_exists('visit_offer_ids', $data)) {
                $this->syncOffers($locked, $data['visit_offer_ids']);
            }
            $this->audit->record(AuditAction::CatalogOpenDayCampaignUpdated, new AuditableReference('open_day_campaign', $locked->id), ['version' => $locked->version]);

            return $locked->refresh()->load('visitOffers');
        });
    }

    public function transition(User $actor, OpenDayCampaign $campaign, int $expectedVersion, string $transition): OpenDayCampaign
    {
        $this->authorize($actor, $campaign, $transition === 'publish');

        return DB::transaction(function () use ($campaign, $expectedVersion, $transition): OpenDayCampaign {
            $locked = OpenDayCampaign::query()->whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->version === $expectedVersion, 409, 'Campaign version conflict.');
            if ($transition === 'publish') {
                abort_unless($locked->status === VisitOfferStatus::Draft, 409);
                if (! $locked->visitOffers()->where('status', VisitOfferStatus::Published->value)->exists()) {
                    throw ValidationException::withMessages(['visit_offer_ids' => 'Кампанията трябва да съдържа публикувано предложение.']);
                }
                if ($locked->starts_on === null || $locked->ends_on === null || $locked->ends_on->isBefore(today())) {
                    throw ValidationException::withMessages(['starts_on' => 'Изберете валиден бъдещ период.']);
                }
                $locked->status = VisitOfferStatus::Published;
                $locked->published_at = now()->toImmutable();
                $action = AuditAction::CatalogOpenDayCampaignPublished;
            } elseif ($transition === 'close') {
                abort_unless($locked->status === VisitOfferStatus::Published, 409);
                $locked->status = VisitOfferStatus::Closed;
                $action = AuditAction::CatalogOpenDayCampaignClosed;
            } else {
                abort_unless($locked->status !== VisitOfferStatus::Cancelled, 409);
                $locked->status = VisitOfferStatus::Cancelled;
                $action = AuditAction::CatalogOpenDayCampaignCancelled;
            }
            $locked->version++;
            $locked->save();
            $this->audit->record($action, new AuditableReference('open_day_campaign', $locked->id), ['version' => $locked->version]);

            return $locked->refresh()->load('visitOffers');
        });
    }

    private function authorize(User $actor, ?OpenDayCampaign $campaign = null, bool $publish = false): void
    {
        if ($campaign !== null) {
            abort_unless($campaign->organization_id === $this->context->organizationId(), 404);
        }
        $organization = Organization::query()->findOrFail($this->context->organizationId());
        Gate::forUser($actor)->authorize($publish ? 'catalog.publish' : 'catalog.manage', $organization);
        if ($publish) {
            abort_unless($organization->status === OrganizationStatus::Active, 403);
        }
    }

    /** @param list<string> $offerIds */
    private function syncOffers(OpenDayCampaign $campaign, array $offerIds): void
    {
        $offers = VisitOffer::query()->whereIn('id', $offerIds)->lockForUpdate()->get();
        if ($offers->count() !== count($offerIds)) {
            throw ValidationException::withMessages(['visit_offer_ids' => 'Някои предложения не са достъпни.']);
        }
        $campaign->visitOffers()->whereNotIn('id', $offerIds)->update(['campaign_id' => null]);
        VisitOffer::query()->whereIn('id', $offerIds)->update(['campaign_id' => $campaign->id]);
    }
}
