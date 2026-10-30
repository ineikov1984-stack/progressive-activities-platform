<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\Models\OrganizationCatalogProfile;
use App\Domain\Catalog\Models\PriceOption;
use App\Domain\Catalog\Models\VisitOffer;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Tenancy\TenantContext;
use App\Domain\PaymentTerms\Actions\SavePaymentTerms;
use App\Domain\PaymentTerms\Enums\BillingKind;
use App\Domain\PaymentTerms\Enums\TermsPreset;
use App\Domain\PaymentTerms\Models\PaymentTerms;
use App\Domain\PaymentTerms\Rules\MarketplaceLimits;
use App\Domain\PaymentTerms\Rules\Presets;
use App\Domain\PaymentTerms\Rules\TermsSchema;
use App\Http\Controllers\Controller;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Настройки → Условия за плащане" (docs/product/payment-policies.md, т. 7.1; step 2b). The team reads; the
 * owner and the administrator change. A way of paying without a saved default uses "Стандартни".
 */
final class PaymentTermsController extends Controller
{
    private const MESSAGES = [
        'name.required' => 'Въведете име на набора.',
        'name.max' => 'Името е най-много 80 знака.',
        'billing_kind.required' => 'Изберете начин на плащане.',
        'billing_kind.enum' => 'Изберете начин на плащане от списъка.',
        'rules.required' => 'Липсват условията.',
        'self_service.required' => 'Липсва какво родителят може сам.',
        'status.in' => 'Изберете дали наборът е в употреба.',
    ];

    public function index(Request $request, TenantContext $context): JsonResponse
    {
        $organization = $this->organization($context);
        Gate::forUser($request->user())->authorize('viewPaymentTerms', $organization);
        $inMarketplace = OrganizationCatalogProfile::query()->where('is_published', true)->exists();

        $terms = PaymentTerms::query()->with('currentVersion')
            ->orderBy('billing_kind')->orderByDesc('is_default')->orderBy('name')->get();

        return response()->json([
            'payment_terms' => $terms->map(fn (PaymentTerms $set): array => $this->present($set, $inMarketplace))->all(),
            'billing_kinds' => array_map(fn (BillingKind $kind): array => [
                'value' => $kind->value,
                'label' => $kind->label(),
                'presets' => self::readyMade($kind),
            ], BillingKind::cases()),
            'in_marketplace' => $inMarketplace,
            'usage' => (object) self::usage(),
            'can_manage' => Gate::forUser($request->user())->allows('managePaymentTerms', $organization),
            'schema_version' => TermsSchema::VERSION,
        ]);
    }

    public function store(Request $request, TenantContext $context, SavePaymentTerms $save): JsonResponse
    {
        Gate::forUser($request->user())->authorize('managePaymentTerms', $this->organization($context));
        /** @var array{billing_kind: string, name: string, is_default?: bool, rules: array<string, mixed>, self_service: array<string, mixed>} $data */
        $data = $request->validate([
            'billing_kind' => ['required', Rule::enum(BillingKind::class)],
            'name' => ['required', 'string', 'max:80'],
            'is_default' => ['sometimes', 'boolean:strict'],
            'rules' => ['required', 'array'],
            'self_service' => ['required', 'array'],
        ], self::MESSAGES);

        $terms = $save->create($request->user(), $data);

        return response()->json(['payment_terms' => $this->present($terms, $this->inMarketplace())], Response::HTTP_CREATED);
    }

    public function update(Request $request, TenantContext $context, string $terms, SavePaymentTerms $save): JsonResponse
    {
        Gate::forUser($request->user())->authorize('managePaymentTerms', $this->organization($context));
        $record = PaymentTerms::query()->findOrFail($terms);
        /** @var array{version: int, name?: string, is_default?: bool, status?: string, rules?: array<string, mixed>, self_service?: array<string, mixed>} $data */
        $data = $request->validate([
            'version' => ['required', 'integer:strict', 'min:1'],
            'name' => ['sometimes', 'required', 'string', 'max:80'],
            'is_default' => ['sometimes', 'boolean:strict'],
            'status' => ['sometimes', Rule::in(['active', 'archived'])],
            'rules' => ['sometimes', 'required', 'array'],
            'self_service' => ['sometimes', 'required', 'array'],
        ], self::MESSAGES);
        $version = $data['version'];
        unset($data['version']);

        $updated = $save->update($request->user(), $record, $version, $data);

        return response()->json(['payment_terms' => $this->present($updated, $this->inMarketplace())]);
    }

