<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Catalog\Concerns\BelongsToCatalogTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One change of the schedule by the office (docs/product/schedule-changes.md, т. 3.1) — for one session or for many.
 * Never changed and never deleted on its own (database trigger, no right of the application to do so): a wrong change
 * is put right by a new one. The reason is for the office; a letter quotes it only with `reason_to_parents`.
 *
 * @property string $organization_id
 * @property string $action move, cancel, restore, change_location, holiday or add
 * @property string|null $reason
 * @property bool $reason_to_parents
 * @property bool $notify_parents
 * @property array<string, mixed>|null $criteria
 * @property int $session_count
 * @property string $idempotency_key
 * @property string $request_hash
 * @property array<string, mixed> $result
 * @property string $actor_user_id
 * @property CarbonImmutable $created_at
 */
#[Fillable([
    'id', 'action', 'reason', 'reason_to_parents', 'notify_parents', 'criteria', 'session_count', 'idempotency_key',
    'request_hash', 'result', 'actor_user_id', 'created_at',
])]
class ScheduleChange extends Model
{
    use BelongsToCatalogTenant;
    use HasUuids;

    /** The row is never updated. */
    public const UPDATED_AT = null;

    protected $table = 'schedule_changes';

    /** @return HasMany<SessionChange, $this> */
    public function sessions(): HasMany
    {
        return $this->hasMany(SessionChange::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'reason_to_parents' => 'boolean',
            'notify_parents' => 'boolean',
            'criteria' => 'array',
            'session_count' => 'integer',
            'result' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
