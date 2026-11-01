<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Catalog\Concerns\BelongsToCatalogTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['request_id', 'requester_profile_id', 'session_id', 'status', 'closed_reason', 'substitute_profile_id', 'schedule_change_id', 'decided_by_user_id', 'closed_at'])]
class SubstituteRequestSession extends Model
{
    use BelongsToCatalogTenant;
    use HasUuids;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['closed_at' => 'immutable_datetime'];
    }
}
