<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Services\ImageRejected;
use App\Domain\Catalog\Services\ImageSanitizer;
use App\Domain\Organizations\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final readonly class CatalogMediaStore
{
    public function __construct(private TenantContext $context, private ImageSanitizer $sanitizer) {}

    /**
     * Every photo of the catalog — cover, gallery, teacher, logo, campaign — is stored only as the cleaned copy, without
     * the phone's data (docs/product/teacher-content.md, т. 5.2). Without the tools nothing is stored (503).
     *
     * @return array{path: string, mime: string, activity_id: ?string}
     */
    public function store(UploadedFile $file, ?string $activityId): array
    {
        // The kind comes from the content alone: never from the file's name or from what the browser says it is.
        $source = $file->getRealPath();
        $imageInfo = is_string($source) ? @getimagesize($source) : false;
        if (! is_string($source) || $imageInfo === false) {
            throw self::notAPhoto();
        }
        $mime = $imageInfo['mime'];
        $extension = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => null,
        };
        if ($extension === null) {
            throw self::notAPhoto();
        }
        $clean = $this->clean($source, $mime);

        $id = (string) Str::uuid();
        $organizationId = $this->context->organizationId();
        $directory = 'catalog/'.$organizationId;
        $filename = $id.'.'.$extension;
        $disk = Storage::disk('local');
        $stored = $directory.'/'.$filename;
        abort_unless($disk->put($stored, $clean['contents']), 500, 'Could not store image.');

        $metadata = [
            'organization_id' => $organizationId,
            'activity_id' => $activityId,
            'path' => 'catalog/'.$filename,
            'mime' => $mime,
            'sanitized' => true,
        ];

        if (! $disk->put($directory.'/'.$id.'.json', json_encode($metadata, JSON_THROW_ON_ERROR))) {
            $disk->delete($stored);
            abort(500, 'Could not store image metadata.');
        }

        return [
            'path' => $metadata['path'],
            'mime' => $mime,
            'activity_id' => $activityId,
        ];
    }

    /** @return array{organization_id: string, activity_id: ?string, path: string, mime: string, sanitized: bool}|null */
    public function metadata(string $path): ?array
    {
        if (! preg_match('/^catalog\/([0-9a-f-]{36})\.(jpg|png|webp)$/D', $path, $matches)) {
            return null;
        }

        $directory = 'catalog/'.$this->context->organizationId();
        $metadataPath = $directory.'/'.$matches[1].'.json';
        $disk = Storage::disk('local');
        if (! $disk->exists($metadataPath) || ! $disk->exists($directory.'/'.basename($path))) {
            return null;
        }

        $metadata = json_decode($disk->get($metadataPath), true);
        if (! is_array($metadata)
            || ($metadata['organization_id'] ?? null) !== $this->context->organizationId()
            || ($metadata['path'] ?? null) !== $path
            || ! in_array($metadata['mime'] ?? null, ['image/jpeg', 'image/png', 'image/webp'], true)
            || ! array_key_exists('activity_id', $metadata)
            || ($metadata['activity_id'] !== null && ! is_string($metadata['activity_id']))) {
            return null;
        }

        return [
            'organization_id' => $metadata['organization_id'],
            'activity_id' => $metadata['activity_id'],
            'path' => $metadata['path'],
            'mime' => $metadata['mime'],
            'sanitized' => ($metadata['sanitized'] ?? false) === true,
        ];
    }

    public function isAttachable(string $path, ?string $activityId = null): bool
    {
        $metadata = $this->metadata($path);

        return $metadata !== null
            && ($metadata['activity_id'] === null || $metadata['activity_id'] === $activityId);
    }

    public function storagePath(string $path): string
    {
        return 'catalog/'.$this->context->organizationId().'/'.basename($path);
    }

    /**
     * Removes the photo of this organization and its record once the change that stopped showing it is saved: a change
     * that is rolled back keeps its file.
     */
    public function deleteAfterCommit(string $path): void
    {
        if (! preg_match('/^catalog\/([0-9a-f-]{36})\.(jpg|png|webp)$/D', $path, $matches)) {
            return;
        }
        $directory = 'catalog/'.$this->context->organizationId();
        $files = [$directory.'/'.basename($path), $directory.'/'.$matches[1].'.json'];

        DB::afterCommit(fn () => Storage::disk('local')->delete($files));
    }

    /** @return list<string> the organizations with a folder of photos — identifiers from the disk, nothing else */
    public function organizations(): array
    {
        $organizations = array_map(basename(...), Storage::disk('local')->directories('catalog'));
        $organizations = array_values(array_filter($organizations, fn (string $id): bool => Str::isUuid($id)));
        sort($organizations);

        return $organizations;
    }

    /** @return list<string> the photos of this organization, by the identifier of their record */
    public function storedIds(): array
    {
        $ids = [];
        foreach (Storage::disk('local')->files('catalog/'.$this->context->organizationId()) as $file) {
            if (preg_match('/^([0-9a-f-]{36})\.json$/D', basename($file), $matches) === 1) {
                $ids[] = $matches[1];
            }
        }
        sort($ids);

        return $ids;
    }

    /**
     * Cleans a photo stored before the sanitizer (`catalog:sanitize-media`). False when it is already clean.
     *
     * @throws ImageRejected when it cannot be read: it is left as it is, for work by hand
     */
    public function sanitizeStored(string $id): bool
    {
        $disk = Storage::disk('local');
        $record = 'catalog/'.$this->context->organizationId().'/'.$id.'.json';
        $data = json_decode((string) $disk->get($record), true);
        $path = is_array($data) && is_string($data['path'] ?? null) ? $data['path'] : '';
        $metadata = $this->metadata($path);
        if (! is_array($data) || $metadata === null || pathinfo($path, PATHINFO_FILENAME) !== $id) {
            throw ImageRejected::unreadable();
        }
        if ($metadata['sanitized']) {
            return false;
        }

        $target = $this->storagePath($path);
        $clean = $this->sanitizer->sanitize($disk->path($target), $metadata['mime']);
        $this->replace($target, $clean['contents']);
        $this->replace($record, json_encode([...$data, 'sanitized' => true], JSON_THROW_ON_ERROR));

        return true;
    }

    private static function notAPhoto(): ValidationException
    {
        return ValidationException::withMessages(['file' => 'Снимката трябва да е JPG, PNG или WebP до 5 MB.']);
    }

    /** @return array{contents: string, mime: string, width: int, height: int} */
    private function clean(string $source, string $mime): array
    {
        try {
            return $this->sanitizer->sanitize($source, $mime);
        } catch (ImageRejected $rejected) {
            abort_if($rejected->toolsAreMissing(), 503, $rejected->getMessage());

            throw ValidationException::withMessages(['file' => $rejected->getMessage()]);
        }
    }

    /** A temporary file in the same folder, then rename(): a stored photo is never half written. */
    private function replace(string $relative, string $contents): void
    {
        $disk = Storage::disk('local');
        $temporary = $relative.'.'.Str::random(16).'.tmp';
        try {
            if (! $disk->put($temporary, $contents) || ! rename($disk->path($temporary), $disk->path($relative))) {
                throw new RuntimeException('Could not replace a catalog file.');
            }
        } finally {
            if ($disk->exists($temporary)) {
                $disk->delete($temporary);
            }
        }
    }
}
