<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Models\Activity;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Tenancy\TenantContext;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final readonly class CreateActivityDraft
{
    private const MAX_SLUG_LENGTH = 255;

    public function __construct(private TenantContext $context, private SyncPriceOptions $prices) {}

    /** @param array<string, mixed> $payload */
    public function handle(User $actor, Organization $organization, array $payload): Activity
    {
        abort_unless($this->context->organizationId() === $organization->getKey(), 404);
        Gate::forUser($actor)->authorize('catalog.manage', $organization);

        return DB::transaction(function () use ($organization, $payload): Activity {
            Organization::query()->whereKey($organization->getKey())->lockForUpdate()->firstOrFail();

            $base = Str::slug($payload['title'], '-', 'bg') ?: Str::lower(Str::random(12));
            $base = rtrim(Str::substr($base, 0, self::MAX_SLUG_LENGTH), '-');
            $slug = $base;
            $suffix = 2;
            while (Activity::query()->where('slug', $slug)->exists()) {
                $ending = '-'.$suffix++;
                $slug = rtrim(Str::substr($base, 0, self::MAX_SLUG_LENGTH - strlen($ending)), '-').$ending;
            }

            $activity = Activity::query()->create([
                ...Arr::only($payload, ['title', 'category', 'summary', 'age_label', 'hero_image_path']),
                ...Arr::only($payload['sections'] ?? [], [
                    'about', 'benefits', 'methodology', 'participation_terms', 'faq', 'gallery', 'instructor_intro',
                ]),
                'slug' => $slug,
            ]);

            $offering = $activity->offerings()->create(Arr::only($payload['offering'] ?? [], [
                'location_id', 'lead_instructor_profile_id', 'title', 'description', 'terms',
                'cancellation_policy', 'contact_email', 'enrollment_instructions',
            ]));
            if (array_key_exists('price_options', $payload['offering'] ?? [])) {
                $this->prices->handle($offering, null, $payload['offering']['price_options']);
            }

            return $activity->refresh()->load('offerings.priceOptions');
        });
    }
}
