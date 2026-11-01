<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Catalog\Concerns\BelongsToCatalogTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['requester_user_id', 'requester_profile_id', 'reason', 'note', 'suggested_profile_id', 'closed_at', 'version', 'idempotency_key', 'request_hash'])]
class SubstituteRequest extends Model
{
    use BelongsToCatalogTenant;
    use HasUuids;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['closed_at' => 'immutable_datetime', 'version' => 'integer'];
    }
}
