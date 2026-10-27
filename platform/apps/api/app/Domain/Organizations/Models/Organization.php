<?php

namespace App\Domain\Organizations\Models;

use App\Domain\Organizations\Enums\OrganizationStatus;
use App\Domain\Organizations\Tenancy\TenantScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property OrganizationStatus $status
 * @property int $review_version
 * @property CarbonImmutable|null $submitted_at
 * @property CarbonImmutable|null $reviewed_at
 */
#[Fillable([
    'name',
    'slug',
    'status',
    'review_version',
    'organization_type',
    'registration_identifier',
    'public_contact_email',
    'public_review_reason',
    'next_action',
    'submitted_at',
    'reviewed_at',
    'reviewed_by',
])]
class Organization extends Model
{
    use HasUuids;

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope('id'));
    }

    /** @return HasMany<OrganizationMembership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationMembership::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => OrganizationStatus::class,
            'review_version' => 'integer',
            'submitted_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
        ];
    }
}
