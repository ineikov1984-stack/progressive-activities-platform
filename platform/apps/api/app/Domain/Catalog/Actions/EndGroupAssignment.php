<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\ValueObjects\AuditableReference;
use App\Domain\Catalog\Models\Activity;
use App\Domain\Catalog\Models\Group;
use App\Domain\Catalog\Services\GroupTeachers;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Ends a teacher's assignment in a group on a day (docs/product/team.md, т. 5). The row stays; the teacher leaves the
 * group's sessions after that day unless they were changed by hand. Sessions that have started are not touched.
 */
final readonly class EndGroupAssignment
{
    public function __construct(private TenantContext $context, private GroupTeachers $teachers, private AuditRecorder $audit) {}

    /** @param array<string, mixed> $payload */
    public function handle(User $actor, Group $group, string $profileId, array $payload): Group
    {
        abort_unless($group->organization_id === $this->context->organizationId(), 404);
        Gate::forUser($actor)->authorize('catalog.manage', Organization::query()->findOrFail($group->organization_id));
        $data = Validator::make($payload, [
            'ends_on' => ['required', 'date_format:Y-m-d'],
            'version' => ['required', 'integer', 'min:1'],
        ])->validate();

        return DB::transaction(function () use ($group, $profileId, $data): Group {
            Activity::query()->whereKey($group->offering()->value('activity_id'))->lockForUpdate()->firstOrFail();
            $group = Group::query()->whereKey($group->id)->lockForUpdate()->firstOrFail();
            $assignment = $group->assignments()->where('instructor_profiles.id', $profileId)->first();
            abort_if($assignment === null, 404);
            abort_unless((int) $assignment->pivot->version === (int) $data['version'], 409, 'Assignment version conflict.');

            $today = CarbonImmutable::now('Europe/Sofia')->toDateString();
            $endsOn = $assignment->pivot->ends_on === null ? null : substr((string) $assignment->pivot->ends_on, 0, 10);
            if ($endsOn !== null && $endsOn < $today) {
                throw ValidationException::withMessages(['ends_on' => 'Назначението вече е приключило.']);
            }
            $startsOn = $assignment->pivot->starts_on === null ? null : substr((string) $assignment->pivot->starts_on, 0, 10);
            if ($startsOn !== null && $data['ends_on'] < $startsOn) {
                throw ValidationException::withMessages(['ends_on' => 'Краят трябва да е след началото.']);
            }

            $group->assignments()->updateExistingPivot($profileId, [
                'ends_on' => $data['ends_on'], 'version' => (int) $assignment->pivot->version + 1,
            ]);
            $this->teachers->apply($group);

            $this->audit->record(AuditAction::CatalogAssignmentEnded, new AuditableReference('group', $group->id), [
                'instructor_profile_id' => $profileId, 'ends_on' => $data['ends_on'],
            ]);

            return $group->refresh()->load(['assignments', 'sessions.instructors']);
        });
    }
}
