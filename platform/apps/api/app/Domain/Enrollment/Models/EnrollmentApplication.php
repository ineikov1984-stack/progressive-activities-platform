<?php

namespace App\Domain\Enrollment\Models;

use App\Domain\Catalog\Concerns\BelongsToCatalogTenant;
use App\Domain\Catalog\Models\Group;
use App\Domain\Catalog\Models\PriceOption;
use App\Domain\Catalog\Models\Session;
use App\Domain\Enrollment\Enums\EnrollmentApplicationStatus;
use App\Domain\Enrollment\Enums\EnrollmentScope;
use App\Domain\Enrollment\Enums\EnrollmentSource;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\Organization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'parent_user_id', 'child_id', 'group_id', 'price_option_id', 'session_id',
    'scope', 'source', 'status', 'idempotency_key', 'accepted_terms_version',
    'accepted_terms_at', 'amount_cents_snapshot', 'currency_snapshot',
    'unit_snapshot', 'public_reason', 'internal_note', 'version',
])]
class EnrollmentApplication extends Model
{
    use BelongsToCatalogTenant;
    use HasUuids;

    /** @var array<string, mixed> */
    protected $attributes = [
        'version' => 1,
    ];

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'parent_user_id');
    }

    /** @return BelongsTo<Child, $this> */
    public function child(): BelongsTo
    {
        return $this->belongsTo(Child::class);
    }

    /** @return BelongsTo<Group, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /** @return BelongsTo<PriceOption, $this> */
    public function priceOption(): BelongsTo
    {
        return $this->belongsTo(PriceOption::class);
    }

    /** @return BelongsTo<Session, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(Session::class);
    }

    /** @return HasOne<Enrollment, $this> */
    public function enrollment(): HasOne
    {
        return $this->hasOne(Enrollment::class, 'application_id');
    }

    /** @return HasMany<EnrollmentTransition, $this> */
    public function transitions(): HasMany
    {
        return $this->hasMany(EnrollmentTransition::class, 'application_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'scope' => EnrollmentScope::class,
            'source' => EnrollmentSource::class,
            'status' => EnrollmentApplicationStatus::class,
            'accepted_terms_at' => 'immutable_datetime',
            'amount_cents_snapshot' => 'integer',
            'version' => 'integer',
        ];
    }
}
