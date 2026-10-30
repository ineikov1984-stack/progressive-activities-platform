<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\ValueObjects\AuditableReference;
use App\Domain\Catalog\Models\Activity;
use App\Domain\Catalog\Models\Group;
use App\Domain\Catalog\Models\InstructorProfile;
use App\Domain\Catalog\Services\GroupTeachers;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\StaffMember;
use App\Domain\Organizations\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Puts a teacher in a group as lead or assistant from a day to a day (docs/product/team.md, т. 5). Works in a published
 * group too: who teaches is an everyday change, not a change of the published activity. One row per group and person;
 * an ended assignment is opened again with the new period.
 */
final readonly class AssignGroupInstructor
{
    public function __construct(private TenantContext $context, private GroupTeachers $teachers, private AuditRecorder $audit) {}

    /** @param array<string, mixed> $payload */
    public function handle(User $actor, Group $group, array $payload): Group
    {
        abort_unless($group->organization_id === $this->context->organizationId(), 404);
        Gate::forUser($actor)->authorize('catalog.manage', Organization::query()->findOrFail($group->organization_id));
        $data = Validator::make($payload, [
            'instructor_profile_id' => ['required', 'uuid', Rule::exists('instructor_profiles', 'id')->where('organization_id', $group->organization_id)],
            'role' => ['sometimes', Rule::in(['lead', 'assistant'])],
            'starts_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'ends_on' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
        ], [
            'instructor_profile_id.exists' => 'Преподавателят не е намерен.',
            'ends_on.after_or_equal' => 'Краят трябва да е след началото.',
        ])->validate();

        return DB::transaction(function () use ($actor, $group, $data): Group {
            // The same order as the schedule editor: the activity, the group, then its sessions.
            Activity::query()->whereKey($group->offering()->value('activity_id'))->lockForUpdate()->firstOrFail();
            $group = Group::query()->whereKey($group->id)->lockForUpdate()->firstOrFail();
            $profile = InstructorProfile::query()->findOrFail($data['instructor_profile_id']);
            $person = $profile->getAttribute('staff_member_id') === null ? null : StaffMember::query()->find($profile->getAttribute('staff_member_id'));
            if ($person?->archived_at !== null) {
                throw ValidationException::withMessages(['instructor_profile_id' => 'Преподавателят е архивиран.']);
            }

            $today = CarbonImmutable::now('Europe/Sofia')->toDateString();
            $existing = $group->assignments()->where('instructor_profiles.id', $profile->id)->first();
            $values = [
                'role' => $data['role'] ?? 'lead',
                'starts_on' => $data['starts_on'] ?? null,
                'ends_on' => $data['ends_on'] ?? null,
                'created_by' => $actor->id,
            ];
            if ($existing !== null) {
                $current = GroupTeachers::assignment($existing);
                if ($current['ends_on'] === null || $current['ends_on'] >= $today) {
                    throw ValidationException::withMessages(['instructor_profile_id' => 'Този човек вече води групата.']);
                }
                $group->assignments()->updateExistingPivot($profile->id, [...$values, 'version' => $current['version'] + 1]);
            } else {
                $group->assignments()->attach($profile->id, [...$values, 'version' => 1]);
            }
            $this->teachers->apply($group);
            // An editor opened before this change saves with the old version and gets a conflict.
            $group->version++;
            $group->save();

            $this->audit->record(AuditAction::CatalogAssignmentAdded, new AuditableReference('group', $group->id), [
                'instructor_profile_id' => $profile->id, 'role' => $values['role'],
                'starts_on' => $values['starts_on'], 'ends_on' => $values['ends_on'],
            ]);

            return $group->refresh()->load(['assignments', 'sessions.instructors']);
        });
    }
}
