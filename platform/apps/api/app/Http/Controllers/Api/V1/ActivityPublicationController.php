<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\Actions\PublishActivityInternally;
use App\Domain\Catalog\Models\Activity;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ActivityPublicationController extends Controller
{
    public function store(Request $request, string $activity, PublishActivityInternally $action): JsonResponse
    {
        $validated = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $published = $action->handle($request->user(), Activity::query()->findOrFail($activity), $validated['version']);

        return response()->json(['activity' => [
            'id' => $published->id,
            'status' => $published->status->value,
            'version' => $published->version,
            'published_at' => $published->published_at?->toIso8601String(),
        ]]);
    }
}
