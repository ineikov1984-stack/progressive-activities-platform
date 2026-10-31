<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\Activity;
use App\Domain\Catalog\Models\InstructorProfile;
use App\Domain\Catalog\Models\OpenDayCampaign;
use App\Domain\Catalog\Models\OrganizationCatalogProfile;
use App\Domain\Organizations\Tenancy\TenantContext;

/**
 * Where a record of the selected organization still shows a photo (docs/product/teacher-content.md, т. 5.6): the cover
 * or the gallery of any activity (archived ones too), the logo, a teacher's photo, a campaign. Only these names leave
 * this class, never the path.
 */
final readonly class CatalogMediaUsage
{
    public function __construct(private TenantContext $context) {}

    /** @return list<'cover'|'logo'|'profile'|'campaign'|'gallery'> always in this order */
    public function placesOf(string $path): array
    {
        // Without an organization the tenant scope finds nothing, and the photo would look unused: stop instead.
        $this->context->organizationId();

        $places = [];
        if (Activity::query()->where('hero_image_path', $path)->exists()) {
            $places[] = 'cover';
        }
        if (OrganizationCatalogProfile::query()->where('logo_path', $path)->exists()) {
            $places[] = 'logo';
        }
        if (InstructorProfile::query()->where('photo_path', $path)->exists()) {
            $places[] = 'profile';
        }
        if (OpenDayCampaign::query()->where('image_path', $path)->exists()) {
            $places[] = 'campaign';
        }
        if (Activity::query()->whereNotNull('gallery')->get(['id', 'gallery'])
            ->contains(fn (Activity $activity): bool => self::inGallery($activity->getAttribute('gallery'), $path))) {
            $places[] = 'gallery';
        }

        return $places;
    }

    public static function inGallery(mixed $gallery, string $path): bool
    {
        return is_array($gallery) && collect($gallery)
            ->contains(fn (mixed $image): bool => is_array($image) && ($image['path'] ?? null) === $path);
    }
}
