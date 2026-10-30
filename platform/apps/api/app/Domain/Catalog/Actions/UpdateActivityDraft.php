<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Enums\ActivityStatus;
use App\Domain\Catalog\Models\Activity;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Tenancy\TenantContext;
use App\Domain\PaymentTerms\Services\PaymentTermsResolver;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final readonly class UpdateActivityDraft
{
    public function __construct(
        private TenantContext $context,
        private SyncPriceOptions $prices,
        private PaymentTermsResolver $paymentTerms,
    ) {}

    /** @param array<string, mixed> $payload */
    public function handle(User $actor, Activity $activity, int $expectedVersion, array $payload): Activity
    {
        abort_unless($activity->organization_id === $this->context->organizationId(), 404);
        $organization = Organization::query()->findOrFail($activity->organization_id);
        Gate::forUser($actor)->authorize('catalog.manage', $organization);

        return DB::transaction(function () use ($activity, $expectedVersion, $payload): Activity {
            $locked = Activity::query()->whereKey($activity->getKey())->lockForUpdate()->firstOrFail();
            abort_unless($locked->version === $expectedVersion, 409, 'Activity version conflict.');
            abort_unless($locked->status === ActivityStatus::Draft, 409, 'Only drafts can be edited.');

            $locked->fill([
                ...Arr::only($payload, ['title', 'category', 'summary', 'age_label', 'hero_image_path']),
                ...Arr::only($payload['sections'] ?? [], [
                    'about', 'benefits', 'methodology', 'participation_terms', 'faq', 'gallery', 'instructor_intro',
                ]),
            ]);
            $locked->version++;
            $locked->save();

            if (array_key_exists('offering', $payload)) {
                $offering = $locked->offerings()->orderBy('created_at')->lockForUpdate()->firstOrFail();
                $offering->fill(Arr::only($payload['offering'], [
                    'location_id', 'lead_instructor_profile_id', 'title', 'description', 'terms',
                    'cancellation_policy', 'contact_email', 'enrollment_instructions', 'payment_terms_id',
                ]));
                $offering->version++;
                $offering->save();
                if (array_key_exists('price_options', $payload['offering'])) {
                    $this->prices->handle($offering, null, $payload['offering']['price_options']);
                }
                if (($payload['offering']['payment_terms_id'] ?? null) !== null) {
                    $this->paymentTerms->assertChoosable($payload['offering']['payment_terms_id'], $offering->priceOptions()
                        ->pluck('enrollment_mode')->all(), 'offering.payment_terms_id');
                }
            }

            return $locked->refresh()->load('offerings.priceOptions');
        });
    }
}
