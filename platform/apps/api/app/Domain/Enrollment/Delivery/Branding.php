<?php

namespace App\Domain\Enrollment\Delivery;

use App\Domain\Catalog\Models\OrganizationCatalogProfile;

/**
 * The organization's look in its emails: its colour (the owner chooses it in the catalog settings), its logo
 * when the profile is public and the file is stored, else its initials. The text on the colour is white or dark,
 * whichever reads better, so a light colour never makes a button unreadable (decision of 30.10.2026).
 */
final readonly class Branding
{
    /** The platform's navy, for organizations without a colour and for team emails. */
    public const PLATFORM = '#173F5F';

    private const DARK_TEXT = '#173F5F';

    public function __construct(
        public string $name,
        public string $accent,
        public string $onAccent,
        public ?string $logoUrl,
        public string $initials,
    ) {}

    /**
     * @param  string  $webUrl  the web app (WEB_URL): the logo is fetched through its public `/backend` proxy, the
     *                          same address the public catalog uses — the API host itself need not be reachable
     * @param  bool  $logoStored  the logo's file is on disk; a missing one would show as a broken image
     */
    public static function forOrganization(string $name, ?OrganizationCatalogProfile $profile, string $webUrl, bool $logoStored): self
    {
        $accent = is_string($profile?->accent_color) && preg_match('/^#[0-9a-fA-F]{6}$/', $profile->accent_color) === 1
            ? strtoupper($profile->accent_color) : self::PLATFORM;
        // The logo is public only with a published profile (the same rule as the public catalog).
        $logo = null;
        if ($logoStored && $profile !== null && $profile->is_published && is_string($profile->logo_path)
            && preg_match('/^catalog\/([0-9a-f-]{36}\.(?:jpg|png|webp))$/D', $profile->logo_path, $match) === 1) {
            $logo = rtrim($webUrl, '/').'/backend/api/v1/public/organizations/'.rawurlencode((string) $profile->slug).'/media/'.$match[1];
        }

        return new self($name, $accent, self::readableOn($accent), $logo, self::initials($name));
    }

    public static function platform(string $name): self
    {
        return new self($name, self::PLATFORM, '#FFFFFF', null, self::initials($name));
    }

    /** White or the dark ink, whichever has the higher contrast on the colour. */
    public static function readableOn(string $hex): string
    {
        $background = self::luminance($hex);
        $white = 1.05 / ($background + 0.05);
        $dark = ($background + 0.05) / (self::luminance(self::DARK_TEXT) + 0.05);

        return $white >= $dark ? '#FFFFFF' : self::DARK_TEXT;
    }

    /** "PA" for "Progressive Academy Demo". */
    public static function initials(string $name): string
    {
        $words = preg_split('/[\s\-„“"]+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $letters = array_map(fn (string $word): string => mb_strtoupper(mb_substr($word, 0, 1)), array_slice($words, 0, 2));

        return $letters === [] ? '•' : implode('', $letters);
    }

    private static function luminance(string $hex): float
    {
        $channels = array_map(function (string $pair): float {
            $value = hexdec($pair) / 255;

            return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }, str_split(ltrim($hex, '#'), 2));

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }
}
