<?php

namespace App\Domain\Enrollment\Models;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'owner_user_id',
    'first_name',
    'last_name',
    'birth_date',
    'school_name',
    'class_label',
    'institution_group',
    'status',
    'version',
])]
class Child extends Model
{
    use HasUuids;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'active',
        'version' => 1,
    ];

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /** @return HasMany<EnrollmentApplication, $this> */
    public function applications(): HasMany
    {
        return $this->hasMany(EnrollmentApplication::class);
    }

    /** @return HasMany<Enrollment, $this> */
    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'birth_date' => 'immutable_date',
            'version' => 'integer',
        ];
    }
}
