<?php

namespace App\Domain\Organizations\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Tenancy\TenantScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property CarbonImmutable|null $last_login_at the last sign-in or selection of the organization
 * @property CarbonImmutable|null $last_seen_at the last request in it, at most once in five minutes
 */
#[Fillable(['organization_id', 'user_id', 'role', 'status'])]
class OrganizationMembership extends Model
{
    use HasUuids;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['last_login_at' => 'immutable_datetime', 'last_seen_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
