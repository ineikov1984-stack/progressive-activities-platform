<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\Services\TeachingScope;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * "Моите групи" (docs/product/team.md, т. 8, 9): the groups the signed-in person teaches in the selected organization,
 * for every role with a linked teacher profile — an owner who teaches too. Without a profile the list is empty.
 */
final class TeachingController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(Request $request, TeachingScope $teaching): JsonResponse
    {
        $organization = Organization::query()->findOrFail($this->context->organizationId());
        Gate::forUser($request->user())->authorize('view', $organization);
        /** @var User $user */
        $user = $request->user();

        return response()->json(['groups' => $teaching->overview($user)]);
    }
}
