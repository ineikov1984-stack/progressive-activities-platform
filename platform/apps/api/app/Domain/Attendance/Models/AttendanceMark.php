<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Attendance\Enums\AttendanceStatus;
use App\Domain\Catalog\Concerns\BelongsToCatalogTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property AttendanceStatus|null $status
 * @property int $version
 * @property CarbonImmutable $marked_at
 */
#[Fillable(['session_id', 'enrollment_id', 'status', 'version', 'marked_by', 'marked_at'])]
class AttendanceMark extends Model
{
    use BelongsToCatalogTenant;
    use HasUuids;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => AttendanceStatus::class,
            'version' => 'integer',
            'marked_at' => 'immutable_datetime',
        ];
    }
}
