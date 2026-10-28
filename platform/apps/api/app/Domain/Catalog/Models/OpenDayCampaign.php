<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Catalog\Concerns\BelongsToCatalogTenant;
use App\Domain\Catalog\Enums\VisitOfferStatus;
use App\Domain\Organizations\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property VisitOfferStatus $status
 * @property CarbonImmutable|null $starts_on
 * @property CarbonImmutable|null $ends_on
 * @property CarbonImmutable|null $published_at
 */
#[Fillable(['slug', 'title', 'description', 'starts_on', 'ends_on', 'image_path', 'status', 'published_at', 'version'])]
class OpenDayCampaign extends Model
{
    use BelongsToCatalogTenant;
    use HasUuids;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'draft',
        'version' => 1,
    ];

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return HasMany<VisitOffer, $this> */
    public function visitOffers(): HasMany
    {
        return $this->hasMany(VisitOffer::class, 'campaign_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'status' => VisitOfferStatus::class,
            'published_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }
}
