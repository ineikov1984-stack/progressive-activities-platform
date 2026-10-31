<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Enums\ActivityStatus;
use App\Domain\Catalog\Models\Activity;
use App\Domain\Catalog\Services\TeamAssignments;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Tenancy\TenantContext;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final readonly class UpdateActivityDraft
{
    public function __construct(
        private TenantContext $context,
        private SyncPriceOptions $prices,
        private SyncPaymentTermsChoices $paymentTerms,
        private TeamAssignments $assignments,
    ) {}

    /** @param array<string, mixed> $payload */
    public function handle(User $actor, Activity $activity, int $expectedVersion, array $payload): Activity
    {
        abort_unless($activity->organization_id === $this->context->organizationId(), 404);
        $organization = Organization::query()->findOrFail($activity->organization_id);
        Gate::forUser($actor)->authorize('catalog.manage', $organization);

        return DB::transaction(function () use ($actor, $activity, $expectedVersion, $payload): Activity {
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
                // The lead of an offering teaches all its groups: changing it is an assignment (docs/product/team-rights.md, т. 2.2).
                // In lower case: PostgreSQL finds a profile by its UUID in any case.
                $lead = $payload['offering']['lead_instructor_profile_id'] ?? null;
                $lead = is_string($lead) ? Str::lower($lead) : null;
                $current = $offering->lead_instructor_profile_id === null ? null : Str::lower((string) $offering->lead_instructor_profile_id);
                if (array_key_exists('lead_instructor_profile_id', $payload['offering']) && $lead !== $current) {
                    $this->assignments->assertMayAssign($actor, $lead === null ? [] : [$lead]);
                }
                $offering->fill(Arr::only($payload['offering'], [
                    'location_id', 'lead_instructor_profile_id', 'title', 'description', 'terms',
                    'cancellation_policy', 'contact_email', 'enrollment_instructions',
                ]));
                $offering->version++;
                $offering->save();
                if (array_key_exists('price_options', $payload['offering'])) {
                    $this->prices->handle($offering, null, $payload['offering']['price_options']);
                }
                if (array_key_exists('payment_terms', $payload['offering'])) {
                    $this->paymentTerms->handle($offering, (array) $payload['offering']['payment_terms']);
                }
            }

            return $locked->refresh()->load('offerings.priceOptions', 'offerings.paymentTermsChoices');
        });
    }
}
