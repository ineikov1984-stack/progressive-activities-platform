<?php

namespace App\Providers;

use App\Domain\Catalog\Policies\CatalogPolicy;
use App\Domain\Enrollment\Models\Child;
use App\Domain\Enrollment\Policies\EnrollmentPolicy;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Policies\OrganizationPolicy;
use App\Domain\Organizations\Services\StaffRights;
use App\Domain\Organizations\Tenancy\OperatorContext;
use App\Domain\Organizations\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(TenantContext::class, fn (): TenantContext => new TenantContext);
        $this->app->scoped(OperatorContext::class, fn (): OperatorContext => new OperatorContext);
        // Rights are read once for a request of an organization (docs/product/team-rights.md, т. 4.5).
        $this->app->scoped(StaffRights::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Organization::class, OrganizationPolicy::class);
        Gate::policy(Child::class, EnrollmentPolicy::class);
        Gate::define('catalog.view', [CatalogPolicy::class, 'view']);
        Gate::define('catalog.manage', [CatalogPolicy::class, 'manage']);
        Gate::define('catalog.publish', [CatalogPolicy::class, 'publish']);
        Gate::define('team.assign', [CatalogPolicy::class, 'assign']);
        Gate::define('enrollment.view', [CatalogPolicy::class, 'viewEnrollments']);
        Gate::define('enrollment.view_own', [CatalogPolicy::class, 'viewOwnEnrollments']);
        Gate::define('enrollment.enroll', [CatalogPolicy::class, 'manageEnrollments']);
        Gate::define('contacts.view', [CatalogPolicy::class, 'viewEnrollmentContacts']);
        // The keys from before, until the web moves to the new ones (docs/product/team-rights.md, т. 2.1).
        Gate::define('enrollment.manage', [CatalogPolicy::class, 'manageEnrollments']);
        Gate::define('enrollment.contacts', [CatalogPolicy::class, 'viewEnrollmentContacts']);

        RateLimiter::for('login', function (Request $request): Limit {
            $email = Str::lower((string) $request->input('email'));

            return Limit::perMinute(5)->by($email.'|'.$request->ip());
        });
    }
}
