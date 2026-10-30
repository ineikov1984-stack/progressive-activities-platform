<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Catalog\Concerns\BelongsToCatalogTenant;
use App\Domain\Enrollment\Models\EnrollmentSessionException;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 */
#[Fillable([
    'group_id',
    'location_id',
    'starts_at',
    'ends_at',
    'capacity',
    'status',
    'change_reason',
    'version',
])]
class Session extends Model
{
    use BelongsToCatalogTenant;
    use HasUuids;

    protected $table = 'activity_sessions';

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Group, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return BelongsToMany<InstructorProfile, $this> */
    public function instructors(): BelongsToMany
    {
        return $this->belongsToMany(InstructorProfile::class, 'activity_session_instructors', 'session_id', 'instructor_profile_id')
            ->withPivotValue('organization_id', $this->organization_id ?? app(TenantContext::class)->organizationId())
            // "assignment" — from the group's assignments; "manual" — changed by hand for this session (docs/product/team.md, т. 5.2).
            ->withPivot(['role', 'source'])
            ->withTimestamps();
    }

    /** @return BelongsToMany<VisitOffer, $this> */
    public function visitOffers(): BelongsToMany
    {
        return $this->belongsToMany(VisitOffer::class, 'visit_offer_sessions', 'session_id', 'visit_offer_id')
            ->withPivotValue('organization_id', $this->organization_id ?? app(TenantContext::class)->organizationId())
            ->withTimestamps();
    }

    /** @return HasMany<EnrollmentSessionException, $this> */
    public function enrollmentExceptions(): HasMany
    {
        return $this->hasMany(EnrollmentSessionException::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'capacity' => 'integer',
            'version' => 'integer',
        ];
    }
}
