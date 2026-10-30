<?php

namespace App\Domain\PaymentTerms\Models;

use App\Domain\Catalog\Concerns\BelongsToCatalogTenant;
use App\Domain\PaymentTerms\Enums\BillingKind;
use App\Domain\PaymentTerms\Enums\TermsPreset;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A named set of payment terms for one way of paying (docs/product/payment-policies.md, т. 7.1, т. 11). The
 * numbers live in versions; what the parent may do alone (`self_service`) lives here and follows the current
 * choice. Sets are archived, never deleted.
 *
 * @property string $id
 * @property BillingKind $billing_kind
 * @property string $name
 * @property TermsPreset $preset
 * @property bool $is_default
 * @property array<string, mixed> $self_service
 * @property string $status
 * @property int $version
 * @property PaymentTermsVersion|null $currentVersion
 */
#[Fillable(['billing_kind', 'name', 'preset', 'is_default', 'self_service', 'status', 'version', 'created_by'])]
class PaymentTerms extends Model
{
    use BelongsToCatalogTenant;
    use HasUuids;

    protected $table = 'payment_terms';

    /** @return HasMany<PaymentTermsVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(PaymentTermsVersion::class, 'terms_id');
    }

    /** @return HasOne<PaymentTermsVersion, $this> */
    public function currentVersion(): HasOne
    {
        return $this->hasOne(PaymentTermsVersion::class, 'terms_id')->ofMany('number', 'max');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'billing_kind' => BillingKind::class,
            'preset' => TermsPreset::class,
            'is_default' => 'boolean',
            'self_service' => 'array',
            'version' => 'integer',
        ];
    }
}
