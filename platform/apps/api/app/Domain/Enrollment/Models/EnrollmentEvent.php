<?php

namespace App\Domain\Enrollment\Models;

use App\Domain\Catalog\Concerns\BelongsToCatalogTenant;
use App\Domain\Organizations\Models\Organization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'type', 'application_id', 'enrollment_id', 'payload', 'occurred_at',
    'delivered_at', 'attempts',
])]
class EnrollmentEvent extends Model
{
    use BelongsToCatalogTenant;
    use HasUuids;

    /** @var array<string, mixed> */
    protected $attributes = [
        'attempts' => 0,
    ];

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<EnrollmentApplication, $this> */
    public function application(): BelongsTo
    {
        return $this->belongsTo(EnrollmentApplication::class);
    }

    /** @return BelongsTo<Enrollment, $this> */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'attempts' => 'integer',
        ];
    }
}
