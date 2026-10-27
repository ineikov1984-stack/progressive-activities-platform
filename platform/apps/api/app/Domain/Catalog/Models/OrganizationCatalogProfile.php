<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Catalog\Concerns\BelongsToCatalogTenant;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'public_name',
    'intro',
    'logo_path',
    'accent_color',
    'is_published',
    'version',
])]
class OrganizationCatalogProfile extends Model
{
    use BelongsToCatalogTenant;
    use HasUuids;

    protected static function booted(): void
    {
        static::creating(function (self $profile): void {
            $profile->slug = Organization::query()
                ->findOrFail(app(TenantContext::class)->organizationId())
                ->slug;
        });
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'version' => 'integer',
        ];
    }
}
