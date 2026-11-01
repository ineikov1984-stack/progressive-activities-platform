<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Catalog\Concerns\BelongsToCatalogTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['session_id', 'topic', 'not_held_at', 'not_held_by', 'not_held_reason', 'version'])]
class AttendanceSession extends Model
{
    use BelongsToCatalogTenant;
    use HasUuids;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'not_held_at' => 'immutable_datetime',
        ];
    }
}
