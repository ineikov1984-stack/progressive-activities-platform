<?php

namespace App\Domain\PaymentTerms\Enums;

/** A ready-made set, or the organization's own numbers (docs/product/payment-policies.md, т. 6). */
enum TermsPreset: string
{
    case Flexible = 'flexible';
    case Standard = 'standard';
    case Strict = 'strict';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::Flexible => 'Гъвкави',
            self::Standard => 'Стандартни',
            self::Strict => 'Строги',
            self::Custom => 'Собствени',
        };
    }
}
