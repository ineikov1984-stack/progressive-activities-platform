<?php

namespace App\Domain\Organizations\Actions;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\ValueObjects\AuditableReference;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Enums\OrganizationStatus;
use App\Domain\Organizations\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final readonly class SubmitOrganizationApplication
{
    public function __construct(private AuditRecorder $auditRecorder) {}

    /** @throws ValidationException */
    public function execute(User $actor, Organization $organization, int $expectedVersion): Organization
    {
        Gate::forUser($actor)->authorize('submitApplication', $organization);

        if ($organization->status === OrganizationStatus::Review) {
            return $organization;
        }

        if ($organization->status !== OrganizationStatus::Applicant) {
            throw new ConflictHttpException('Организацията не може да бъде изпратена за преглед в текущия ѝ статус.');
        }

        Validator::make($organization->only([
            'organization_type',
            'registration_identifier',
            'public_contact_email',
        ]), [
            'organization_type' => ['required', 'string', 'max:48'],
            'registration_identifier' => ['required', 'string', 'max:64'],
            'public_contact_email' => ['required', 'email', 'max:254'],
        ])->validate();

        return DB::transaction(function () use ($organization, $expectedVersion): Organization {
            $updated = Organization::query()
                ->whereKey($organization->id)
                ->where('status', OrganizationStatus::Applicant->value)
                ->where('review_version', $expectedVersion)
                ->update([
                    'status' => OrganizationStatus::Review->value,
                    'review_version' => $expectedVersion + 1,
                    'submitted_at' => now(),
                    'public_review_reason' => null,
                    'next_action' => null,
                    'updated_at' => now(),
                ]);

            if ($updated !== 1) {
                $current = Organization::query()->findOrFail($organization->id);

                if ($current->status === OrganizationStatus::Review) {
                    return $current;
                }

                throw new ConflictHttpException('Заявлението е променено. Заредете актуалната версия и опитайте отново.');
            }

            $current = Organization::query()->findOrFail($organization->id);
            $this->auditRecorder->record(
                AuditAction::OrganizationApplicationSubmitted,
                new AuditableReference('organization', $current->id),
                ['review_version' => $current->review_version],
            );

            return $current;
        });
    }
}
