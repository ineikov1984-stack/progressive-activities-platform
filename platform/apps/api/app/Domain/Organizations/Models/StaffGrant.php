<?php

namespace App\Domain\Organizations\Models;

use App\Domain\Organizations\Tenancy\TenantContext;
use App\Domain\Organizations\Tenancy\TenantScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A right added to a person of the team on top of the role (docs/product/team-rights.md, т. 4.3). Never deleted: it is
 * revoked with who, when and why; who gave it, what and when never change (database trigger). It gives nothing without
 * an active membership, after `expires_at` or once revoked.
 *
 * @property string $staff_member_id
 * @property string $ability
 * @property string $granted_by
 * @property CarbonImmutable $granted_at
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $revoked_at
 * @property string|null $revoked_by
 * @property string|null $revoked_reason removed, role_changed, scope_changed, archived or expired
 */
#[Fillable(['staff_member_id', 'ability', 'granted_by', 'granted_at', 'expires_at', 'revoked_at', 'revoked_by', 'revoked_reason'])]
class StaffGrant extends Model
{
    use HasUuids;

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
        static::creating(function (StaffGrant $grant): void {
            $grant->setAttribute('organization_id', $grant->getAttribute('organization_id') ?? app(TenantContext::class)->organizationId());
        });
    }

    /** @return BelongsTo<StaffMember, $this> */
    public function staffMember(): BelongsTo
    {
        return $this->belongsTo(StaffMember::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'granted_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }
}
