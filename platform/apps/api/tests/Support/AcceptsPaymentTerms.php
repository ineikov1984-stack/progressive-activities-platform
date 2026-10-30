<?php

namespace Tests\Support;

use App\Domain\Catalog\Models\PriceOption;
use App\Domain\PaymentTerms\Enums\BillingKind;
use App\Domain\PaymentTerms\Services\AppliedTerms;

/** What a parent accepts next to a price (docs/product/payment-policies.md, т. 7.3). */
trait AcceptsPaymentTerms
{
    /** The terms version shown with a price while its organization has not saved terms of its own. */
    protected function standardTermsVersion(string $priceId): string
    {
        $mode = (string) PriceOption::query()->withoutGlobalScopes()->whereKey($priceId)->value('enrollment_mode');

        return AppliedTerms::standard(BillingKind::fromEnrollmentMode($mode) ?? BillingKind::Monthly)->key();
    }
}
