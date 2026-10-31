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
 * A group, or a whole activity with its later groups, in a person's scope "Избрани групи" (docs/product/team-rights.md,
 * т. 4.2). Only identifiers: that the group is of the same organization the composite key keeps, and only Catalog reads
 * what they mean. Never deleted: removed with who and when.
 *
 * @property string $staff_member_id
 * @property string|null $group_id
 * @property string|null $activity_id
 * @property string $added_by
 * @property CarbonImmutable $added_at
 * @property CarbonImmutable|null $removed_at
 * @property string|null $removed_by
 */
#[Fillable(['staff_member_id', 'group_id', 'activity_id', 'added_by', 'added_at', 'removed_at', 'removed_by'])]
class StaffScopeGroup extends Model
{
    use HasUuids;

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
        static::creating(function (StaffScopeGroup $row): void {
            $row->setAttribute('organization_id', $row->getAttribute('organization_id') ?? app(TenantContext::class)->organizationId());
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
        return ['added_at' => 'immutable_datetime', 'removed_at' => 'immutable_datetime'];
    }
}
