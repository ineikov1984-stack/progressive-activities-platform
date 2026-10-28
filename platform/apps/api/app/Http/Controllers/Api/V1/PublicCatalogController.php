<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\Actions\CatalogMediaStore;
use App\Domain\Catalog\Enums\ActivityStatus;
use App\Domain\Catalog\Enums\LocationType;
use App\Domain\Catalog\Enums\VisitOfferStatus;
use App\Domain\Catalog\Models\Activity;
use App\Domain\Catalog\Models\Group;
use App\Domain\Catalog\Models\InstructorProfile;
use App\Domain\Catalog\Models\Location;
use App\Domain\Catalog\Models\Offering;
use App\Domain\Catalog\Models\OpenDayCampaign;
use App\Domain\Catalog\Models\OrganizationCatalogProfile;
use App\Domain\Catalog\Models\PriceOption;
use App\Domain\Catalog\Models\Session;
use App\Domain\Catalog\Services\PublicAvailabilityPresenter;
use App\Domain\Organizations\Enums\OrganizationStatus;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

final class PublicCatalogController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly PublicAvailabilityPresenter $availabilityPresenter,
    ) {}

    public function catalog(string $organizationSlug): JsonResponse
    {
        return DB::transaction(function () use ($organizationSlug): JsonResponse {
            try {
                $profile = $this->resolvePublishedProfile($organizationSlug);
                $activities = Activity::query()->where('status', ActivityStatus::Published)
                    ->with($this->publicRelations())->orderByDesc('published_at')->get();

                return response()->json([
                    'organization' => $this->presentProfile($profile),
                    'activities' => $activities->map(fn (Activity $activity) => $this->presentCard($activity, $profile->slug))->all(),
                ]);
            } finally {
                $this->context->clear();
            }
        });
    }

    public function activity(string $organizationSlug, string $activitySlug): JsonResponse
    {
        return DB::transaction(function () use ($organizationSlug, $activitySlug): JsonResponse {
            try {
                $profile = $this->resolvePublishedProfile($organizationSlug);
                $activity = Activity::query()->where('status', ActivityStatus::Published)
                    ->where('slug', $activitySlug)->with($this->publicRelations())->firstOrFail();

                return response()->json([
                    'organization' => $this->presentProfile($profile),
                    'activity' => $this->presentActivity($activity, $profile->slug),
                ]);
            } finally {
                $this->context->clear();
            }
        });
    }

    public function openDay(string $organizationSlug, string $campaignSlug): JsonResponse
    {
        return DB::transaction(function () use ($organizationSlug, $campaignSlug): JsonResponse {
            try {
                $profile = $this->resolvePublishedProfile($organizationSlug);
                $campaign = OpenDayCampaign::query()->where('slug', $campaignSlug)
                    ->where('status', VisitOfferStatus::Published)
                    ->with(['visitOffers' => fn ($query) => $query->where('status', VisitOfferStatus::Published)
                        ->with(['activity', 'group.offering', 'sessions.group', 'campaign'])])->firstOrFail();

                $days = [];
                $at = CarbonImmutable::now();
                foreach ($campaign->visitOffers as $offer) {
                    foreach ($this->availabilityPresenter->rows(collect([$offer]), $at) as $row) {
                        $activity = $offer->activity;
                        $date = CarbonImmutable::parse($row['starts_at'])->setTimezone($row['timezone'])->toDateString();
                        $key = $activity->slug;
                        $days[$date][$key]['activity'] = [
                            'slug' => $activity->slug,
                            'title' => $activity->title,
                            'category' => $activity->category,
                            'summary' => $activity->summary,
                            'hero_image_url' => $this->mediaUrl($activity->hero_image_path, $profile->slug),
                        ];
                        $days[$date][$key]['visit_offers'][] = $row;
                    }
                }
                abort_if($days === [], 404);
                ksort($days);

                return response()->json([
                    'organization' => $this->presentProfile($profile),
                    'campaign' => [
                        'slug' => $campaign->slug,
                        'title' => $campaign->title,
                        'description' => $campaign->description,
                        'starts_on' => $campaign->starts_on?->toDateString(),
                        'ends_on' => $campaign->ends_on?->toDateString(),
                        'image_url' => $this->mediaUrl($campaign->image_path, $profile->slug),
                        'days' => collect($days)->map(fn (array $activities, string $date) => [
                            'date' => $date,
                            'activities' => array_values($activities),
                        ])->values()->all(),
                    ],
                ]);
            } finally {
                $this->context->clear();
            }
        });
    }

    public function media(string $organizationSlug, string $filename, CatalogMediaStore $media): Response
    {
        return DB::transaction(function () use ($organizationSlug, $filename, $media): Response {
            try {
                $this->resolvePublishedProfile($organizationSlug);
                $path = 'catalog/'.$filename;
                $metadata = $media->metadata($path);
                abort_if($metadata === null || ! $this->isPublishedMedia($path), 404);

                return Storage::disk('local')->response($media->storagePath($path), null, [
                    'Content-Type' => $metadata['mime'], 'Cache-Control' => 'public, max-age=3600',
                    'X-Content-Type-Options' => 'nosniff',
                ]);
            } finally {
                $this->context->clear();
            }
        });
    }

    private function resolvePublishedProfile(string $slug): OrganizationCatalogProfile
    {
        $this->context->clear();
        if (DB::getDriverName() === 'pgsql') {
            DB::select("SELECT set_config('app.current_organization_id', '', true)");
        }
        $profile = OrganizationCatalogProfile::query()->withoutGlobalScopes()
            ->where('slug', $slug)->where('is_published', true)->firstOrFail();
        $this->context->select($profile->organization_id);
        if (DB::getDriverName() === 'pgsql') {
            DB::select("SELECT set_config('app.current_organization_id', ?, true)", [$profile->organization_id]);
        }
        $organization = Organization::query()->find($profile->organization_id);
        if ($organization === null || $organization->status !== OrganizationStatus::Active) {
            $this->context->clear();
            abort(404);
        }

        return $profile;
    }

    /** @return array<string, callable> */
    private function publicRelations(): array
    {
        return [
            'visitOffers' => fn ($query) => $query->where('status', VisitOfferStatus::Published)
                ->with(['activity', 'group.offering', 'sessions.group', 'campaign']),
            'offerings' => fn ($query) => $query->with([
                'location' => fn ($location) => $location->where('is_public', true),
                'leadInstructor' => fn ($instructor) => $instructor->where('is_public', true),
                'priceOptions' => fn ($price) => $price->whereNull('group_id')->where('is_available', true),
                'groups' => fn ($group) => $group->where('status', 'published')->with([
                    'location' => fn ($location) => $location->where('is_public', true),
                    'instructors' => fn ($instructor) => $instructor->where('is_public', true),
                    'priceOptions' => fn ($price) => $price->where('is_available', true),
                    'sessions' => fn ($session) => $session->where('status', 'scheduled')
                        ->where('starts_at', '>', now())->orderBy('starts_at'),
                ]),
            ]),
        ];
    }

    /** @return array<string, mixed> */
    private function presentProfile(OrganizationCatalogProfile $profile): array
    {
        return [
            'slug' => $profile->slug,
            'public_name' => $profile->public_name,
            'intro' => $profile->intro,
            'accent_color' => $profile->accent_color,
            'logo_url' => $this->mediaUrl($profile->logo_path, $profile->slug),
        ];
    }

    /** @return array<string, mixed> */
    private function presentCard(Activity $activity, string $organizationSlug): array
    {
        $groups = $activity->offerings->flatMap->groups;
        /** @var PriceOption|null $price */
        $price = $groups->flatMap->priceOptions
            ->concat($activity->offerings->flatMap->priceOptions)
            ->sortBy('amount_cents')->first();
        $rows = $this->availabilityPresenter->rows($activity->visitOffers, CarbonImmutable::now());
        $availability = $this->availabilityPresenter->cardAvailability($rows)
            ?? $this->availabilityPresenter->groupAvailability($groups, CarbonImmutable::now());
        $offerPrice = collect($rows)->sortBy('amount_cents')->first();
        $offerPriceVaries = collect($rows)->map(fn (array $row): string => implode(':', [
            $row['fee_type'], $row['amount_cents'], $row['currency'],
        ]))->unique()->count() > 1;
        $audienceRow = collect($rows)->first(fn (array $row): bool => $row['audience'] !== null);
        $campaignRow = collect($rows)->first(fn (array $row): bool => $row['type'] === 'open_day'
            && $row['campaign_slug'] !== null);

        return [
            'slug' => $activity->slug,
            'title' => $activity->title,
            'category' => $activity->category,
            'summary' => $activity->summary,
            'age_label' => $activity->age_label,
            'hero_image_url' => $this->mediaUrl($activity->hero_image_path, $organizationSlug),
            'price' => $offerPrice ? $this->presentVisitPrice($offerPrice, $offerPriceVaries || $offerPrice['fee_type'] === 'from_price')
                : ($price ? $this->presentPrice($price) : null),
            'audience' => $audienceRow['audience'] ?? $activity->age_label,
            'availability' => $availability,
            'special_offer' => $this->availabilityPresenter->specialOffer($rows),
            'special_offer_url' => $campaignRow === null ? null
                : '/o/'.rawurlencode($organizationSlug).'/open-days/'
                    .rawurlencode($campaignRow['campaign_slug']),
            'schedule' => $groups->first()?->schedule_pattern,
        ];
    }

    /** @return array<string, mixed> */
    private function presentActivity(Activity $activity, string $organizationSlug): array
    {
        $groups = $activity->offerings->flatMap->groups;
        $instructors = $activity->offerings->pluck('leadInstructor')->filter()
            ->concat($groups->flatMap->instructors)->unique('id')->values();

        return [
            ...$this->presentCard($activity, $organizationSlug),
            'sections' => array_filter([
                'about' => $activity->about,
                'benefits' => $activity->benefits,
                'methodology' => $activity->methodology,
                'participation_terms' => $activity->participation_terms,
                'faq' => $activity->faq,
                'gallery' => $this->presentGallery($activity->getAttribute('gallery'), $organizationSlug),
                'instructor_intro' => $activity->instructor_intro,
            ], fn ($value) => $value !== null && $value !== [] && $value !== ''),
            'groups' => $activity->offerings->flatMap(fn (Offering $offering) => $offering->groups
                ->map(fn (Group $group) => $this->presentGroup($group, $offering)))->values()->all(),
            'terms' => $activity->offerings->map(fn ($offering) => array_filter([
                'title' => $offering->title,
                'terms' => $offering->terms,
                'cancellation_policy' => $offering->cancellation_policy,
            ], fn ($value) => $value !== null && $value !== ''))->filter()->values()->all(),
            'instructors' => $instructors->map(fn (InstructorProfile $instructor) => [
                'public_name' => $instructor->public_name,
                'role_title' => $instructor->role_title,
                'bio' => $instructor->bio,
                'qualifications' => $instructor->qualifications,
                'photo_url' => $this->mediaUrl($instructor->photo_path, $organizationSlug),
            ])->all(),
            'visit_offers' => $this->availabilityPresenter->rows($activity->visitOffers, CarbonImmutable::now()),
        ];
    }

    /** @return array<string, mixed> */
    private function presentGroup(Group $group, Offering $publicOffering): array
    {
        /** @var Location|null $location */
        $location = $group->location ?? $publicOffering->location;

        return [
            'id' => $group->id,
            'name' => $group->name,
            'age_label' => $group->age_label,
            'capacity_min' => $group->capacity_min,
            'capacity_max' => $group->capacity_max,
            'schedule' => $group->schedule_pattern,
            'availability' => $this->availabilityPresenter->groupAvailability(collect([$group]), CarbonImmutable::now()),
            'location' => $location ? $this->presentLocation($location) : null,
            'prices' => $group->priceOptions
                ->concat($publicOffering->priceOptions)
                ->unique('id')->values()->map($this->presentPrice(...))->all(),
            'sessions' => $group->sessions->map(fn (Session $session) => [
                'id' => $session->id,
                'starts_at' => $session->starts_at->toIso8601String(),
                'ends_at' => $session->ends_at->toIso8601String(),
                'status' => $session->status,
            ])->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $offer
     * @return array<string, mixed>
     */
    private function presentVisitPrice(array $offer, bool $from): array
    {
        return [
            'unit' => 'session',
            'amount_cents' => $offer['amount_cents'],
            'currency' => $offer['currency'],
            'from' => $from,
            'label' => $from ? 'От '.number_format($offer['amount_cents'] / 100, 2, ',', '')."\u{00A0}€ / посещение"
                : ($offer['fee_type'] === 'free' ? 'Безплатно'
                    : number_format($offer['amount_cents'] / 100, 2, ',', '')."\u{00A0}€ / посещение"),
        ];
    }

    /** @return array<string, mixed> */
    private function presentPrice(PriceOption $price): array
    {
        $amount = number_format($price->amount_cents / 100, 2, ',', '');
        $unit = match ($price->unit) {
            'session' => 'занимание', 'month' => 'месец', 'period' => 'период', 'package' => 'пакет',
            default => $price->unit,
        };

        return [
            'id' => $price->id,
            'enrollment_mode' => $price->enrollment_mode,
            'unit' => $price->unit,
            'amount_cents' => $price->amount_cents,
            'currency' => 'EUR',
            'label' => $amount."\u{00A0}€ / {$unit}",
            'terms' => $price->terms,
            'cancellation_policy' => $price->cancellation_policy,
            'payment_due' => $price->payment_due,
        ];
    }

    /** @return array<string, mixed> */
    private function presentLocation(Location $location): array
    {
        $physical = $location->type === LocationType::Physical;

        return [
            'name' => $location->name,
            'type' => $location->type->value,
            'address' => $physical ? $location->address : null,
            'public_directions' => $physical ? $location->public_directions : null,
            'navigation_url' => $physical ? $location->map_url : null,
        ];
    }

    /** @return list<array{url: string, alt: string}> */
    private function presentGallery(mixed $gallery, string $organizationSlug): array
    {
        return collect(is_array($gallery) ? $gallery : [])->filter(fn ($image) => is_array($image)
            && is_string($image['path'] ?? null) && is_string($image['alt'] ?? null))->map(fn (array $image) => [
                'url' => $this->mediaUrl($image['path'], $organizationSlug), 'alt' => $image['alt'],
            ])->filter(fn (array $image) => $image['url'] !== null)->values()->all();
    }

    private function mediaUrl(?string $path, string $organizationSlug): ?string
    {
        if ($path === null || ! preg_match('/^catalog\/([0-9a-f-]{36}\.(?:jpg|png|webp))$/D', $path, $matches)) {
            return null;
        }

        return '/api/v1/public/organizations/'.$organizationSlug.'/media/'.$matches[1];
    }

    private function isPublishedMedia(string $path): bool
    {
        $profileUsesMedia = OrganizationCatalogProfile::query()->where('is_published', true)->where('logo_path', $path)->exists();
        if ($profileUsesMedia) {
            return true;
        }

        $campaignUsesMedia = OpenDayCampaign::query()->where('status', VisitOfferStatus::Published)
            ->where('image_path', $path)
            ->with(['visitOffers' => fn ($query) => $query->where('status', VisitOfferStatus::Published)
                ->with(['activity', 'group.offering', 'sessions.group', 'campaign'])])->get()
            ->contains(fn (OpenDayCampaign $campaign): bool => $this->availabilityPresenter
                ->rows($campaign->visitOffers, CarbonImmutable::now()) !== []);
        if ($campaignUsesMedia) {
            return true;
        }

        return Activity::query()->where('status', ActivityStatus::Published)->get()
            ->contains(function (Activity $activity) use ($path): bool {
                if ($activity->hero_image_path === $path) {
                    return true;
                }
                $gallery = $activity->getAttribute('gallery');
                if (is_array($gallery) && collect($gallery)->contains(fn ($image) => is_array($image) && ($image['path'] ?? null) === $path)) {
                    return true;
                }

                return InstructorProfile::query()->where('is_public', true)->where('photo_path', $path)
                    ->where(function ($query) use ($activity): void {
                        $query->whereHas('leadOfferings', fn ($offering) => $offering->where('activity_id', $activity->id))
                            ->orWhereExists(fn ($groups) => $groups->selectRaw('1')
                                ->from('activity_group_instructors')
                                ->join('activity_groups', 'activity_groups.id', '=', 'activity_group_instructors.group_id')
                                ->join('offerings', 'offerings.id', '=', 'activity_groups.offering_id')
                                ->whereColumn('activity_group_instructors.instructor_profile_id', 'instructor_profiles.id')
                                ->where('activity_groups.status', 'published')
                                ->where('offerings.activity_id', $activity->id));
                    })->exists();
            });
    }
}
