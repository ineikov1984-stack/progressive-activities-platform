<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Models\Offering;
use App\Domain\PaymentTerms\Enums\BillingKind;
use App\Domain\PaymentTerms\Services\PaymentTermsResolver;

/**
 * The activity's terms for each way of paying, replaced as a whole (docs/product/payment-policies.md, т. 7.2). A
 * way of paying left out, or given null, uses the organization's terms. Runs after the prices are saved, so the
 * check sees the prices of the same request.
 */
final readonly class SyncPaymentTermsChoices
{
    public function __construct(private PaymentTermsResolver $paymentTerms) {}

    /** @param array<array-key, mixed> $choices billing kind => set id or null */
    public function handle(Offering $offering, array $choices): void
    {
        $modes = $offering->priceOptions()->pluck('enrollment_mode')->all();
        $chosen = [];
        foreach ($choices as $kind => $termsId) {
            if ($termsId === null) {
                continue;
            }
            $billingKind = BillingKind::from((string) $kind);
            $this->paymentTerms->assertChoosable((string) $termsId, $billingKind, $modes, "offering.payment_terms.{$kind}");
            $chosen[$billingKind->value] = (string) $termsId;
        }

        $offering->paymentTermsChoices()->delete();
        foreach ($chosen as $kind => $termsId) {
            $offering->paymentTermsChoices()->create(['billing_kind' => $kind, 'payment_terms_id' => $termsId]);
        }
        $offering->unsetRelation('paymentTermsChoices');
    }
}
