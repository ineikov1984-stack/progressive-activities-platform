<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Catalog\Concerns\BelongsToCatalogTenant;
use App\Domain\Organizations\Models\Organization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'activity_id',
    'location_id',
    'lead_instructor_profile_id',
    'title',
    'description',
    'terms',
    'cancellation_policy',
    'contact_email',
    'enrollment_instructions',
    'version',
])]
class Offering extends Model
{
    use BelongsToCatalogTenant;
    use HasUuids;

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Activity, $this> */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return BelongsTo<InstructorProfile, $this> */
    public function leadInstructor(): BelongsTo
    {
        return $this->belongsTo(InstructorProfile::class, 'lead_instructor_profile_id');
    }

    /** @return HasMany<Group, $this> */
    public function groups(): HasMany
    {
        return $this->hasMany(Group::class);
    }

    /** @return HasMany<PriceOption, $this> */
    public function priceOptions(): HasMany
    {
        return $this->hasMany(PriceOption::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['version' => 'integer'];
    }
}
