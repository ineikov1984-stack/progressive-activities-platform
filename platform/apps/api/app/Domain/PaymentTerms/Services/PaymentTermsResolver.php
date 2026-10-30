<?php

namespace App\Domain\PaymentTerms\Services;

use App\Domain\PaymentTerms\Enums\BillingKind;
use App\Domain\PaymentTerms\Models\PaymentTerms;
use Illuminate\Validation\ValidationException;

/**
 * Which terms apply to a price (docs/product/payment-policies.md, т. 7.2): the set chosen for the activity when
 * it is for the price's way of paying, otherwise the organization's default for it, otherwise the platform's
 * "Стандартни". Reads the current organization only (tenant scope and RLS); other domains call it instead of
 * reading the sets themselves. Keeps nothing between calls: a controller may outlive one request.
 */
final class PaymentTermsResolver
{
    /** The terms for a price with this enrollment mode, in an activity that chose `$chosenTermsId` (or none). */
    public function forPrice(string $enrollmentMode, ?string $chosenTermsId): ?AppliedTerms
    {
        $kind = BillingKind::fromEnrollmentMode($enrollmentMode);
        if ($kind === null) {
            return null;
        }

        return $this->resolve($kind, $chosenTermsId);
    }

    /**
     * A set chosen for an activity must be one of the organization's own, not archived, and for a way of paying
     * the activity has — otherwise it would never apply and the team would think it does.
     *
     * @param  array<array-key, mixed>  $enrollmentModes  the modes of the activity's prices
     */
    public function assertChoosable(string $termsId, array $enrollmentModes, string $field): void
    {
        $terms = PaymentTerms::query()->whereKey($termsId)->where('status', 'active')->first();
        if ($terms === null) {
            throw ValidationException::withMessages([$field => 'Изберете условия от „Настройки → Условия за плащане“.']);
        }
        $kinds = array_map(fn (mixed $mode): ?BillingKind => BillingKind::fromEnrollmentMode((string) $mode), array_values($enrollmentModes));
        if (! in_array($terms->billing_kind, $kinds, true)) {
            throw ValidationException::withMessages([
                $field => 'Тези условия са за „'.$terms->billing_kind->label().'“, а дейността няма такава цена.',
            ]);
        }
    }

    private function resolve(BillingKind $kind, ?string $chosenTermsId): AppliedTerms
    {
        // A chosen set stays in force if it is archived later: the activity was published with it.
        $terms = $chosenTermsId === null ? null : PaymentTerms::query()->whereKey($chosenTermsId)
            ->where('billing_kind', $kind->value)->with('currentVersion')->first();
        $terms ??= PaymentTerms::query()->where('billing_kind', $kind->value)->where('is_default', true)
            ->where('status', 'active')->with('currentVersion')->first();
        $version = $terms?->currentVersion;

        return $terms !== null && $version !== null ? AppliedTerms::ofVersion($terms, $version) : AppliedTerms::standard($kind);
    }
}
