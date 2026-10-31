<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Catalog\Concerns\BelongsToCatalogTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One session in a change of the schedule (docs/product/schedule-changes.md, т. 3.2): what it was and what it became.
 * Never changed; only a deleted place clears itself out of it. The latest change of a session is the one with the
 * greatest `schedule_change_id` (the identifiers are time-ordered).
 *
 * @property string $organization_id
 * @property string $schedule_change_id
 * @property string $session_id
 * @property string|null $from_status
 * @property string $to_status
 * @property CarbonImmutable|null $from_starts_at
 * @property CarbonImmutable|null $from_ends_at
 * @property CarbonImmutable $to_starts_at
 * @property CarbonImmutable $to_ends_at
 * @property string|null $from_location_id
 * @property string|null $to_location_id
 * @property list<array{type: string, session_id: string, other_session_id: string|null}> $confirmed_overlaps
 * @property CarbonImmutable $created_at
 */
#[Fillable([
    'schedule_change_id', 'session_id', 'from_status', 'to_status', 'from_starts_at', 'from_ends_at', 'to_starts_at',
    'to_ends_at', 'from_location_id', 'to_location_id', 'confirmed_overlaps', 'created_at',
])]
class SessionChange extends Model
{
    use BelongsToCatalogTenant;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'session_changes';

    /** @return BelongsTo<ScheduleChange, $this> */
    public function change(): BelongsTo
    {
        return $this->belongsTo(ScheduleChange::class, 'schedule_change_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'from_starts_at' => 'immutable_datetime',
            'from_ends_at' => 'immutable_datetime',
            'to_starts_at' => 'immutable_datetime',
            'to_ends_at' => 'immutable_datetime',
            'confirmed_overlaps' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