    /**
     * Which of the organization's activities use each way of paying, so the screen can say "Ползва се при: …"
     * (the owner's review of 30.10.2026). Archived activities and cancelled offers do not count.
     *
     * @return array<string, list<string>>
     */
    private static function usage(): array
    {
        $titles = [];
        $prices = PriceOption::query()
            ->join('offerings', fn (JoinClause $join) => $join->on('offerings.id', '=', 'price_options.offering_id')
                ->on('offerings.organization_id', '=', 'price_options.organization_id'))
            ->join('activities', fn (JoinClause $join) => $join->on('activities.id', '=', 'offerings.activity_id')
                ->on('activities.organization_id', '=', 'offerings.organization_id'))
            ->where('activities.status', '!=', 'archived')
            ->orderBy('activities.title')
            ->get(['price_options.enrollment_mode as mode', 'activities.title as title']);
        foreach ($prices as $price) {
            $kind = BillingKind::fromEnrollmentMode((string) $price->getAttribute('mode'));
            if ($kind !== null) {
                $titles[$kind->value][] = self::title($price->getAttribute('title'));
            }
        }
        $offers = VisitOffer::query()
            ->whereIn('type', ['trial', 'single'])
            ->where('status', '!=', 'cancelled')
            ->orderBy('title')
            ->get(['title']);
        foreach ($offers as $offer) {
            $titles[BillingKind::Visit->value][] = self::title($offer->getAttribute('title'));
        }

        return array_map(fn (array $list): array => array_values(array_unique($list)), $titles);
    }

    private static function title(mixed $title): string
    {
        return is_string($title) && trim($title) !== '' ? trim($title) : 'Дейност без име';
    }

    private function organization(TenantContext $context): Organization
    {
        return Organization::query()->findOrFail($context->organizationId());
    }

    private function inMarketplace(): bool
    {
        return OrganizationCatalogProfile::query()->where('is_published', true)->exists();
    }

    /**
     * The ready-made sets, so the screen shows what "Гъвкави / Стандартни / Строги" mean and starts
     * "Собствени" from "Стандартни".
     *
     * @return array<string, array{label: string, rules: array<string, mixed>, self_service: array<string, mixed>}>
     */
    private static function readyMade(BillingKind $kind): array
    {
        $sets = [];
        foreach ([TermsPreset::Flexible, TermsPreset::Standard, TermsPreset::Strict] as $preset) {
            $sets[$preset->value] = [
                'label' => $preset->label(),
                'rules' => Presets::rules($kind, $preset),
                'self_service' => Presets::selfService($preset),
            ];
        }

        return $sets;
    }

    /** @return array<string, mixed> */
    private function present(PaymentTerms $terms, bool $inMarketplace): array
    {
        $version = $terms->currentVersion;
        $rules = $version !== null ? $version->rules : [];

        return [
            'id' => $terms->id,
            'billing_kind' => $terms->billing_kind->value,
            'billing_kind_label' => $terms->billing_kind->label(),
            'name' => $terms->name,
            'preset' => $terms->preset->value,
            'preset_label' => $terms->preset->label(),
            'is_default' => $terms->is_default,
            'status' => $terms->status,
            'version' => $terms->version,
            'self_service' => $terms->self_service,
            'current_version' => $version === null ? null : [
                'id' => $version->id,
                'number' => $version->number,
                'schema_version' => $version->schema_version,
                'rules' => $rules,
                'created_at' => $version->created_at->toIso8601String(),
            ],
            // A set saved before the organization joined the public catalog may fall outside its frame.
            'marketplace_violations' => $inMarketplace && $terms->status === 'active'
                ? (object) MarketplaceLimits::violations($terms->billing_kind, $rules)
                : (object) [],
        ];
    }
}
