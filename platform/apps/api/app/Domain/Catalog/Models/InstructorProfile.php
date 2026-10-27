<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Catalog\Concerns\BelongsToCatalogTenant;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\Organization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id',
    'public_name',
    'role_title',
    'bio',
    'qualifications',
    'photo_path',
    'is_public',
    'version',
])]
class InstructorProfile extends Model
{
    use BelongsToCatalogTenant;
    use HasUuids;

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Offering, $this> */
    public function leadOfferings(): HasMany
    {
        return $this->hasMany(Offering::class, 'lead_instructor_profile_id');
    }

    /** @return BelongsToMany<Group, $this> */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class, 'activity_group_instructors', 'instructor_profile_id', 'group_id')
            ->withPivotValue('organization_id', $this->organization_id)
            ->withTimestamps();
    }

    /** @return BelongsToMany<Session, $this> */
    public function sessions(): BelongsToMany
    {
        return $this->belongsToMany(Session::class, 'activity_session_instructors', 'instructor_profile_id', 'session_id')
            ->withPivotValue('organization_id', $this->organization_id)
            ->withTimestamps();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'qualifications' => 'array',
            'is_public' => 'boolean',
            'version' => 'integer',
        ];
    }
}
