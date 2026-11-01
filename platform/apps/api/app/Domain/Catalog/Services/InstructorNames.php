<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Organizations\Services\StaffNames;
use App\Domain\Organizations\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/** Team-only operational labels; public catalog presenters keep public_name. No cross-request cache. */
final readonly class InstructorNames
{
    public function __construct(private TenantContext $context, private StaffNames $staff) {}

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
        $profiles = DB::table('instructor_profiles')->where('organization_id', $this->context->organizationId())->whereIn('id', $ids)->get(['id', 'public_name', 'staff_member_id']);
        $staff = $this->staff->forIds($profiles->pluck('staff_member_id')->all());
        $names = [];
        foreach ($profiles as $p) {
            $names[(string) $p->id] = $p->staff_member_id !== null && isset($staff[$p->staff_member_id]) ? $staff[$p->staff_member_id] : (string) $p->public_name;
        }

        return $names;
    }
}
