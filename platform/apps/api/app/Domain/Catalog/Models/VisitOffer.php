<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Catalog\Concerns\BelongsToCatalogTenant;
use App\Domain\Catalog\Enums\VisitFeeType;
use App\Domain\Catalog\Enums\VisitOfferStatus;
use App\Domain\Catalog\Enums\VisitOfferType;
use App\Domain\Enrollment\Models\EnrollmentApplication;
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
 * @property VisitOfferType $type
 * @property VisitOfferStatus $status
 * @property VisitFeeType $fee_type
 * @property CarbonImmutable|null $published_at
 * @property CarbonImmutable|null $closed_at
 * @property CarbonImmutable|null $cancelled_at
 */
#[Fillable([
    'activity_id', 'group_id', 'campaign_id', 'type', 'status', 'title', 'description',
    'audience_age_label', 'audience_class_label', 'visit_limit', 'fee_type',
    'amount_cents', 'currency', 'fee_reason', 'booking_deadline_hours',
    'cancellation_deadline_hours', 'cancellation_policy', 'cancellation_outcome',
    'terms_version', 'trial_limit_per_academic_year', 'single_visit_limit',
    'single_visit_window_days', 'unlimited_single_visits', 'no_show_trial_limit',
    'waitlist_enabled', 'published_at', 'closed_at', 'cancelled_at', 'version',
])]
class VisitOffer extends Model
{
    use BelongsToCatalogTenant;
    use HasUuids;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'draft',
        'fee_type' => 'free',
        'amount_cents' => 0,
        'currency' => 'EUR',
        'booking_deadline_hours' => 12,
        'cancellation_deadline_hours' => 24,
        'cancellation_outcome' => 'non_refundable',
        'trial_limit_per_academic_year' => 1,
        'single_visit_limit' => 2,
        'single_visit_window_days' => 30,
        'unlimited_single_visits' => false,
        'waitlist_enabled' => true,
        'version' => 1,
    ];

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

    /** @return BelongsTo<Group, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /** @return BelongsTo<OpenDayCampaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(OpenDayCampaign::class, 'campaign_id');
    }

    /** @return BelongsToMany<Session, $this> */
    public function sessions(): BelongsToMany
    {
        return $this->belongsToMany(Session::class, 'visit_offer_sessions', 'visit_offer_id', 'session_id')
            ->withPivotValue('organization_id', $this->organization_id ?? app(TenantContext::class)->organizationId())
            ->withTimestamps();
    }

    /** @return HasMany<EnrollmentApplication, $this> */
    public function applications(): HasMany
    {
        return $this->hasMany(EnrollmentApplication::class, 'visit_offer_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => VisitOfferType::class,
            'status' => VisitOfferStatus::class,
            'fee_type' => VisitFeeType::class,
            'visit_limit' => 'integer',
            'amount_cents' => 'integer',
            'booking_deadline_hours' => 'integer',
            'cancellation_deadline_hours' => 'integer',
            'trial_limit_per_academic_year' => 'integer',
            'single_visit_limit' => 'integer',
            'single_visit_window_days' => 'integer',
            'unlimited_single_visits' => 'boolean',
            'no_show_trial_limit' => 'integer',
            'waitlist_enabled' => 'boolean',
            'published_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }
}
