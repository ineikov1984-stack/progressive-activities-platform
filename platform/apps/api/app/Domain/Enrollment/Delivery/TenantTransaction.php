<?php

namespace App\Domain\Enrollment\Delivery;

use App\Domain\Organizations\Tenancy\TenantContext;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * The sender has no user and no request: each step for an organization runs in its own transaction
 * with that organization in the RLS setting and in the tenant context, and the context is cleared
 * afterwards, so the next organization never inherits it (docs/product/enrollment-contacts-and-emails.md, т. 6.1).
 */
final class TenantTransaction
{
    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public static function run(string $organizationId, Closure $work): mixed
    {
        $context = app(TenantContext::class);

        return DB::transaction(function () use ($context, $organizationId, $work): mixed {
            if (DB::getDriverName() === 'pgsql') {
                DB::select("SELECT set_config('app.current_organization_id', ?, true)", [$organizationId]);
            }
            $context->select($organizationId);

            try {
                return $work();
            } finally {
                $context->clear();
            }
        });
    }
}
