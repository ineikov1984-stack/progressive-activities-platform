<?php

namespace App\Domain\Enrollment\Models;

use App\Domain\Catalog\Concerns\BelongsToCatalogTenant;
use App\Domain\Catalog\Models\Group;
use App\Domain\Catalog\Models\PriceOption;
use App\Domain\Catalog\Models\Session;
use App\Domain\Enrollment\Enums\EnrollmentScope;
use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Organizations\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property EnrollmentScope $scope
 * @property EnrollmentStatus $status
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable|null $ends_on
 * @property CarbonImmutable|null $starts_not_before a continuation after a transfer starts on this day at the earliest
 */
#[Fillable([
    'application_id', 'child_id', 'group_id', 'price_option_id', 'session_id',
    'scope', 'status', 'starts_on', 'ends_on', 'transferred_from_id', 'version', 'starts_not_before',
])]
class Enrollment extends Model
{
    use BelongsToCatalogTenant;
    use HasUuids;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'active',
        'version' => 1,
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

    /** @return BelongsTo<Enrollment, $this> */
    public function transferredFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'transferred_from_id');
    }

    /**
     * The enrollment that continues this one in another group, if it was transferred.
     *
     * @return HasOne<Enrollment, $this>
     */
    public function transferredTo(): HasOne
    {
        return $this->hasOne(self::class, 'transferred_from_id');
    }

    /** @return HasMany<EnrollmentTransition, $this> */
    public function transitions(): HasMany
    {
        return $this->hasMany(EnrollmentTransition::class);
    }

    /** @return HasMany<EnrollmentSessionException, $this> */
    public function sessionExceptions(): HasMany
    {
        return $this->hasMany(EnrollmentSessionException::class);
    }

    /** @return HasMany<EnrollmentPause, $this> */
    public function pauses(): HasMany
    {
        return $this->hasMany(EnrollmentPause::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'scope' => EnrollmentScope::class,
            'status' => EnrollmentStatus::class,
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'starts_not_before' => 'immutable_date',
            'version' => 'integer',
        ];
    }
}
