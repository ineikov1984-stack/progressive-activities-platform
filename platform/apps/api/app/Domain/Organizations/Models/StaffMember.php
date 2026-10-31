<?php

namespace App\Domain\Organizations\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Tenancy\TenantContext;
use App\Domain\Organizations\Tenancy\TenantScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A person of an organization's team, with or without a login (docs/product/team.md, т. 3). The phone and the email
 * are seen only with "team.manage" and never leave the administration.
 *
 * @property string $full_name
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $user_id
 * @property CarbonImmutable|null $archived_at
 * @property int $version
 * @property string|null $access_scope own, groups or all; null — the role's default (docs/product/team-rights.md, т. 4.1)
 * @property string|null $access_template school_partner, the label of a template (т. 7)
 */
#[Fillable(['full_name', 'phone', 'email', 'user_id', 'archived_at', 'version', 'created_by'])]
#[Hidden(['phone', 'email'])]
class StaffMember extends Model
{
    use HasUuids;

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
        static::creating(function (StaffMember $member): void {
            $member->setAttribute('organization_id', $member->getAttribute('organization_id') ?? app(TenantContext::class)->organizationId());
        });
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

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['archived_at' => 'immutable_datetime', 'version' => 'integer'];
    }
}
