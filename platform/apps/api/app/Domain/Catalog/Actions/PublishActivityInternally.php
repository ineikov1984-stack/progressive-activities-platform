<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\ValueObjects\AuditableReference;
use App\Domain\Catalog\Enums\ActivityStatus;
use App\Domain\Catalog\Enums\EnrollmentMode;
use App\Domain\Catalog\Enums\PriceUnit;
use App\Domain\Catalog\Models\Activity;
use App\Domain\Catalog\Models\Group;
use App\Domain\Catalog\Models\OrganizationCatalogProfile;
use App\Domain\Catalog\Services\ScheduleConflictDetector;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Enums\OrganizationStatus;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Tenancy\TenantContext;
use App\Domain\PaymentTerms\Services\PaymentTermsResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final readonly class PublishActivityInternally
{
    public function __construct(
        private TenantContext $context,
        private ScheduleConflictDetector $conflicts,
        private AuditRecorder $audit,
        private PaymentTermsResolver $paymentTerms,
    ) {}

    public function handle(User $actor, Activity $activity, int $expectedVersion): Activity
    {
        abort_unless($this->context->hasOrganization() && $activity->organization_id === $this->context->organizationId(), 404);
        $organization = Organization::query()->findOrFail($activity->organization_id);
        Gate::forUser($actor)->authorize('catalog.publish', $organization);

        return DB::transaction(function () use ($activity, $expectedVersion): Activity {
            $organization = Organization::query()->whereKey($activity->organization_id)->lockForUpdate()->firstOrFail();
            abort_unless($organization->status === OrganizationStatus::Active, 403, 'Only active organizations can publish.');
            $locked = Activity::query()->whereKey($activity->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->version === $expectedVersion, 409, 'Activity version conflict.');
            abort_unless($locked->status === ActivityStatus::Draft, 409, 'Only drafts can be published.');

            $this->validateCoreContent($locked);
            $offerings = $locked->offerings()->with(['priceOptions', 'groups.priceOptions', 'groups.sessions'])->lockForUpdate()->get();
            if ($offerings->isEmpty()) {
                throw ValidationException::withMessages(['offering' => 'Добавете предлагане преди публикуване.']);
            }
            // Parents need an address for questions: the activity's own or the organization's.
            if (blank($organization->public_contact_email) && $offerings->contains(fn ($offering) => blank($offering->contact_email))) {
                throw ValidationException::withMessages(['contact_email' => 'Добавете имейл за въпроси в стъпка 6 „Цени и записване“.']);
            }

            $groups = $offerings->flatMap->groups;
            if ($groups->isEmpty()) {
                throw ValidationException::withMessages(['groups' => 'Добавете поне една група преди публикуване.']);
            }

            foreach ($groups as $group) {
                $this->validateGroup($group);
            }
            if (OrganizationCatalogProfile::query()->where('is_published', true)->exists()) {
                $this->validateMarketplaceTerms($groups);
            }

            $locked->status = ActivityStatus::Published;
            $locked->published_at = now()->toImmutable();
            $locked->version++;
            $locked->save();
            Group::query()->whereIn('id', $groups->pluck('id')->all())->update(['status' => 'published']);

            $this->audit->record(
                AuditAction::CatalogActivityPublished,
                new AuditableReference('activity', $locked->id),
                ['version' => $locked->version],
            );

            return $locked->refresh();
        });
    }

    private function validateCoreContent(Activity $activity): void
    {
        foreach (['title', 'category', 'summary', 'age_label'] as $field) {
            if (trim($activity->{$field}) === '') {
                throw ValidationException::withMessages([$field => 'Полето е задължително за публикуване.']);
            }
        }
    }

    /**
     * In the public catalog every price's terms stay in the platform's frame (docs/product/payment-policies.md,
     * т. 7.5). Sets saved before the organization joined the catalog are checked here.
     *
     * @param  iterable<Group>  $groups
     */
    private function validateMarketplaceTerms(iterable $groups): void
    {
        foreach ($groups as $group) {
            $prices = $group->priceOptions->concat($group->offering->priceOptions->whereNull('group_id'))->where('is_available', true);
            foreach ($prices as $price) {
                if ($this->paymentTerms->forPrice($price->enrollment_mode, $group->offering->payment_terms_id)?->marketplaceViolations()) {
                    throw ValidationException::withMessages(['payment_terms' => 'Условията за плащане на тази дейност не са в рамките на каталога на платформата. Променете ги в „Настройки → Условия за плащане“.']);
                }
            }
        }
    }

    private function validateGroup(Group $group): void
    {
        if (! $group->sessions->contains(fn ($session) => $session->status === 'scheduled')) {
            throw ValidationException::withMessages(['sessions' => 'Групата трябва да има поне едно предстоящо занятие.']);
        }

        $prices = $group->priceOptions->concat($group->offering->priceOptions->whereNull('group_id'));
        $validPrice = $prices->contains(fn ($price) => $price->is_available
            && $price->currency === 'EUR'
            && $price->amount_cents > 0
            && EnrollmentMode::tryFrom($price->enrollment_mode) !== null
            && PriceUnit::tryFrom($price->unit) !== null);
        if (! $validPrice) {
            throw ValidationException::withMessages(['price_options' => 'Групата трябва да има поне една валидна цена в евро.']);
        }

        if (collect($this->conflicts->forGroup($group))->contains('blocking', true)) {
            abort(409, 'Blocking schedule conflict.');
        }
    }
}
