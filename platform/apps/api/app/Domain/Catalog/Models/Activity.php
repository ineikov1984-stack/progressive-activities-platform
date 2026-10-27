<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Catalog\Concerns\BelongsToCatalogTenant;
use App\Domain\Catalog\Enums\ActivityStatus;
use App\Domain\Organizations\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property ActivityStatus $status
 * @property CarbonImmutable|null $published_at
 */
#[Fillable([
    'slug',
    'title',
    'category',
    'summary',
    'age_label',
    'hero_image_path',
    'about',
    'benefits',
    'methodology',
    'participation_terms',
    'faq',
    'gallery',
    'instructor_intro',
    'status',
    'published_at',
    'version',
])]
class Activity extends Model
{
    use BelongsToCatalogTenant;
    use HasUuids;

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return HasMany<Offering, $this> */
    public function offerings(): HasMany
    {
        return $this->hasMany(Offering::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'benefits' => 'array',
            'faq' => 'array',
            'gallery' => 'array',
            'status' => ActivityStatus::class,
            'published_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }
}
