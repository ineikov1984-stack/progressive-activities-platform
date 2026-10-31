<?php

namespace App\Domain\Enrollment\Services;

use App\Domain\Enrollment\Enums\EnrollmentScope;
use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Models\Child;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The children enrolled in some groups, by name — the choices of the filter "Дете" in the schedule
 * (docs/product/schedule.md, т. 3.5). Enrolled means an enrollment that is active, paused or ended, not cancelled and
 * not only an application, that holds today, begins later or ended no more than 90 days ago: for the period its last
 * day, for a trial or a single visit the day of the visit. The `children` table has no RLS: the children are found only through the
 * organization's enrollments (the tenant scope of `Enrollment` and the RLS of `enrollments`), and only in the groups
 * given — never where else a child goes. Name and class only: never the groups, contacts, health or money.
 */
final readonly class EnrolledChildSearch
{
    public const LIMIT = 20;

    /** How long a child stays in the search after the enrollment ended — the schedule looks back a few weeks. */
    private const RECENT_DAYS = 90;

    private const TIMEZONE = 'Europe/Sofia';

    /**
     * Up to 20 children whose name holds the text, in any case, by name.
     *
     * @param  list<string>|null  $groupIds  the groups to look in; null — every group of the organization
     * @return list<array{id: string, name: string, class: string|null}>
     */
    public function find(string $text, ?array $groupIds): array
    {
        $text = trim($text);
        if ($text === '' || $groupIds === []) {
            return [];
        }
        $since = CarbonImmutable::now(self::TIMEZONE)->subDays(self::RECENT_DAYS)->toDateString();
        // "%" and "_" are letters of the text, not wildcards.
        $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $text).'%';
        // Case-insensitive search for Cyrillic names needs ILIKE on PostgreSQL; SQLite (tests) has only LIKE.
        $operator = DB::getDriverName() === 'pgsql' ? 'ilike' : 'like';

        $children = Child::query()
            ->whereHas('enrollments', function ($enrollment) use ($groupIds, $since): void {
                $enrollment->whereIn('status', [EnrollmentStatus::Active->value, EnrollmentStatus::Paused->value, EnrollmentStatus::Ended->value])
                    ->where(fn ($recent) => $recent
                        ->where(fn ($period) => $period->where('scope', EnrollmentScope::FullPeriod->value)
                            ->where(fn ($open) => $open->whereNull('ends_on')->orWhereDate('ends_on', '>=', $since)))
                        ->orWhere(fn ($visit) => $visit->whereIn('scope', [EnrollmentScope::Trial->value, EnrollmentScope::Single->value])
                            ->whereDate('starts_on', '>=', $since)));
                if ($groupIds !== null) {
                    $enrollment->whereIn('group_id', $groupIds);
                }
            })
            ->whereRaw("(children.first_name || ' ' || coalesce(children.last_name, '')) {$operator} ? escape '!'", [$pattern])
            ->orderBy('children.first_name')
            ->orderBy('children.last_name')
            ->orderBy('children.id')
            ->limit(self::LIMIT)
            ->get(['children.id', 'children.first_name', 'children.last_name', 'children.class_label']);

        $rows = [];
        foreach ($children as $child) {
            $rows[] = [
                'id' => (string) $child->id,
                'name' => trim($child->first_name.' '.$child->last_name),
                'class' => $child->class_label,
            ];
        }

        return $rows;
    }
}
