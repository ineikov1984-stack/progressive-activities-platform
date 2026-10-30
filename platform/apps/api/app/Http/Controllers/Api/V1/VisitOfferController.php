<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\Actions\PublishVisitOffer;
use App\Domain\Catalog\Actions\SaveVisitOffer;
use App\Domain\Catalog\Enums\VisitFeeType;
use App\Domain\Catalog\Enums\VisitOfferType;
use App\Domain\Catalog\Models\VisitOffer;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Tenancy\TenantContext;
use App\Domain\PaymentTerms\Services\PaymentTermsResolver;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final class VisitOfferController extends Controller
{
    public function __construct(private readonly PaymentTermsResolver $paymentTerms) {}

    /** Offer fields that no longer apply (docs/product/visits.md, т. 8a.8). */
    private const RETIRED = [
        'cancellation_deadline_hours', 'cancellation_outcome', 'cancellation_policy', 'terms_version',
        'trial_limit_per_academic_year', 'single_visit_limit', 'single_visit_window_days',
        'unlimited_single_visits', 'no_show_trial_limit',
    ];

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
            // Only an open day has its own fee; a trial or single visit is priced from step 6 (docs/product/visits.md, т. 8a).
            'fee_type' => ['sometimes', Rule::enum(VisitFeeType::class)],
            'amount_cents' => ['sometimes', 'integer', 'min:0'],
            'currency' => ['sometimes', Rule::in(['EUR'])],
            'fee_reason' => ['sometimes', 'nullable', 'string'],
            'booking_deadline_hours' => ['sometimes', 'integer', 'min:0'],
            'waitlist_enabled' => ['sometimes', 'boolean'],
            // The visit terms of the activity and the trial rule replace these; kept as they are for older applications.
            ...array_fill_keys(self::RETIRED, ['prohibited']),
        ], array_fill_keys(array_map(fn (string $field): string => "{$field}.prohibited", self::RETIRED),
            'Правилата за отказ и лимитите вече идват от „Такси, отсъствия и отказ“ и стъпка 6.'));

        $type = $data['type'] ?? $offer?->type->value;
        if ($type !== VisitOfferType::OpenDay->value) {
            foreach (['fee_type', 'amount_cents', 'fee_reason'] as $field) {
                if (array_key_exists($field, $data)) {
                    throw ValidationException::withMessages([$field => 'Цената на пробното и еднократното посещение е от стъпка 6 „Цени и записване“.']);
                }
            }

            // A new trial or single offer (or one turning into one) is stored as free so the row stays valid; nothing
            // reads it for these kinds (т. 8a.1). An older one keeps what it had.
            return $offer === null || $offer->type->value !== $type
                ? [...$data, 'fee_type' => VisitFeeType::Free->value, 'amount_cents' => 0, 'fee_reason' => null]
                : $data;
        }

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
        $offer->loadMissing(['sessions', 'group.priceOptions', 'group.offering.priceOptions', 'group.offering.paymentTermsChoices']);
        $price = $offer->stepSixPrice();

        return [
            'id' => $offer->id, 'activity_id' => $offer->activity_id, 'group_id' => $offer->group_id,
            'campaign_id' => $offer->campaign_id, 'type' => $offer->type->value,
            'status' => $offer->status->value, 'version' => $offer->version,
            'title' => $offer->title, 'description' => $offer->description,
            'audience_age_label' => $offer->audience_age_label, 'audience_class_label' => $offer->audience_class_label,
            'visit_limit' => $offer->visit_limit, 'fee_type' => $offer->fee_type->value,
            'amount_cents' => $offer->amount_cents, 'currency' => $offer->currency,
            'fee_reason' => $offer->fee_reason, 'booking_deadline_hours' => $offer->booking_deadline_hours,
            'waitlist_enabled' => $offer->waitlist_enabled,
            // A trial or single visit is priced from step 6 (null — the activity has no such price yet).
            'price' => $price === null ? null : [
                'id' => $price->id, 'enrollment_mode' => $price->enrollment_mode, 'unit' => $price->unit,
                'amount_cents' => $price->amount_cents, 'currency' => $price->currency,
            ],
            // The activity's "Такси, отсъствия и отказ" for visits, which every application copies (т. 8a.3).
            'payment_terms' => $offer->group === null ? null : $this->paymentTerms
                ->forPrice('single', $offer->group->offering->paymentTermsByKind())?->presentForParent(),
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
