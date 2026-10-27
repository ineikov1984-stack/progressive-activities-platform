<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrganizationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return DB::transaction(function () use ($request): JsonResponse {
            $userId = (string) $request->user()->getAuthIdentifier();

            if (DB::getDriverName() === 'pgsql') {
                DB::select("SELECT set_config('app.current_user_id', ?, true)", [$userId]);
            }

            $organizationIds = OrganizationMembership::query()
                ->withoutGlobalScopes()
                ->where('user_id', $userId)
                ->where('status', 'active')
                ->pluck('organization_id');

            $organizations = Organization::query()
                ->withoutGlobalScopes()
                ->whereKey($organizationIds)
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'name', 'slug', 'status']);

            return response()->json(['organizations' => $organizations]);
        });
    }
}
