<?php

namespace App\Domain\PaymentTerms\Rules;

use App\Domain\PaymentTerms\Enums\BillingKind;
use Illuminate\Support\Arr;

/**
 * The frame for organizations in the public catalog (docs/product/payment-policies.md, т. 7.5; the owner's
 * decision of 30.10.2026): notice up to a month, a deposit up to 30 %, and never "nothing comes back, ever" —
 * every cancellation ladder has a step that keeps less than everything. Checked on rules that already passed
 * TermsSchema; returns what is wrong, in Bulgarian, or nothing.
 */
final class MarketplaceLimits
{
    public const MAX_NOTICE_DAYS = 30;

    public const MAX_DEPOSIT_PERCENT = 30;

    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, string>
     */
    public static function violations(BillingKind $kind, array $rules): array
    {
        $violations = [];
        $notice = match ($kind) {
            BillingKind::Monthly => Arr::get($rules, 'end.notice_days'),
            BillingKind::Kindergarten => Arr::get($rules, 'notice_days'),
            default => null,
        };
        if (is_int($notice) && $notice > self::MAX_NOTICE_DAYS) {
            $violations[$kind === BillingKind::Monthly ? 'end.notice_days' : 'notice_days'] = 'В каталога на платформата предизвестието е най-много 30 дни.';
        }
        $ladder = match ($kind) {
            BillingKind::Visit => 'cancellation.tiers',
            BillingKind::FullPeriod, BillingKind::DayCamp, BillingKind::ResidentialCamp => 'before_start.tiers',
            default => null,
        };
        if ($ladder !== null) {
            $kept = array_column((array) Arr::get($rules, $ladder, []), 'kept');
            if ($kept === [] || min($kept) >= 100) {
                $violations[$ladder] = 'В каталога на платформата поне при ранен отказ трябва да се връща нещо.';
            }
        }
        if ($kind === BillingKind::ResidentialCamp && Arr::get($rules, 'deposit.mode') === 'percent'
            && (int) Arr::get($rules, 'deposit.percent', 0) > self::MAX_DEPOSIT_PERCENT) {
            $violations['deposit.percent'] = 'В каталога на платформата капарото е най-много 30 % от цената.';
        }

        return $violations;
    }
}
