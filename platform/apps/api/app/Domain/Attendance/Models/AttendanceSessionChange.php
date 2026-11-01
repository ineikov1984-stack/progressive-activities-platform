<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Catalog\Concerns\BelongsToCatalogTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['attendance_session_id', 'action', 'reason', 'actor_user_id', 'version', 'created_at'])]
class AttendanceSessionChange extends Model
{
    use BelongsToCatalogTenant;
    use HasUuids;

    public const UPDATED_AT = null;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }
}
