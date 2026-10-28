<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\Actions\PublishVisitOffer;
use App\Domain\Catalog\Actions\SaveVisitOffer;
use App\Domain\Catalog\Enums\VisitFeeType;
use App\Domain\Catalog\Enums\VisitOfferType;
use App\Domain\Catalog\Models\VisitOffer;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final class VisitOfferController extends Controller
{
    public function index(Request $request, TenantContext $context): JsonResponse
    {
        Gate::forUser($request->user())->authorize('catalog.view', $this->organization($context));

        return response()->json(['visit_offers' => VisitOffer::query()->with('sessions')->latest()->get()->map($this->present(...))->all()]);
    }

    public function show(Request $request, TenantContext $context, string $visitOffer): JsonResponse
    {
        Gate::forUser($request->user())->authorize('catalog.view', $this->organization($context));

        return response()->json(['visit_offer' => $this->present(VisitOffer::query()->with('sessions')->findOrFail($visitOffer))]);
    }

    public function store(Request $request, TenantContext $context, SaveVisitOffer $save): JsonResponse
    {
        Gate::forUser($request->user())->authorize('catalog.manage', $this->organization($context));
        $data = $this->validated($request, $context);
        $offer = $save->create($request->user(), $data);

        return response()->json(['visit_offer' => $this->present($offer)], Response::HTTP_CREATED);
    }

    public function update(Request $request, TenantContext $context, string $visitOffer, SaveVisitOffer $save): JsonResponse
    {
        Gate::forUser($request->user())->authorize('catalog.manage', $this->organization($context));
        $offer = VisitOffer::query()->findOrFail($visitOffer);
        $data = $this->validated($request, $context, $offer);
        $updated = $save->update($request->user(), $offer, $data['version'], $data);

        return response()->json(['visit_offer' => $this->present($updated)]);
    }

    public function publish(Request $request, string $visitOffer, PublishVisitOffer $action): JsonResponse
    {
        return $this->transition($request, $visitOffer, $action, 'publish');
    }

    public function close(Request $request, string $visitOffer, PublishVisitOffer $action): JsonResponse
    {
        return $this->transition($request, $visitOffer, $action, 'close');
    }

    public function cancel(Request $request, string $visitOffer, PublishVisitOffer $action): JsonResponse
    {
        return $this->transition($request, $visitOffer, $action, 'cancel');
    }

    private function transition(Request $request, string $id, PublishVisitOffer $action, string $transition): JsonResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $offer = VisitOffer::query()->findOrFail($id);

        return response()->json(['visit_offer' => $this->present($action->handle($request->user(), $offer, $data['version'], $transition))]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, TenantContext $context, ?VisitOffer $offer = null): array
    {
        $required = $offer ? 'sometimes' : 'required';
        $tenant = $context->organizationId();
        $data = $request->validate([
            'version' => $offer ? ['required', 'integer', 'min:1'] : ['prohibited'],
            'activity_id' => [$required, 'uuid', Rule::exists('activities', 'id')->where('organization_id', $tenant)],
            'group_id' => [$required, 'uuid', Rule::exists('activity_groups', 'id')->where('organization_id', $tenant)],
            'campaign_id' => ['prohibited'],
            'session_ids' => ['sometimes', 'array'],
            'session_ids.*' => ['required', 'uuid', 'distinct', Rule::exists('activity_sessions', 'id')->where('organization_id', $tenant)],
            'type' => [$required, Rule::enum(VisitOfferType::class)],
            'title' => [$required, 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'audience_age_label' => ['sometimes', 'nullable', 'string', 'max:100'],
            'audience_class_label' => ['sometimes', 'nullable', 'string', 'max:100'],
            'visit_limit' => [$required, 'integer', 'min:1'],
            'fee_type' => ['sometimes', Rule::enum(VisitFeeType::class)],
            'amount_cents' => ['sometimes', 'integer', 'min:0'],
            'currency' => ['sometimes', Rule::in(['EUR'])],
            'fee_reason' => ['sometimes', 'nullable', 'string'],
            'booking_deadline_hours' => ['sometimes', 'integer', 'min:0'],
            'cancellation_deadline_hours' => ['sometimes', 'integer', 'min:0'],
            'cancellation_policy' => ['sometimes', 'nullable', 'string'],
            'cancellation_outcome' => ['sometimes', Rule::in(['refund', 'credit', 'non_refundable'])],
            'terms_version' => ['sometimes', 'nullable', 'string', 'max:100'],
            'trial_limit_per_academic_year' => ['sometimes', 'integer', 'min:1'],
            'single_visit_limit' => ['sometimes', 'integer', 'min:1'],
            'single_visit_window_days' => ['sometimes', 'integer', 'min:1'],
            'unlimited_single_visits' => ['sometimes', 'boolean'],
            'no_show_trial_limit' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'waitlist_enabled' => ['sometimes', 'boolean'],
        ]);

        $fee = $data['fee_type'] ?? ($offer === null ? 'free' : $offer->fee_type->value);
        $amount = $data['amount_cents'] ?? ($offer === null ? 0 : $offer->amount_cents);
        $reason = array_key_exists('fee_reason', $data) ? $data['fee_reason'] : $offer?->fee_reason;
        if ($fee === 'free' && $amount !== 0) {
            throw ValidationException::withMessages(['amount_cents' => 'Безплатното посещение трябва да е 0 евроцента.']);
        }
        if ($fee !== 'free' && $amount <= 0) {
            throw ValidationException::withMessages(['amount_cents' => 'Таксата трябва да е положителна сума в евроцентове.']);
        }
        if ($fee !== 'free' && trim((string) $reason) === '') {
            throw ValidationException::withMessages(['fee_reason' => 'Добавете основание за таксата.']);
        }

        return $data;
    }

    private function organization(TenantContext $context): Organization
    {
        return Organization::query()->findOrFail($context->organizationId());
    }

    /** @return array<string, mixed> */
    private function present(VisitOffer $offer): array
    {
        $offer->loadMissing('sessions');

        return [
            'id' => $offer->id, 'activity_id' => $offer->activity_id, 'group_id' => $offer->group_id,
            'campaign_id' => $offer->campaign_id, 'type' => $offer->type->value,
            'status' => $offer->status->value, 'version' => $offer->version,
            'title' => $offer->title, 'description' => $offer->description,
            'audience_age_label' => $offer->audience_age_label, 'audience_class_label' => $offer->audience_class_label,
            'visit_limit' => $offer->visit_limit, 'fee_type' => $offer->fee_type->value,
            'amount_cents' => $offer->amount_cents, 'currency' => $offer->currency,
            'fee_reason' => $offer->fee_reason, 'booking_deadline_hours' => $offer->booking_deadline_hours,
            'cancellation_deadline_hours' => $offer->cancellation_deadline_hours,
            'cancellation_policy' => $offer->cancellation_policy, 'cancellation_outcome' => $offer->cancellation_outcome,
            'terms_version' => $offer->terms_version, 'trial_limit_per_academic_year' => $offer->trial_limit_per_academic_year,
            'single_visit_limit' => $offer->single_visit_limit, 'single_visit_window_days' => $offer->single_visit_window_days,
            'unlimited_single_visits' => $offer->unlimited_single_visits, 'no_show_trial_limit' => $offer->no_show_trial_limit,
            'waitlist_enabled' => $offer->waitlist_enabled,
            'published_at' => $offer->published_at?->toIso8601String(),
            'closed_at' => $offer->closed_at?->toIso8601String(),
            'cancelled_at' => $offer->cancelled_at?->toIso8601String(),
            'sessions' => $offer->sessions->map(fn ($session) => [
                'id' => $session->id, 'starts_at' => $session->starts_at->toIso8601String(),
                'ends_at' => $session->ends_at->toIso8601String(), 'status' => $session->status,
            ])->all(),
        ];
    }
}
