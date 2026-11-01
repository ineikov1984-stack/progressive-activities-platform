<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\Session;
use Carbon\CarbonImmutable;

final class SessionCutoff
{
    public const OPENS_BEFORE_MINUTES = 15;

    public static function at(Session $session): CarbonImmutable
    {
        return $session->starts_at->subMinutes(self::OPENS_BEFORE_MINUTES);
    }

    public static function passed(Session $session, ?CarbonImmutable $now = null): bool
    {
        return ($now ?? CarbonImmutable::now())->gte(self::at($session));
    }
}
