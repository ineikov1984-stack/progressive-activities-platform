<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Models\Group;
use App\Domain\Catalog\Models\Offering;
use Illuminate\Support\Arr;

final class SyncPriceOptions
{
    /** @param list<array<string, mixed>> $options */
    public function handle(Offering $offering, ?Group $group, array $options): void
    {
        $query = $offering->priceOptions()->where('group_id', $group?->id);
        $query->delete();

        foreach ($options as $option) {
            $offering->priceOptions()->create([
                ...Arr::only($option, [
                    'enrollment_mode', 'unit', 'amount_cents', 'terms', 'is_available',
                    'cancellation_policy', 'payment_due',
                ]),
                'group_id' => $group?->id,
                'currency' => 'EUR',
            ]);
        }
    }
}
