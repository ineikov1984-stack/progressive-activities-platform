<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Organizations\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final readonly class CatalogMediaStore
{
    public function __construct(private TenantContext $context) {}

    /** @return array{path: string, mime: string, activity_id: ?string} */
    public function store(UploadedFile $file, ?string $activityId): array
    {
        $mime = $file->getMimeType();
        $extension = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => null,
        };

        $imageInfo = @getimagesize($file->getRealPath());
        abort_unless($extension !== null && $imageInfo !== false && $imageInfo['mime'] === $mime, 422, 'Invalid image.');

        $id = (string) Str::uuid();
        $organizationId = $this->context->organizationId();
        $directory = 'catalog/'.$organizationId;
        $filename = $id.'.'.$extension;
        $disk = Storage::disk('local');
        $stored = $disk->putFileAs($directory, $file, $filename);
        abort_unless($stored !== false, 500, 'Could not store image.');

        $metadata = [
            'organization_id' => $organizationId,
            'activity_id' => $activityId,
            'path' => 'catalog/'.$filename,
            'mime' => $mime,
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

    /** @return array{organization_id: string, activity_id: ?string, path: string, mime: string}|null */
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
}
