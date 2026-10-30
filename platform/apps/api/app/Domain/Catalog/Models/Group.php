<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Catalog\Concerns\BelongsToCatalogTenant;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property array<string, mixed>|null $schedule_pattern
 * @property list<string>|null $enrollment_modes the activity's ways of enrolling this group takes; null — all
 * @property int|null $visit_places children on a trial or single visit from a price in one session; null — every free place
 */
#[Fillable([
    'offering_id',
    'location_id',
    'name',
    'age_label',
    'capacity_min',
    'capacity_max',
    'schedule_pattern',
    'enrollment_approval_mode',
    'enrollment_modes',
    'visit_places',
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

    /**
     * The prices a child can be enrolled with in this group, only for the ways of enrolling it takes: its own
     * prices, and the activity's where the group has no available price of its own for that way. Pass the
     * activity's prices when they are already loaded with the needed filter. Callers still check `is_available`.
     *
     * @param  Collection<int, PriceOption>|null  $offeringPrices
     * @return Collection<int, PriceOption>
     */
    public function pricesForEnrollment(?Collection $offeringPrices = null): Collection
    {
        $own = $this->priceOptions->filter(fn (PriceOption $price): bool => $this->takesMode($price->enrollment_mode));
        $shared = ($offeringPrices ?? $this->offering->priceOptions)->whereNull('group_id')
            ->filter(fn (PriceOption $price): bool => $this->takesMode($price->enrollment_mode)
                && ! $own->contains(fn (PriceOption $mine): bool => $mine->is_available && $mine->enrollment_mode === $price->enrollment_mode));

        return $own->concat($shared)->values();
    }

    /**
     * The available price for a way of enrolling whether or not the group takes it in every session: its own, else
     * the activity's. A visit on chosen dates uses it (docs/product/visits.md, т. 8a.1).
     */
    public function availablePriceFor(string $mode): ?PriceOption
    {
        return $this->priceOptions->first(fn (PriceOption $price): bool => $price->is_available && $price->enrollment_mode === $mode)
            ?? $this->offering->priceOptions->first(fn (PriceOption $price): bool => $price->group_id === null
                && $price->is_available && $price->enrollment_mode === $mode);
    }

    public function takesMode(string $mode): bool
    {
        return $this->enrollment_modes === null || in_array($mode, $this->enrollment_modes, true);
    }

    /** @return HasMany<VisitOffer, $this> */
    public function visitOffers(): HasMany
    {
        return $this->hasMany(VisitOffer::class, 'group_id');
    }

    /** @return HasMany<Enrollment, $this> */
    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    /** @return BelongsToMany<InstructorProfile, $this> */
    public function instructors(): BelongsToMany
    {
        // Only who leads or assists the group now (docs/product/team.md, т. 5.3); a teacher who has left disappears
        // from the editor and the public page. The day is Sofia's, like the groups' schedules.
        $today = CarbonImmutable::now('Europe/Sofia')->toDateString();

        return $this->assignments()
            ->where(fn ($query) => $query->whereNull('activity_group_instructors.starts_on')->orWhere('activity_group_instructors.starts_on', '<=', $today))
            ->where(fn ($query) => $query->whereNull('activity_group_instructors.ends_on')->orWhere('activity_group_instructors.ends_on', '>=', $today));
    }

    /**
     * Every assignment of the group, ended ones included: one row per person, lead or assistant, with its period.
     *
     * @return BelongsToMany<InstructorProfile, $this>
     */
    public function assignments(): BelongsToMany
    {
        return $this->belongsToMany(InstructorProfile::class, 'activity_group_instructors', 'group_id', 'instructor_profile_id')
            ->withPivotValue('organization_id', $this->organization_id ?? app(TenantContext::class)->organizationId())
            ->withPivot(['role', 'starts_on', 'ends_on', 'version', 'created_by'])
            ->withTimestamps();
    }

    /**
     * The weekly days and times only (for lists the team scans), without dates and exceptions.
     *
     * @return array{frequency: string, slots: list<array{weekday: int, starts_at: string, duration_minutes: int}>}|null
     */
    public function weeklySlots(): ?array
    {
        $pattern = $this->schedule_pattern ?? [];
        $items = $pattern['slots'] ?? $pattern['patterns'] ?? [];
        if (! is_array($items)) {
            return null;
        }
        $slots = [];
        foreach ($items as $item) {
            if (is_array($item) && isset($item['weekday'], $item['starts_at'])) {
                $slots[] = [
                    'weekday' => (int) $item['weekday'],
                    'starts_at' => (string) $item['starts_at'],
                    'duration_minutes' => (int) ($item['duration_minutes'] ?? 0),
                ];
            }
        }

        return $slots === [] ? null : ['frequency' => ($pattern['frequency'] ?? 'weekly') === 'biweekly' ? 'biweekly' : 'weekly', 'slots' => $slots];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'capacity_min' => 'integer',
            'capacity_max' => 'integer',
            'schedule_pattern' => 'array',
            'enrollment_modes' => 'array',
            'visit_places' => 'integer',
            'waitlist_enabled' => 'boolean',
            'version' => 'integer',
        ];
    }
}
