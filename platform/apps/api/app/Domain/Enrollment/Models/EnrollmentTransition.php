<?php

namespace App\Domain\Enrollment\Models;

use App\Domain\Catalog\Concerns\BelongsToCatalogTenant;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\Organization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property array<string, mixed>|null $metadata */
#[Fillable([
    'application_id', 'enrollment_id', 'actor_user_id', 'from_status',
    'to_status', 'public_reason', 'internal_note', 'metadata',
])]
class EnrollmentTransition extends Model
{
    use BelongsToCatalogTenant;
    use HasUuids;

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

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }
}
