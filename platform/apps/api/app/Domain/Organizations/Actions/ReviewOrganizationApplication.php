<?php

namespace App\Domain\Organizations\Actions;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\ValueObjects\AuditableReference;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Enums\OrganizationStatus;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Organizations\Models\OrganizationReview;
use App\Domain\Organizations\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final readonly class ReviewOrganizationApplication
{
    public function __construct(
        private AuditRecorder $auditRecorder,
        private TenantContext $tenantContext,
    ) {}

    public function execute(
        User $operator,
        Organization $organization,
        OrganizationStatus $next,
        int $expectedVersion,
        ?string $publicReason,
        ?string $nextAction,
        ?string $internalNote,
    ): Organization {
        $isMember = OrganizationMembership::query()->withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->where('user_id', $operator->id)
            ->where('status', 'active')
            ->exists();

        if ($isMember) {
            throw new AuthorizationException('Не можете да разглеждате организация, в която членувате.');
        }

        Validator::make([
            'public_reason' => $publicReason,
            'next_action' => $nextAction,
        ], [
            'public_reason' => [
                Rule::requiredIf(in_array($next, [OrganizationStatus::Applicant, OrganizationStatus::Restricted, OrganizationStatus::Suspended], true)),
                'nullable', 'string', 'max:2000',
            ],
            'next_action' => [
                Rule::requiredIf(in_array($next, [OrganizationStatus::Applicant, OrganizationStatus::Restricted, OrganizationStatus::Suspended], true)),
                'nullable', 'string', 'max:2000',
            ],
        ])->validate();

        $previous = $organization->status;
        if (! $previous->canTransitionTo($next)) {
            throw new ConflictHttpException('Този преход между статуси не е позволен.');
        }

        return DB::transaction(function () use ($operator, $organization, $previous, $next, $expectedVersion, $publicReason, $nextAction, $internalNote): Organization {
            $updated = Organization::query()->withoutGlobalScopes()
                ->whereKey($organization->id)
                ->where('status', $previous->value)
                ->where('review_version', $expectedVersion)
                ->update([
                    'status' => $next->value,
                    'review_version' => $expectedVersion + 1,
                    'public_review_reason' => $publicReason,
                    'next_action' => $nextAction,
                    'reviewed_at' => now(),
                    'reviewed_by' => $operator->id,
                    'updated_at' => now(),
                ]);

            if ($updated !== 1) {
                throw new ConflictHttpException('Заявлението вече е променено.');
            }

            OrganizationReview::query()->withoutGlobalScopes()->create([
                'organization_id' => $organization->id,
                'operator_id' => $operator->id,
                'from_status' => $previous->value,
                'to_status' => $next->value,
                'review_version' => $expectedVersion + 1,
                'public_reason' => $publicReason,
                'next_action' => $nextAction,
                'internal_note' => $internalNote,
            ]);

            $this->tenantContext->select($organization);
            try {
                $this->auditRecorder->record(
                    AuditAction::OrganizationApplicationReviewed,
                    new AuditableReference('organization', $organization->id),
                    ['from' => $previous->value, 'to' => $next->value, 'review_version' => $expectedVersion + 1],
                );
            } finally {
                $this->tenantContext->clear();
            }

            return Organization::query()->withoutGlobalScopes()->findOrFail($organization->id);
        });
    }
}
