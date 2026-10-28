<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Catalog\Concerns\BelongsToCatalogTenant;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property array<string, mixed>|null $schedule_pattern */
#[Fillable([
    'offering_id',
    'location_id',
    'name',
    'age_label',
    'capacity_min',
    'capacity_max',
    'schedule_pattern',
    'enrollment_approval_mode',
    'waitlist_enabled',
    'status',
    'version',
])]
class Group extends Model
{
    use BelongsToCatalogTenant;
    use HasUuids;

    protected $table = 'activity_groups';

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Offering, $this> */
    public function offering(): BelongsTo
    {
        return $this->belongsTo(Offering::class);
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return HasMany<Session, $this> */
    public function sessions(): HasMany
    {
        return $this->hasMany(Session::class);
    }

    /** @return HasMany<PriceOption, $this> */
    public function priceOptions(): HasMany
    {
        return $this->hasMany(PriceOption::class);
    }

    /** @return HasMany<Enrollment, $this> */
    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    /** @return BelongsToMany<InstructorProfile, $this> */
    public function instructors(): BelongsToMany
    {
        return $this->belongsToMany(InstructorProfile::class, 'activity_group_instructors', 'group_id', 'instructor_profile_id')
            ->withPivotValue('organization_id', $this->organization_id ?? app(TenantContext::class)->organizationId())
            ->withTimestamps();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'capacity_min' => 'integer',
            'capacity_max' => 'integer',
            'schedule_pattern' => 'array',
            'waitlist_enabled' => 'boolean',
            'version' => 'integer',
        ];
    }
}
