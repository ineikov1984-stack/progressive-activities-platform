<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\Models\OrganizationCatalogProfile;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class OrganizationCatalogProfileController extends Controller
{
    public function show(Request $request, TenantContext $context): JsonResponse
    {
        Gate::forUser($request->user())->authorize('catalog.view', $this->organization($context));
        $profile = OrganizationCatalogProfile::query()->first();

        return response()->json(['profile' => $profile ? $this->present($profile) : null]);
    }

    public function update(Request $request, TenantContext $context): JsonResponse
    {
        $organization = $this->organization($context);
        Gate::forUser($request->user())->authorize('catalog.manage', $organization);
        $profile = OrganizationCatalogProfile::query()->first();

        $validated = $request->validate([
            'public_name' => ['sometimes', 'required', 'string', 'max:255'],
            'intro' => ['sometimes', 'nullable', 'string'],
            'logo_path' => ['sometimes', 'nullable', 'string', 'max:255'],
            'accent_color' => ['sometimes', 'nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'is_published' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('is_published', $validated)) {
            Gate::forUser($request->user())->authorize('catalog.publish', $organization);
        }

        $publicName = $validated['public_name'] ?? $profile?->public_name ?? '';
        if (($validated['is_published'] ?? false) && trim($publicName) === '') {
            throw ValidationException::withMessages(['public_name' => 'Въведете публично име преди публикуване.']);
        }

        if ($profile === null) {
            $profile = OrganizationCatalogProfile::query()->create([
                ...$validated,
                'public_name' => $publicName,
            ]);
            $profile->refresh();
        } else {
            $profile->fill($validated);
            $profile->version++;
            $profile->save();
        }

        return response()->json(['profile' => $this->present($profile)]);
    }

    private function organization(TenantContext $context): Organization
    {
        return Organization::query()->findOrFail($context->organizationId());
    }

    /** @return array<string, mixed> */
    private function present(OrganizationCatalogProfile $profile): array
    {
        return [
            'id' => $profile->id,
            'slug' => $profile->slug,
            'public_name' => $profile->public_name,
            'intro' => $profile->intro,
            'logo_path' => $profile->logo_path,
            'accent_color' => $profile->accent_color,
            'is_published' => $profile->is_published,
            'version' => $profile->version,
        ];
    }
}
