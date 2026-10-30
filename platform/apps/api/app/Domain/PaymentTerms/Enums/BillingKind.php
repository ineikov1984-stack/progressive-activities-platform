<?php

namespace App\Domain\PaymentTerms\Enums;

/**
 * How the parent pays — each has its own terms (docs/product/payment-policies.md, т. 4). The kind belongs to
 * the price, not to the enrollment: monthly, full-period and package prices all enroll "for the period".
 */
enum BillingKind: string
{
    case Monthly = 'monthly';
    case FullPeriod = 'full_period';
    case Visit = 'visit';
    case Package = 'package';
    case DayCamp = 'day_camp';
    case ResidentialCamp = 'residential_camp';
    case Kindergarten = 'kindergarten';

    /** The kind for a price option's enrollment mode (`trial` and `single` share the visit terms). */
    public static function fromEnrollmentMode(string $mode): ?self
    {
        return match ($mode) {
            'monthly' => self::Monthly,
            'full_period' => self::FullPeriod,
            'package' => self::Package,
            'trial', 'single' => self::Visit,
            default => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Месечна такса',
            self::FullPeriod => 'Цял курс наведнъж',
            self::Visit => 'Пробно или единично посещение',
            self::Package => 'Карта с посещения',
            self::DayCamp => 'Дневен кемп',
            self::ResidentialCamp => 'Лагер с нощувки',
            self::Kindergarten => 'Градина и занималня',
        };
    }
}
