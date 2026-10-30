<?php

namespace App\Domain\PaymentTerms\Services;

use App\Domain\PaymentTerms\Enums\BillingKind;
use App\Domain\PaymentTerms\Enums\TermsPreset;
use App\Domain\PaymentTerms\Models\PaymentTerms;
use App\Domain\PaymentTerms\Models\PaymentTermsVersion;
use App\Domain\PaymentTerms\Rules\MarketplaceLimits;
use App\Domain\PaymentTerms\Rules\Presets;
use App\Domain\PaymentTerms\Rules\TermsSchema;
use Carbon\CarbonImmutable;

/**
 * The terms that apply to one price right now (docs/product/payment-policies.md, т. 7): a version of one of the
 * organization's sets, or the platform's "Стандартни" while the organization has not saved its own for this way
 * of paying. `key()` is what the parent saw; the copy is what the application keeps.
 */
final readonly class AppliedTerms
{
    /** @param array<string, mixed> $rules */
    private function __construct(
        public BillingKind $kind,
        public ?string $termsId,
        public ?string $versionId,
        public ?int $versionNumber,
        public string $name,
        public TermsPreset $preset,
        public int $schemaVersion,
        public array $rules,
    ) {}

    public static function ofVersion(PaymentTerms $terms, PaymentTermsVersion $version): self
    {
        return new self(
            $terms->billing_kind, $terms->id, $version->id, $version->number, $terms->name, $terms->preset,
            $version->schema_version, $version->rules,
        );
    }

    /** The platform's ready-made terms for a way of paying the organization has no set for. */
    public static function standard(BillingKind $kind): self
    {
        return new self($kind, null, null, null, 'Условията на организацията', TermsPreset::Standard,
            TermsSchema::VERSION, Presets::rules($kind, TermsPreset::Standard));
    }

    /**
     * The version the parent accepts. A saved version is its id; the ready-made terms are named by their numbers,
     * so a change in them also asks the parent to look again.
     */
    public function key(): string
    {
        return $this->versionId ?? 'standard-'.$this->kind->value.'-'
            .substr(hash('sha256', json_encode(Presets::canonical($this->rules), JSON_THROW_ON_ERROR)), 0, 16);
    }

    /** @return array<string, string> */
    public function marketplaceViolations(): array
    {
        return MarketplaceLimits::violations($this->kind, $this->rules);
    }

    /**
     * The copy kept with an application: the numbers, not a pointer to them. Ids only for the people involved.
     *
     * @param  'parent'|'team'  $acceptedVia
     * @return array<string, mixed>
     */
    public function snapshot(string $acceptedVia, CarbonImmutable $acceptedAt, ?string $acceptedBy = null, bool $earlyStart = false): array
    {
        return [
            'key' => $this->key(),
            'billing_kind' => $this->kind->value,
            'terms_id' => $this->termsId,
            'version_id' => $this->versionId,
            'version_number' => $this->versionNumber,
            'name' => $this->name,
            'preset' => $this->preset->value,
            'schema_version' => $this->schemaVersion,
            'rules' => $this->rules,
            'early_start' => $earlyStart,
            'accepted' => array_filter([
                'via' => $acceptedVia,
                'by_user_id' => $acceptedBy,
                'at' => $acceptedAt->toIso8601String(),
            ], fn ($value) => $value !== null),
        ];
    }

    /**
     * What a parent reads before applying (the public activity page and, later, the application screen).
     *
     * @return array<string, mixed>
     */
    public function presentForParent(): array
    {
        return [
            'version' => $this->key(),
            'billing_kind' => $this->kind->value,
            'billing_kind_label' => $this->kind->label(),
            'name' => $this->name,
            'preset' => $this->preset->value,
            'preset_label' => $this->preset->label(),
            'schema_version' => $this->schemaVersion,
            'rules' => $this->rules,
        ];
    }
}
