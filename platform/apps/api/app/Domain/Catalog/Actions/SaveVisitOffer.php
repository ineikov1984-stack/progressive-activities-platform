<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\ValueObjects\AuditableReference;
use App\Domain\Catalog\Enums\VisitOfferStatus;
use App\Domain\Catalog\Models\Activity;
use App\Domain\Catalog\Models\Group;
use App\Domain\Catalog\Models\OpenDayCampaign;
use App\Domain\Catalog\Models\Session;
use App\Domain\Catalog\Models\VisitOffer;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Tenancy\TenantContext;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final readonly class SaveVisitOffer
{
    private const FIELDS = [
        'activity_id', 'group_id', 'campaign_id', 'type', 'title', 'description',
        'audience_age_label', 'audience_class_label', 'visit_limit', 'fee_type',
        'amount_cents', 'currency', 'fee_reason', 'booking_deadline_hours',
        'cancellation_deadline_hours', 'cancellation_policy', 'cancellation_outcome',
        'terms_version', 'trial_limit_per_academic_year', 'single_visit_limit',
        'single_visit_window_days', 'unlimited_single_visits', 'no_show_trial_limit',
        'waitlist_enabled',
    ];

    private const PROTECTED_FIELDS = [
        'activity_id', 'group_id', 'type', 'visit_limit', 'fee_type', 'amount_cents',
        'currency', 'fee_reason', 'booking_deadline_hours', 'cancellation_deadline_hours',
        'cancellation_policy', 'cancellation_outcome', 'terms_version',
        'trial_limit_per_academic_year', 'single_visit_limit', 'single_visit_window_days',
        'unlimited_single_visits', 'no_show_trial_limit', 'waitlist_enabled',
    ];

    public function __construct(private TenantContext $context, private AuditRecorder $audit) {}

    /** @param array<string, mixed> $data */
    public function create(User $actor, array $data): VisitOffer
    {
        $organization = Organization::query()->findOrFail($this->context->organizationId());
        Gate::forUser($actor)->authorize('catalog.manage', $organization);

        return DB::transaction(function () use ($data): VisitOffer {
            $this->checkRelations($data);
            $offer = VisitOffer::query()->create(Arr::only($data, self::FIELDS));
            $offer->sessions()->sync($data['session_ids'] ?? []);
            $this->audit->record(AuditAction::CatalogVisitOfferCreated, new AuditableReference('visit_offer', $offer->id), ['version' => 1]);

            return $offer->load('sessions');
        });
    }

    /** @param array<string, mixed> $data */
    public function update(User $actor, VisitOffer $offer, int $expectedVersion, array $data): VisitOffer
    {
        $this->authorize($actor, $offer);

        return DB::transaction(function () use ($offer, $expectedVersion, $data): VisitOffer {
            $locked = VisitOffer::query()->whereKey($offer->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->version === $expectedVersion, 409, 'Visit offer version conflict.');
            abort_if($locked->status === VisitOfferStatus::Cancelled, 409, 'Cancelled offers cannot be edited.');
            $effective = array_merge($locked->only(self::FIELDS), $data);
            $this->checkRelations($effective, $data['session_ids'] ?? $locked->sessions()->pluck('activity_sessions.id')->all());

            $before = $locked->only(self::FIELDS);
            $oldSessions = $locked->sessions()->pluck('activity_sessions.id')->sort()->values()->all();
            $newSessions = $data['session_ids'] ?? $oldSessions;
            sort($newSessions);
            if ($locked->status === VisitOfferStatus::Published && array_key_exists('session_ids', $data)
                && ($newSessions === [] || Session::query()->whereIn('id', $newSessions)
                    ->where('status', 'scheduled')->where('starts_at', '>', now())->count() !== count($newSessions))) {
                throw ValidationException::withMessages(['session_ids' => 'Публикуваното предложение изисква бъдещи занятия.']);
            }
            if ($locked->applications()->where('status', 'approved')->exists()) {
                foreach (self::PROTECTED_FIELDS as $field) {
                    if (array_key_exists($field, $data) && $before[$field] != $data[$field]) {
                        throw ValidationException::withMessages([$field => 'Потвърдените посещения не позволяват тази промяна.']);
                    }
                }
                if ($newSessions !== $oldSessions) {
                    throw ValidationException::withMessages(['session_ids' => 'Потвърдените посещения не позволяват смяна на занятията.']);
                }
            }

            $groupChanged = array_key_exists('group_id', $data) && $data['group_id'] !== $locked->group_id;
            if ($groupChanged) {
                $locked->sessions()->detach();
            }
            $locked->fill(Arr::only($data, self::FIELDS));
            $locked->version++;
            $locked->save();
            if ($groupChanged || array_key_exists('session_ids', $data)) {
                $locked->sessions()->sync($newSessions);
            }

            $this->audit->record(AuditAction::CatalogVisitOfferUpdated, new AuditableReference('visit_offer', $locked->id), ['version' => $locked->version]);
            $groups = [
                [AuditAction::CatalogVisitOfferCapacityChanged, ['visit_limit']],
                [AuditAction::CatalogVisitOfferPriceChanged, ['fee_type', 'amount_cents', 'currency', 'fee_reason']],
                [AuditAction::CatalogVisitOfferDeadlineChanged, ['booking_deadline_hours', 'cancellation_deadline_hours']],
                [AuditAction::CatalogVisitOfferPolicyChanged, [
                    'cancellation_policy', 'cancellation_outcome', 'terms_version', 'trial_limit_per_academic_year',
                    'single_visit_limit', 'single_visit_window_days', 'unlimited_single_visits',
                    'no_show_trial_limit', 'waitlist_enabled',
                ]],
            ];
            foreach ($groups as [$action, $fields]) {
                $changes = [];
                foreach ($fields as $field) {
                    if (array_key_exists($field, $data) && $before[$field] != $locked->{$field}) {
                        $changes[$field] = ['old' => $before[$field], 'new' => $locked->{$field}];
                    }
                }
                if ($changes !== []) {
                    $this->audit->record($action, new AuditableReference('visit_offer', $locked->id), ['version' => $locked->version, 'changes' => $changes]);
                }
            }

            return $locked->refresh()->load('sessions');
        });
    }

    private function authorize(User $actor, VisitOffer $offer): void
    {
        abort_unless($offer->organization_id === $this->context->organizationId(), 404);
        Gate::forUser($actor)->authorize('catalog.manage', Organization::query()->findOrFail($offer->organization_id));
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>|null  $sessionIds
     */
    private function checkRelations(array $data, ?array $sessionIds = null): void
    {
        $tenant = $this->context->organizationId();
        $activity = Activity::query()->whereKey($data['activity_id'])->first();
        $group = Group::query()->whereKey($data['group_id'])->with('offering')->first();
        if ($activity === null || $group === null || $group->offering->activity_id !== $activity->id) {
            throw ValidationException::withMessages(['group_id' => 'Групата трябва да принадлежи на дейността.']);
        }
        if (($data['campaign_id'] ?? null) !== null
            && ! OpenDayCampaign::query()->whereKey($data['campaign_id'])->exists()) {
            throw ValidationException::withMessages(['campaign_id' => 'Кампанията не е достъпна.']);
        }
        foreach ($sessionIds ?? $data['session_ids'] ?? [] as $sessionId) {
            if (! Session::query()->whereKey($sessionId)->where('group_id', $group->id)->where('organization_id', $tenant)->exists()) {
                throw ValidationException::withMessages(['session_ids' => 'Занятията трябва да принадлежат на избраната група.']);
            }
        }
    }
}
