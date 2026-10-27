<?php

namespace App\Domain\Organizations\Models;

use App\Domain\Organizations\Enums\OrganizationStatus;
use App\Domain\Organizations\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class OrganizationReview extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
        static::updating(fn (): never => throw new LogicException('Organization reviews are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Organization reviews are immutable.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'from_status' => OrganizationStatus::class,
            'to_status' => OrganizationStatus::class,
            'created_at' => 'immutable_datetime',
        ];
    }
}
