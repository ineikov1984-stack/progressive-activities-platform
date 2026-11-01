<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\Actions\DeclineSubstituteRequest;
use App\Domain\Catalog\Services\OfficeSubstituteRequests;
use App\Domain\Catalog\Services\SubstituteCandidates;
use App\Domain\Identity\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OfficeSubstituteRequestController extends Controller
{
    public function index(Request $request, OfficeSubstituteRequests $reader): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        return response()->json($reader->listing($actor));
    }

    public function show(Request $request, string $substituteRequest, OfficeSubstituteRequests $reader): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        return response()->json($reader->detail($actor, strtolower($substituteRequest)));
    }

    public function decline(Request $request, string $substituteRequest, OfficeSubstituteRequests $reader, DeclineSubstituteRequest $action): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $reader->authorize($actor);
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']], ['version.*' => 'Заредете отново заявката.']);
        $record = $action->execute($actor, strtolower($substituteRequest), (int) $data['version']);

        return response()->json($reader->detail($actor, $record->id));
    }

    public function candidates(Request $request, SubstituteCandidates $candidates): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $candidates->authorize($actor);
        $data = $request->validate([
            'sessions' => ['required', 'array', 'min:1', 'max:50'],
            'sessions.*' => ['required', 'uuid', 'distinct:ignore_case'],
            'replaces' => ['nullable', 'uuid'],
        ], ['sessions.*' => 'Изберете от 1 до 50 занимания.', 'replaces.*' => 'Изберете преподавател.']);

        return response()->json($candidates->read($actor, array_map(strtolower(...), $data['sessions']), isset($data['replaces']) ? strtolower($data['replaces']) : null));
    }
}
