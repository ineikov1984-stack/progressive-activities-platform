<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Catalog\Concerns\BelongsToCatalogTenant;
use App\Domain\Organizations\Models\Organization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

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
            ->withPivotValue('organization_id', $this->organization_id)
            ->withTimestamps();
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
