<?php

namespace App\Domain\Enrollment\Actions;

use App\Domain\Catalog\Enums\ActivityStatus;
use App\Domain\Catalog\Enums\EnrollmentMode;
use App\Domain\Catalog\Models\Group;
use App\Domain\Catalog\Models\PriceOption;
use App\Domain\Catalog\Models\Session;
use App\Domain\Enrollment\Enums\EnrollmentApplicationStatus;
use App\Domain\Enrollment\Enums\EnrollmentScope;
use App\Domain\Enrollment\Enums\EnrollmentSource;
use App\Domain\Enrollment\Models\EnrollmentApplication;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Enums\OrganizationStatus;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SubmitEnrollmentApplication
{
    public function __construct(private readonly TenantContext $context) {}

    /** @param array<string, mixed> $payload */
    public function handle(User $actor, array $payload, string $idempotencyKey): EnrollmentApplication
    {
        $child = $actor->children()->where('status', 'active')->findOrFail($payload['child_id']);
        $group = Group::query()->withoutGlobalScopes()->find($payload['group_id']);

        if ($group === null) {
            throw ValidationException::withMessages(['group_id' => 'Избраната група не е достъпна.']);
        }

        $organization = Organization::query()->withoutGlobalScopes()->find($group->organization_id);
        if ($organization === null || $organization->status !== OrganizationStatus::Active) {
            throw ValidationException::withMessages(['group_id' => 'Избраната група не е достъпна.']);
        }

        $this->context->select($organization);

        try {
            return DB::transaction(function () use ($actor, $child, $payload, $idempotencyKey): EnrollmentApplication {
                $this->setDatabaseTenant();

                $existing = EnrollmentApplication::query()
                    ->where('parent_user_id', $actor->id)
                    ->where('source', EnrollmentSource::OrganizationCatalog)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing !== null) {
                    return $existing;
                }

                $group = Group::query()->with('offering.activity')->find($payload['group_id']);
                if ($group === null || $group->status !== 'published'
                    || $group->offering->activity->status !== ActivityStatus::Published) {
                    throw ValidationException::withMessages(['group_id' => 'Избраната група не е достъпна.']);
                }

                $price = PriceOption::query()->whereKey($payload['price_option_id'])
                    ->where('group_id', $group->id)->where('is_available', true)->first();
                if ($price === null) {
                    throw ValidationException::withMessages(['price_option_id' => 'Избраната цена не е достъпна.']);
                }

                $scope = $this->scopeFor(EnrollmentMode::from($price->enrollment_mode));
                $session = $this->resolveSession($payload['session_id'] ?? null, $group, $scope);

                return EnrollmentApplication::query()->create([
                    'parent_user_id' => $actor->id,
                    'child_id' => $child->id,
                    'group_id' => $group->id,
                    'price_option_id' => $price->id,
                    'session_id' => $session?->id,
                    'scope' => $scope,
                    'source' => EnrollmentSource::OrganizationCatalog,
                    'status' => EnrollmentApplicationStatus::Submitted,
                    'idempotency_key' => $idempotencyKey,
                    'accepted_terms_version' => $payload['accepted_terms_version'],
                    'accepted_terms_at' => now(),
                    'amount_cents_snapshot' => $price->amount_cents,
                    'currency_snapshot' => $price->currency,
                    'unit_snapshot' => $price->unit,
                ]);
            });
        } finally {
            $this->context->clear();
        }
    }

    private function scopeFor(EnrollmentMode $mode): EnrollmentScope
    {
        return match ($mode) {
            EnrollmentMode::Trial => EnrollmentScope::Trial,
            EnrollmentMode::Single => EnrollmentScope::Single,
            EnrollmentMode::FullPeriod, EnrollmentMode::Monthly, EnrollmentMode::Package => EnrollmentScope::FullPeriod,
        };
    }

    private function resolveSession(?string $sessionId, Group $group, EnrollmentScope $scope): ?Session
    {
        if ($scope === EnrollmentScope::FullPeriod) {
            if ($sessionId !== null) {
                throw ValidationException::withMessages(['session_id' => 'За записване за целия период не се избира отделна дата.']);
            }

            return null;
        }

        if ($sessionId === null) {
            throw ValidationException::withMessages(['session_id' => 'Изберете предстояща дата.']);
        }

        $session = Session::query()->whereKey($sessionId)->where('group_id', $group->id)
            ->where('status', 'scheduled')->where('starts_at', '>', now())->first();
        if ($session === null) {
            throw ValidationException::withMessages(['session_id' => 'Избраната дата не е достъпна.']);
        }

        return $session;
    }

    private function setDatabaseTenant(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::select("SELECT set_config('app.current_organization_id', ?, true)", [$this->context->organizationId()]);
        }
    }
}
