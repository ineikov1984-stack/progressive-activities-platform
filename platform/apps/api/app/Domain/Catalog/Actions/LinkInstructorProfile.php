<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\ValueObjects\AuditableReference;
use App\Domain\Catalog\Models\InstructorProfile;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\StaffMember;
use App\Domain\Organizations\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Gives a person of the team the teacher profile the parents see (docs/product/team.md, т. 3, 9): an existing profile
 * without a person, or a new one with the person's name that is not public until it is filled in.
 */
final readonly class LinkInstructorProfile
{
    public function __construct(private TenantContext $context, private AuditRecorder $audit) {}

    public function handle(User $actor, StaffMember $person, ?string $profileId): InstructorProfile
    {
        abort_unless($person->getAttribute('organization_id') === $this->context->organizationId(), 404);
        Gate::forUser($actor)->authorize('manageTeam', Organization::query()->findOrFail($this->context->organizationId()));

        return DB::transaction(function () use ($person, $profileId): InstructorProfile {
            $person = StaffMember::query()->whereKey($person->id)->lockForUpdate()->firstOrFail();
            if ($person->archived_at !== null) {
                throw ValidationException::withMessages(['member' => 'Човекът е архивиран.']);
            }
            $current = InstructorProfile::query()->where('staff_member_id', $person->id)->first();
            if ($profileId === null && $current !== null) {
                return $current;
            }
            if ($current !== null && $current->id !== $profileId) {
                throw ValidationException::withMessages(['instructor_profile_id' => 'Човекът вече има профил на преподавател.']);
            }

            if ($profileId === null) {
                $profile = InstructorProfile::query()->create(['public_name' => $person->full_name, 'is_public' => false]);
            } else {
                $profile = InstructorProfile::query()->lockForUpdate()->find($profileId)
                    ?? throw ValidationException::withMessages(['instructor_profile_id' => 'Профилът не е намерен.']);
                if ($profile->getAttribute('staff_member_id') !== null && $profile->getAttribute('staff_member_id') !== $person->id) {
                    throw ValidationException::withMessages(['instructor_profile_id' => 'Профилът е свързан с друг човек.']);
                }
                if ($profile->getAttribute('staff_member_id') === $person->id) {
                    return $profile;
                }
            }
            $profile->forceFill(['staff_member_id' => $person->id])->save();

            $this->audit->record(AuditAction::TeamProfileLinked, new AuditableReference('staff_member', $person->id), [
                'instructor_profile_id' => $profile->id, 'created' => $profileId === null,
            ]);

            return $profile;
        });
    }
}
