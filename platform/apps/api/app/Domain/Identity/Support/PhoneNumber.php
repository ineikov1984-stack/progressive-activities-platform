<?php

namespace App\Domain\Identity\Support;

/**
 * Phone numbers kept in E.164 (docs/product/enrollment-contacts-and-emails.md, т. 5), without a new
 * dependency: Bulgarian numbers in the ways people write them, any other country as "+<code><number>".
 */
final class PhoneNumber
{
    /** E.164: a plus, a country code that does not start with 0, 8 to 15 digits in all. */
    private const E164 = '/^\+[1-9]\d{7,14}$/';

    /** A Bulgarian national number: 8 (Sofia landline) or 9 digits (mobile), not starting with 0. */
    private const BULGARIAN = '/^[1-9]\d{7,8}$/';

    /** The number in E.164, or null when it is not a phone number. */
    public static function normalize(?string $value): ?string
    {
        $digits = preg_replace('/[\s\-.\/()]/u', '', trim((string) $value)) ?? '';
        if ($digits === '') {
            return null;
        }
        if (str_starts_with($digits, '00')) {
            $digits = '+'.substr($digits, 2);
        } elseif (str_starts_with($digits, '359') && preg_match('/^359\d{8,9}$/', $digits) === 1) {
            $digits = '+'.$digits;
        } elseif (str_starts_with($digits, '0')) {
            $national = substr($digits, 1);

            return preg_match(self::BULGARIAN, $national) === 1 ? '+359'.$national : null;
        }
        if (str_starts_with($digits, '+359')) {
            return preg_match(self::BULGARIAN, substr($digits, 4)) === 1 ? $digits : null;
        }

        return preg_match(self::E164, $digits) === 1 ? $digits : null;
    }

    /** "088 123 4567" or "02 123 4567" for a Bulgarian number; any other number as it is stored. */
    public static function display(string $e164): string
    {
        if (! str_starts_with($e164, '+359')) {
            return $e164;
        }
        $national = substr($e164, 4);
        $area = strlen($national) === 9 ? substr($national, 0, 2) : substr($national, 0, 1);
        $rest = substr($national, strlen($area));

        return '0'.$area.' '.substr($rest, 0, 3).' '.substr($rest, 3);
    }
}
