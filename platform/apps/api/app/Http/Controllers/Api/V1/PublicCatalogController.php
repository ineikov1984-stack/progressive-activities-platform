<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\Actions\CatalogMediaStore;
use App\Domain\Catalog\Enums\ActivityStatus;
use App\Domain\Catalog\Enums\LocationType;
use App\Domain\Catalog\Models\Activity;
use App\Domain\Catalog\Models\Group;
use App\Domain\Catalog\Models\InstructorProfile;
use App\Domain\Catalog\Models\Location;
use App\Domain\Catalog\Models\Offering;
use App\Domain\Catalog\Models\OrganizationCatalogProfile;
use App\Domain\Catalog\Models\PriceOption;
use App\Domain\Organizations\Enums\OrganizationStatus;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

final class PublicCatalogController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

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
            'offerings' => fn ($query) => $query->with([
                'location' => fn ($location) => $location->where('is_public', true),
                'leadInstructor' => fn ($instructor) => $instructor->where('is_public', true),
                'priceOptions' => fn ($price) => $price->whereNull('group_id')->where('is_available', true),
                'groups' => fn ($group) => $group->where('status', 'published')->with([
                    'location' => fn ($location) => $location->where('is_public', true),
                    'instructors' => fn ($instructor) => $instructor->where('is_public', true),
                    'priceOptions' => fn ($price) => $price->where('is_available', true),
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
        $availability = $this->availability($groups);

        return [
            'slug' => $activity->slug,
            'title' => $activity->title,
            'category' => $activity->category,
            'summary' => $activity->summary,
            'age_label' => $activity->age_label,
            'hero_image_url' => $this->mediaUrl($activity->hero_image_path, $organizationSlug),
            'price' => $price ? $this->presentPrice($price) : null,
            'availability' => $availability,
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
        ];
    }

    /** @return array<string, mixed> */
    private function presentGroup(Group $group, Offering $publicOffering): array
    {
        /** @var Location|null $location */
        $location = $group->location ?? $publicOffering->location;

        return [
            'name' => $group->name,
            'age_label' => $group->age_label,
            'capacity_min' => $group->capacity_min,
            'capacity_max' => $group->capacity_max,
            'schedule' => $group->schedule_pattern,
            'availability' => $this->availability(collect([$group])),
            'location' => $location ? $this->presentLocation($location) : null,
            'prices' => $group->priceOptions
                ->concat($publicOffering->priceOptions)
                ->unique('id')->values()->map($this->presentPrice(...))->all(),
        ];
    }

    /**
     * @param  Collection<int, Group>  $groups
     * @return array{status: string, remaining_places: ?int}
     */
    private function availability(Collection $groups): array
    {
        $capacities = $groups->pluck('capacity_max')->filter(fn ($capacity) => $capacity !== null);
        $remaining = $capacities->isEmpty() ? null : (int) $capacities->sum();

        return [
            'status' => $remaining !== null && $remaining <= 3 ? 'limited' : 'available',
            'remaining_places' => $remaining,
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
