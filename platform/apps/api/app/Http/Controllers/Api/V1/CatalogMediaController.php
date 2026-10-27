<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\Actions\CatalogMediaStore;
use App\Domain\Catalog\Models\Activity;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

final class CatalogMediaController extends Controller
{
    public function store(Request $request, TenantContext $context, CatalogMediaStore $media): JsonResponse
    {
        Gate::forUser($request->user())->authorize('catalog.manage', $this->organization($context));
        $validated = $request->validate([
            'file' => ['required', 'file', 'max:5120', 'mimes:jpg,jpeg,png,webp'],
            'activity_id' => ['sometimes', 'nullable', 'uuid'],
        ]);
        $activityId = $validated['activity_id'] ?? null;
        if ($activityId !== null) {
            Activity::query()->findOrFail($activityId);
        }

        return response()->json(['media' => $media->store($validated['file'], $activityId)], Response::HTTP_CREATED);
    }

    public function show(Request $request, TenantContext $context, CatalogMediaStore $media, string $filename): Response
    {
        Gate::forUser($request->user())->authorize('catalog.view', $this->organization($context));
        $path = 'catalog/'.$filename;
        $metadata = $media->metadata($path);
        abort_if($metadata === null, 404);

        return Storage::disk('local')->response($media->storagePath($path), null, [
            'Content-Type' => $metadata['mime'],
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function organization(TenantContext $context): Organization
    {
        return Organization::query()->findOrFail($context->organizationId());
    }
}
