<?php

namespace App\Domain\Catalog\Concerns;

use App\Domain\Organizations\Tenancy\TenantContext;
use App\Domain\Organizations\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Model;

trait BelongsToCatalogTenant
{
    protected static function bootBelongsToCatalogTenant(): void
    {
        static::addGlobalScope(new TenantScope('organization_id'));

        static::creating(function (Model $model): void {
            $model->setAttribute('organization_id', app(TenantContext::class)->organizationId());
        });
    }
}
