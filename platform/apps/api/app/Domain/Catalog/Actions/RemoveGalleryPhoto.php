<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\ValueObjects\AuditableReference;
use App\Domain\Catalog\Enums\ActivityStatus;
use App\Domain\Catalog\Models\Activity;
use App\Domain\Catalog\Services\CatalogMediaUsage;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * "Махни от страницата" (docs/product/teacher-content.md, т. 5.6): the office takes a photo out of the gallery of a
 * published activity at once — at a parent's request or when it went up by mistake. Only the gallery and the version
 * change; title, status, prices and groups are not touched. The file goes when nothing else of the organization shows
 * it. The audit has identifiers and the version only, never the path. In PR 1b this goes through ApplyContentChange
 * and gets a row in the history.
 */
final readonly class RemoveGalleryPhoto
{
    public function __construct(
        private TenantContext $context,
        private CatalogMediaStore $media,
        private CatalogMediaUsage $usage,
        private AuditRecorder $audit,
    ) {}

    public function handle(User $actor, Activity $activity, int $expectedVersion, string $path): Activity
    {
        abort_unless($this->context->hasOrganization() && $activity->organization_id === $this->context->organizationId(), 404);
        Gate::forUser($actor)->authorize('catalog.manage', Organization::query()->findOrFail($activity->organization_id));

        return DB::transaction(function () use ($activity, $expectedVersion, $path): Activity {
            $locked = Activity::query()->whereKey($activity->getKey())->lockForUpdate()->firstOrFail();
            abort_unless($locked->version === $expectedVersion, 409, 'Дейността е променена междувременно. Заредете страницата отново.');
            abort_if($locked->status === ActivityStatus::Archived, 409, 'Дейността е в архива.');
            abort_if($locked->status === ActivityStatus::Draft, 409, 'Черновата се променя в редактора.');

            $gallery = $locked->getAttribute('gallery');
            $gallery = is_array($gallery) ? array_values($gallery) : [];
            $kept = array_values(array_filter($gallery, fn (mixed $image): bool => ! CatalogMediaUsage::inGallery([$image], $path)));
            if (count($kept) === count($gallery)) {
                throw ValidationException::withMessages(['path' => 'Снимката вече не е в галерията.']);
            }

            $locked->forceFill(['gallery' => $kept]);
            $locked->version++;
            $locked->save();

            $this->audit->record(AuditAction::CatalogGalleryPhotoRemoved, new AuditableReference('activity', $locked->id), [
                'version' => $locked->version,
            ]);

            if (! $this->usage->inUse($path)) {
                $this->media->deleteAfterCommit($path);
            }

            return $locked->refresh();
        });
    }
}
