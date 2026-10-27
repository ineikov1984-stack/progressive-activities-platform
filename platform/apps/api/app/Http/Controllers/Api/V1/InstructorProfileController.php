<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\Models\InstructorProfile;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

final class InstructorProfileController extends Controller
{
    public function index(Request $request, TenantContext $context): JsonResponse
    {
        Gate::forUser($request->user())->authorize('catalog.view', $this->organization($context));

        return response()->json([
            'instructors' => InstructorProfile::query()->orderBy('public_name')->get()->map($this->present(...))->all(),
        ]);
    }

    public function store(Request $request, TenantContext $context): JsonResponse
    {
        Gate::forUser($request->user())->authorize('catalog.manage', $this->organization($context));
        $instructor = InstructorProfile::query()->create($this->validated($request));
        $instructor->refresh();

        return response()->json(['instructor' => $this->present($instructor)], Response::HTTP_CREATED);
    }

    public function update(Request $request, TenantContext $context, string $instructor): JsonResponse
    {
        Gate::forUser($request->user())->authorize('catalog.manage', $this->organization($context));
        $record = InstructorProfile::query()->findOrFail($instructor);
        $record->fill($this->validated($request, false));
        $record->version++;
        $record->save();

        return response()->json(['instructor' => $this->present($record)]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating = true): array
    {
        return $request->validate([
            'public_name' => [$creating ? 'required' : 'sometimes', 'required', 'string', 'max:255'],
            'role_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'bio' => ['sometimes', 'nullable', 'string'],
            'qualifications' => ['sometimes', 'nullable', 'array'],
            'qualifications.*' => ['string', 'max:255'],
            'photo_path' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_public' => ['sometimes', 'boolean'],
        ]);
    }

    private function organization(TenantContext $context): Organization
    {
        return Organization::query()->findOrFail($context->organizationId());
    }

    /** @return array<string, mixed> */
    private function present(InstructorProfile $instructor): array
    {
        return [
            'id' => $instructor->id,
            'public_name' => $instructor->public_name,
            'role_title' => $instructor->role_title,
            'bio' => $instructor->bio,
            'qualifications' => $instructor->qualifications,
            'photo_path' => $instructor->photo_path,
            'is_public' => $instructor->is_public,
            'version' => $instructor->version,
        ];
    }
}
