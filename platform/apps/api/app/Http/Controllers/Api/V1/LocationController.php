<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\Enums\LocationType;
use App\Domain\Catalog\Enums\SpaceUsage;
use App\Domain\Catalog\Models\Location;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final class LocationController extends Controller
{
    public function index(Request $request, TenantContext $context): JsonResponse
    {
        Gate::forUser($request->user())->authorize('catalog.view', $this->organization($context));

        return response()->json([
            'locations' => Location::query()->orderBy('name')->get()->map($this->present(...))->all(),
        ]);
    }

    public function store(Request $request, TenantContext $context): JsonResponse
    {
        Gate::forUser($request->user())->authorize('catalog.manage', $this->organization($context));
        $validated = $this->validated($request);
        $this->requirePhysicalAddress($validated);
        $location = Location::query()->create($validated);
        $location->refresh();

        return response()->json(['location' => $this->present($location)], Response::HTTP_CREATED);
    }

    public function update(Request $request, TenantContext $context, string $location): JsonResponse
    {
        Gate::forUser($request->user())->authorize('catalog.manage', $this->organization($context));
        $record = Location::query()->findOrFail($location);
        $validated = $this->validated($request, $record);
        $this->requirePhysicalAddress($validated, $record);
        $record->fill($validated);
        $record->version++;
        $record->save();

        return response()->json(['location' => $this->present($record)]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Location $location = null): array
    {
        $required = $location === null ? 'required' : 'sometimes';

        return $request->validate([
            'name' => [$required, 'string', 'max:255'],
            'type' => [$required, Rule::enum(LocationType::class)],
            'space_usage' => ['sometimes', Rule::enum(SpaceUsage::class)],
            'address' => ['sometimes', 'nullable', 'string'],
            'latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'map_url' => ['sometimes', 'nullable', 'url:http,https', 'max:255'],
            'public_directions' => ['sometimes', 'nullable', 'string'],
            'is_public' => ['sometimes', 'boolean'],
        ]);
    }

    /** @param array<string, mixed> $validated */
    private function requirePhysicalAddress(array $validated, ?Location $location = null): void
    {
        $type = $validated['type'] ?? $location?->type?->value;
        $address = array_key_exists('address', $validated) ? $validated['address'] : $location?->address;

        if ($type === LocationType::Physical->value && (! is_string($address) || trim($address) === '')) {
            throw ValidationException::withMessages(['address' => 'Въведете адрес за физическо място.']);
        }
    }

    private function organization(TenantContext $context): Organization
    {
        return Organization::query()->findOrFail($context->organizationId());
    }

    /** @return array<string, mixed> */
    private function present(Location $location): array
    {
        return [
            'id' => $location->id,
            'name' => $location->name,
            'type' => $location->type->value,
            'space_usage' => $location->space_usage->value,
            'address' => $location->address,
            'latitude' => $location->latitude,
            'longitude' => $location->longitude,
            'map_url' => $location->map_url,
            'public_directions' => $location->public_directions,
            'is_public' => $location->is_public,
            'version' => $location->version,
        ];
    }
}
