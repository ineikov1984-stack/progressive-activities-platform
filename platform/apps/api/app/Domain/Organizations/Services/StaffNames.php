<?php

namespace App\Domain\Organizations\Services;

use App\Domain\Organizations\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/** Internal team identity only. Never returns contacts or reads another organization's records. */
final readonly class StaffNames
{
    public function __construct(private TenantContext $context) {}

    /**
     * @param  array<array-key,mixed>  $ids
     * @return array<string,string>
     */
    public function forIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, is_string(...))));
        if ($ids === []) {
            return [];
        }
        $names = [];
        foreach (DB::table('staff_members')->where('organization_id', $this->context->organizationId())->whereIn('id', $ids)->get(['id', 'full_name']) as $row) {
            $names[(string) $row->id] = (string) $row->full_name;
        }

        return $names;
    }
}
