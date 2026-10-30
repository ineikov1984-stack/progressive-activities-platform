<?php

namespace App\Domain\Organizations\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Tenancy\TenantScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property OrganizationRole $role
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $accepted_at
 * @property string|null $staff_member_id the person of the team it invites (docs/product/team.md, т. 7); none in the old form
 * @property string $token_hash the e-mail's token (in the old form, the one token the inviter saw)
 * @property string|null $copy_token_hash the link the inviter copies: it only joins an existing profile; none in the old form
 */
#[Fillable([
    'organization_id',
    'email',
    'role',
    'token_hash',
    'active_key',
    'expires_at',
    'accepted_at',
    'invited_by',
    'accepted_by',
    'staff_member_id',
    'copy_token_hash',
])]
#[Hidden(['token_hash', 'copy_token_hash', 'active_key'])]
class Invitation extends Model
{
    use HasUuids;

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    /** "pending", "expired" or "accepted". */
    public function status(): string
    {
        return match (true) {
            $this->accepted_at !== null => 'accepted',
            $this->expires_at->isPast() => 'expired',
            default => 'pending',
        };
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /** @return BelongsTo<User, $this> */
    public function accepter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'role' => OrganizationRole::class,
            'expires_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
        ];
    }
}
