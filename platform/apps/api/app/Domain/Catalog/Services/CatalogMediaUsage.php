<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\Activity;
use App\Domain\Catalog\Models\InstructorProfile;
use App\Domain\Catalog\Models\OpenDayCampaign;
use App\Domain\Catalog\Models\OrganizationCatalogProfile;

/**
 * Whether a record of the selected organization still shows a photo (docs/product/teacher-content.md, т. 5.6): the
 * cover or the gallery of any activity (archived ones too), a teacher's photo, the logo, a campaign.
 */
final readonly class CatalogMediaUsage
{
    public function inUse(string $path): bool
    {
        if (Activity::query()->where('hero_image_path', $path)->exists()
            || InstructorProfile::query()->where('photo_path', $path)->exists()
            || OrganizationCatalogProfile::query()->where('logo_path', $path)->exists()
            || OpenDayCampaign::query()->where('image_path', $path)->exists()) {
            return true;
        }

        return Activity::query()->whereNotNull('gallery')->get(['id', 'gallery'])
            ->contains(fn (Activity $activity): bool => self::inGallery($activity->getAttribute('gallery'), $path));
    }

    public static function inGallery(mixed $gallery, string $path): bool
    {
        return is_array($gallery) && collect($gallery)
            ->contains(fn (mixed $image): bool => is_array($image) && ($image['path'] ?? null) === $path);
    }
}
