<?php

namespace App\Domain\PaymentTerms\Models;

use App\Domain\Catalog\Concerns\BelongsToCatalogTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * The numbers of a set of payment terms at one moment. Immutable (a database trigger refuses changes): a copy
 * on an application points here, so the numbers the parent accepted never move.
 *
 * @property string $id
 * @property string $terms_id
 * @property int $number
 * @property int $schema_version
 * @property array<string, mixed> $rules
 * @property CarbonImmutable $created_at
 */
#[Fillable(['terms_id', 'number', 'schema_version', 'rules', 'created_by'])]
class PaymentTermsVersion extends Model
{
    use BelongsToCatalogTenant;
    use HasUuids;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Payment terms versions are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Payment terms versions are immutable.'));
    }

    /** @return BelongsTo<PaymentTerms, $this> */
    public function terms(): BelongsTo
    {
        return $this->belongsTo(PaymentTerms::class, 'terms_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'schema_version' => 'integer',
            'rules' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
