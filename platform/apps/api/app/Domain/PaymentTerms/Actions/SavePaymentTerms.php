<?php

namespace App\Domain\PaymentTerms\Actions;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\ValueObjects\AuditableReference;
use App\Domain\Catalog\Models\OrganizationCatalogProfile;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Tenancy\TenantContext;
use App\Domain\PaymentTerms\Enums\BillingKind;
use App\Domain\PaymentTerms\Models\PaymentTerms;
use App\Domain\PaymentTerms\Models\PaymentTermsVersion;
use App\Domain\PaymentTerms\Rules\MarketplaceLimits;
use App\Domain\PaymentTerms\Rules\Presets;
use App\Domain\PaymentTerms\Rules\TermsSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Creates and changes the organization's sets of payment terms (docs/product/payment-policies.md, т. 7.1;
 * step 2b). Only the owner and the administrator. The numbers pass the schema and, for an organization in the
 * public catalog, the marketplace frame; the ready-made label is derived here; a change of the numbers is a
 * new immutable version, so copies taken earlier keep what the parent accepted.
 */
final readonly class SavePaymentTerms
{
    public function __construct(private TenantContext $context, private AuditRecorder $audit) {}

    /**
     * @param  array{billing_kind: string, name: string, is_default?: bool, rules: array<string, mixed>, self_service: array<string, mixed>}  $data
     */
    public function create(User $actor, array $data): PaymentTerms
    {
        $this->authorize($actor);
        $kind = BillingKind::from($data['billing_kind']);
        $name = trim($data['name']);
        $rules = $this->checkedRules($kind, $data['rules']);
        $selfService = $this->checkedSelfService($data['self_service']);
        $this->checkMarketplace($kind, $rules);

        return DB::transaction(function () use ($actor, $data, $kind, $name, $rules, $selfService): PaymentTerms {
            $isDefault = (bool) ($data['is_default'] ?? false);
            $this->lockKind($kind);
            $this->checkNameIsFree($kind, $name);
            if ($isDefault) {
                $this->clearDefault($kind);
            }
            $terms = PaymentTerms::query()->create([
                'billing_kind' => $kind,
                'name' => $name,
                'preset' => Presets::match($kind, $rules, $selfService),
                'is_default' => $isDefault,
                'self_service' => $selfService,
                'status' => 'active',
                'version' => 1,
                'created_by' => $actor->id,
            ]);
            $version = $this->addVersion($actor, $terms, 1, $rules);
            $this->audit->record(AuditAction::PaymentTermsCreated, new AuditableReference('payment_terms', $terms->id), [
                'billing_kind' => $kind->value,
                'preset' => $terms->preset->value,
                'is_default' => $isDefault,
                'version' => 1,
                'terms_version_id' => $version->id,
            ]);

            return $terms->setRelation('currentVersion', $version);
        });
    }

    /**
     * @param  array{name?: string, is_default?: bool, status?: string, rules?: array<string, mixed>, self_service?: array<string, mixed>}  $data
     */
    public function update(User $actor, PaymentTerms $terms, int $expectedVersion, array $data): PaymentTerms
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($actor, $terms, $expectedVersion, $data): PaymentTerms {
            $this->lockKind($terms->billing_kind);
            $locked = PaymentTerms::query()->with('currentVersion')->whereKey($terms->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->version === $expectedVersion, Response::HTTP_CONFLICT, 'Условията са променени междувременно. Презаредете страницата и опитайте отново.');
            $kind = $locked->billing_kind;
            $current = $locked->currentVersion;
            if ($current === null) {
                abort(Response::HTTP_CONFLICT, 'Наборът няма запазени условия.');
            }

            $status = $data['status'] ?? $locked->status;
            if ($locked->status === 'archived' && $status === 'archived' && array_diff(array_keys($data), ['status']) !== []) {
                throw ValidationException::withMessages(['status' => 'Архивиран набор не се променя. Първо го върнете в употреба.']);
            }
            $isDefault = $data['is_default'] ?? $locked->is_default;
            if ($locked->is_default && ! $isDefault) {
                throw ValidationException::withMessages(['is_default' => 'Изберете друг набор по подразбиране — тогава този престава да е такъв.']);
            }
            if ($status === 'archived' && $isDefault) {
                throw ValidationException::withMessages(['status' => 'Наборът по подразбиране не може да се архивира. Първо изберете друг.']);
            }

            $rules = array_key_exists('rules', $data) ? $this->checkedRules($kind, $data['rules']) : $current->rules;
            $selfService = array_key_exists('self_service', $data) ? $this->checkedSelfService($data['self_service']) : $locked->self_service;
            if ($status === 'active') {
                $this->checkMarketplace($kind, $rules);
            }
            $name = array_key_exists('name', $data) ? trim($data['name']) : $locked->name;
            if ($status === 'active' && ($name !== $locked->name || $locked->status !== 'active')) {
                $this->checkNameIsFree($kind, $name, $locked->id);
            }

            $rulesChanged = Presets::canonical($rules) !== Presets::canonical($current->rules);
            $selfServiceChanged = Presets::canonical($selfService) !== Presets::canonical($locked->self_service);
            $wasDefault = $locked->is_default;
            $locked->fill([
                'name' => $name,
                'is_default' => $isDefault,
                'status' => $status,
                'self_service' => $selfService,
                'preset' => Presets::match($kind, $rules, $selfService),
            ]);
            if (! $rulesChanged && ! $selfServiceChanged && ! $locked->isDirty(['name', 'is_default', 'status', 'preset'])) {
                return $locked;
            }

            if ($isDefault && ! $wasDefault) {
                $this->clearDefault($kind);
            }
            $locked->version = $expectedVersion + 1;
            $locked->save();
            $version = $rulesChanged ? $this->addVersion($actor, $locked, $current->number + 1, $rules) : $current;
            $this->audit->record(AuditAction::PaymentTermsUpdated, new AuditableReference('payment_terms', $locked->id), [
                'billing_kind' => $kind->value,
                'preset' => $locked->preset->value,
                'is_default' => $isDefault,
                'status' => $status,
                'version' => $locked->version,
                'terms_version_id' => $version->id,
                'rules_changed' => $rulesChanged,
            ]);

            return $locked->setRelation('currentVersion', $version);
        });
    }

    private function authorize(User $actor): void
    {
        Gate::forUser($actor)->authorize('managePaymentTerms', Organization::query()->findOrFail($this->context->organizationId()));
    }

    /**
     * Serializes saves of one way of paying in this organization, so two sets never become the default at once
     * and two sets never take the same name — also when the organization has no set yet (nothing to lock).
     */
    private function lockKind(BillingKind $kind): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['payment_terms:'.$this->context->organizationId().':'.$kind->value]);
        }
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    private function checkedRules(BillingKind $kind, array $rules): array
    {
        try {
            return TermsSchema::validate($kind, $rules);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(self::prefixed('rules', $exception->errors()));
        }
    }

    /**
     * @param  array<string, mixed>  $selfService
     * @return array<string, mixed>
     */
    private function checkedSelfService(array $selfService): array
    {
        try {
            return TermsSchema::validateSelfService($selfService);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(self::prefixed('self_service', $exception->errors()));
        }
    }

    /** @param array<string, mixed> $rules */
    private function checkMarketplace(BillingKind $kind, array $rules): void
    {
        if (! OrganizationCatalogProfile::query()->where('is_published', true)->exists()) {
            return;
        }
        $violations = MarketplaceLimits::violations($kind, $rules);
        if ($violations !== []) {
            throw ValidationException::withMessages(self::prefixed('rules', array_map(fn (string $message): array => [$message], $violations)));
        }
    }

    private function checkNameIsFree(BillingKind $kind, string $name, ?string $except = null): void
    {
        // Compared here, not in SQL: SQLite lowers only Latin letters.
        $query = PaymentTerms::query()->where('billing_kind', $kind)->where('status', 'active');
        if ($except !== null) {
            $query->whereKeyNot($except);
        }
        $wanted = mb_strtolower($name);
        $taken = $query->pluck('name')->contains(fn (mixed $other): bool => is_string($other) && mb_strtolower($other) === $wanted);
        if ($taken) {
            throw ValidationException::withMessages(['name' => 'Вече има набор с това име за този начин на плащане.']);
        }
    }

    private function clearDefault(BillingKind $kind): void
    {
        PaymentTerms::query()->where('billing_kind', $kind)->where('is_default', true)->get()
            ->each(function (PaymentTerms $previous): void {
                $previous->is_default = false;
                $previous->version++;
                $previous->save();
                $this->audit->record(AuditAction::PaymentTermsUpdated, new AuditableReference('payment_terms', $previous->id), [
                    'billing_kind' => $previous->billing_kind->value,
                    'is_default' => false,
                    'version' => $previous->version,
                    'rules_changed' => false,
                ]);
            });
    }

    /** @param array<string, mixed> $rules */
    private function addVersion(User $actor, PaymentTerms $terms, int $number, array $rules): PaymentTermsVersion
    {
        return PaymentTermsVersion::query()->create([
            'terms_id' => $terms->id,
            'number' => $number,
            'schema_version' => TermsSchema::VERSION,
            'rules' => $rules,
            'created_by' => $actor->id,
        ]);
    }

    /**
     * @param  array<string, list<string>>  $errors
     * @return array<string, list<string>>
     */
    private static function prefixed(string $prefix, array $errors): array
    {
        $prefixed = [];
        foreach ($errors as $key => $messages) {
            $prefixed["{$prefix}.{$key}"] = $messages;
        }

        return $prefixed;
    }
}
