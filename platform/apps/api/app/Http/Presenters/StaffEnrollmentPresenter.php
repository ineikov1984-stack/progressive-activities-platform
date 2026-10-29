<?php

namespace App\Http\Presenters;

use App\Domain\Enrollment\Models\Child;
use App\Domain\Enrollment\Models\EnrollmentApplication;

/**
 * How applications and children look to the organization's team (docs/product/staff-enrollment.md, Б1).
 * Never contact details: those are only in the details, for those who may see them.
 */
final class StaffEnrollmentPresenter
{
    /**
     * @param  bool  $withParentName  the parent's name, only for those who see contacts (docs/product/enrollment-contacts-and-emails.md, т. 3.2)
     * @return array<string, mixed>
     */
    public static function application(EnrollmentApplication $application, ?int $placesTaken = null, bool $withParentName = false): array
    {
        $activity = $application->group?->offering?->activity;

        return [
            'id' => $application->id,
            'status' => $application->status->value,
            'version' => $application->version,
            'scope' => $application->scope->value,
            'source' => $application->source->value,
            'child' => self::child($application->child),
            'group' => ['id' => $application->group_id, 'name' => $application->group?->name] + ($placesTaken === null ? [] : [
                'capacity_max' => $application->group?->capacity_max, 'places_taken' => $placesTaken,
            ]),
            'activity' => ['id' => $activity?->id, 'title' => $activity?->title],
            'session_starts_at' => $application->session?->starts_at?->toIso8601String(),
            'visit_offer_title' => $application->visit_offer_title_snapshot,
            'price' => [
                'amount_cents' => $application->amount_cents_snapshot,
                'currency' => $application->currency_snapshot,
                'unit' => $application->unit_snapshot,
            ],
            'public_reason' => $application->public_reason,
            'submitted_at' => $application->created_at?->toIso8601String(),
        ] + ($withParentName ? ['parent_name' => $application->parent?->name] : []);
    }

    /** @return array{id: string|null, name: string|null, class_label: string|null} */
    public static function child(?Child $child): array
    {
        return [
            'id' => $child?->id,
            'name' => $child === null ? null : trim($child->first_name.' '.$child->last_name),
            'class_label' => $child?->class_label,
        ];
    }
}
