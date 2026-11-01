<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Attendance\Enums\AttendanceStatus;
use App\Domain\Attendance\Enums\MarkKind;
use App\Domain\Catalog\Concerns\BelongsToCatalogTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['mark_id', 'from_status', 'to_status', 'version', 'kind', 'reason', 'actor_user_id', 'request_key', 'request_hash', 'created_at'])]
class AttendanceMarkChange extends Model
{
    use BelongsToCatalogTenant;
    use HasUuids;

    public const UPDATED_AT = null;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'from_status' => AttendanceStatus::class,
            'to_status' => AttendanceStatus::class,
            'kind' => MarkKind::class,
            'version' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }
}
