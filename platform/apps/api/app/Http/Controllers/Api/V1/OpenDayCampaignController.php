<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\Actions\SaveOpenDayCampaign;
use App\Domain\Catalog\Models\OpenDayCampaign;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final class OpenDayCampaignController extends Controller
{
    public function index(Request $request, TenantContext $context): JsonResponse
    {
        Gate::forUser($request->user())->authorize('catalog.view', $this->organization($context));

        return response()->json(['campaigns' => OpenDayCampaign::query()->with('visitOffers')->latest()->get()->map($this->present(...))->all()]);
    }

    public function show(Request $request, TenantContext $context, string $campaign): JsonResponse
    {
        Gate::forUser($request->user())->authorize('catalog.view', $this->organization($context));

        return response()->json(['campaign' => $this->present(OpenDayCampaign::query()->with('visitOffers')->findOrFail($campaign))]);
    }

    public function store(Request $request, TenantContext $context, SaveOpenDayCampaign $action): JsonResponse
    {
        Gate::forUser($request->user())->authorize('catalog.manage', $this->organization($context));
        $campaign = $action->create($request->user(), $this->validated($request, $context));

        return response()->json(['campaign' => $this->present($campaign)], Response::HTTP_CREATED);
    }

    public function update(Request $request, TenantContext $context, string $campaign, SaveOpenDayCampaign $action): JsonResponse
    {
        Gate::forUser($request->user())->authorize('catalog.manage', $this->organization($context));
        $record = OpenDayCampaign::query()->findOrFail($campaign);
        $data = $this->validated($request, $context, $record);
        $updated = $action->update($request->user(), $record, $data['version'], $data);

        return response()->json(['campaign' => $this->present($updated)]);
    }

    public function publish(Request $request, string $campaign, SaveOpenDayCampaign $action): JsonResponse
    {
        return $this->transition($request, $campaign, $action, 'publish');
    }

    public function close(Request $request, string $campaign, SaveOpenDayCampaign $action): JsonResponse
    {
        return $this->transition($request, $campaign, $action, 'close');
    }

    public function cancel(Request $request, string $campaign, SaveOpenDayCampaign $action): JsonResponse
    {
        return $this->transition($request, $campaign, $action, 'cancel');
    }

    private function transition(Request $request, string $id, SaveOpenDayCampaign $action, string $transition): JsonResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $updated = $action->transition($request->user(), OpenDayCampaign::query()->findOrFail($id), $data['version'], $transition);

        return response()->json(['campaign' => $this->present($updated)]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, TenantContext $context, ?OpenDayCampaign $campaign = null): array
    {
        $tenant = $context->organizationId();

        $data = $request->validate([
            'version' => $campaign ? ['required', 'integer', 'min:1'] : ['prohibited'],
            'slug' => ['sometimes', 'string', 'max:255', 'alpha_dash'],
            'title' => [$campaign ? 'sometimes' : 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'starts_on' => ['sometimes', 'nullable', 'date'],
            'ends_on' => ['sometimes', 'nullable', 'date', 'after_or_equal:starts_on'],
            'image_path' => ['sometimes', 'nullable', 'string', 'max:255'],
            'visit_offer_ids' => ['sometimes', 'array'],
            'visit_offer_ids.*' => ['required', 'uuid', 'distinct', Rule::exists('visit_offers', 'id')->where('organization_id', $tenant)],
            'visit_offer_versions' => ['sometimes', 'array'],
            'visit_offer_versions.*' => ['required', 'integer', 'min:1'],
            'source_campaign_versions' => ['sometimes', 'array'],
            'source_campaign_versions.*' => ['required', 'integer', 'min:1'],
        ]);
        $start = array_key_exists('starts_on', $data) ? $data['starts_on'] : $campaign?->starts_on?->toDateString();
        $end = array_key_exists('ends_on', $data) ? $data['ends_on'] : $campaign?->ends_on?->toDateString();
        if ($start !== null && $end !== null && CarbonImmutable::parse($end)->lessThan(CarbonImmutable::parse($start))) {
            throw ValidationException::withMessages(['ends_on' => 'Крайната дата трябва да е след началната.']);
        }

        return $data;
    }

    private function organization(TenantContext $context): Organization
    {
        return Organization::query()->findOrFail($context->organizationId());
    }

    /** @return array<string, mixed> */
    private function present(OpenDayCampaign $campaign): array
    {
        $campaign->loadMissing('visitOffers');

        return [
            'id' => $campaign->id, 'slug' => $campaign->slug, 'title' => $campaign->title,
            'description' => $campaign->description, 'starts_on' => $campaign->starts_on?->toDateString(),
            'ends_on' => $campaign->ends_on?->toDateString(), 'image_path' => $campaign->image_path,
            'status' => $campaign->status->value, 'version' => $campaign->version,
            'published_at' => $campaign->published_at?->toIso8601String(),
            'visit_offers' => $campaign->visitOffers->map(fn ($offer) => [
                'id' => $offer->id, 'title' => $offer->title, 'status' => $offer->status->value,
                'type' => $offer->type->value,
            ])->all(),
        ];
    }
}
