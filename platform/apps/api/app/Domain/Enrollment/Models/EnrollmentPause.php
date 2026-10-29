<?php

namespace App\Domain\Enrollment\Models;

use App\Domain\Catalog\Concerns\BelongsToCatalogTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A period in which a full-period enrollment does not attend (illness, holiday).
 * The child keeps the place in the group; sessions inside the period do not count.
 *
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable $ends_on
 * @property CarbonImmutable|null $cancelled_at
 */
#[Fillable(['enrollment_id', 'starts_on', 'ends_on', 'reason_category', 'note', 'created_by', 'ended_by', 'cancelled_at'])]
class EnrollmentPause extends Model
{
    use BelongsToCatalogTenant;
    use HasUuids;

    /** @return BelongsTo<Enrollment, $this> */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'cancelled_at' => 'immutable_datetime',
        ];
    }
}
