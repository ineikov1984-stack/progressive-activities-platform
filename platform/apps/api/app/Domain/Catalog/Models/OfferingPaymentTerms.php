<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Catalog\Concerns\BelongsToCatalogTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The set of payment terms an activity uses for one way of paying (docs/product/payment-policies.md, т. 7.2).
 * Without a row the organization's default for that way of paying applies (PaymentTermsResolver).
 *
 * @property string $offering_id
 * @property string $billing_kind
 * @property string $payment_terms_id
 */
#[Fillable(['offering_id', 'billing_kind', 'payment_terms_id'])]
class OfferingPaymentTerms extends Model
{
    use BelongsToCatalogTenant;

    protected $table = 'offering_payment_terms';

    /** One row per (offering, way of paying); rows are replaced as a whole, never updated by key. */
    protected $primaryKey = 'offering_id';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * The real key is (offering, way of paying): an update or delete of one choice never touches the others.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function setKeysForSaveQuery($query)
    {
        return $query->where('offering_id', $this->getOriginal('offering_id') ?? $this->getAttribute('offering_id'))
            ->where('billing_kind', $this->getOriginal('billing_kind') ?? $this->getAttribute('billing_kind'));
    }
}
