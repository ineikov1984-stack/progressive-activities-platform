<?php

namespace App\Domain\Enrollment\Delivery;

use App\Domain\Enrollment\Models\EnrollmentApplication;
use App\Domain\Enrollment\Models\EnrollmentEvent;
use App\Domain\Enrollment\Models\EnrollmentTransition;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMembership;

/**
 * Who gets an email for an event (docs/product/enrollment-contacts-and-emails.md, т. 6.2–6.4). Called inside
 * the organization's transaction. Addresses are not stored: a recipient is a key ("user:<id>",
 * "offering_contact", "organization_contact") resolved again when the email is composed.
 */
final class DeliveryRecipients
{
    /** Events the parent hears about. */
    public const PARENT = [
        'enrollment.application_submitted', 'enrollment.application_approved', 'enrollment.application_waitlisted',
        'enrollment.application_rejected', 'enrollment.transferred', 'enrollment.paused', 'enrollment.pause_ended',
        'enrollment.ended', 'enrollment.cancelled', 'enrollment.reopened', 'enrollment.session_excluded',
        'enrollment.session_restored',
    ];

    /** Events the team always hears about. */
    public const TEAM = ['enrollment.application_submitted', 'enrollment.application_withdrawn'];

    /** Events the team hears about when the parent did it. */
    public const TEAM_IF_PARENT = ['enrollment.ended', 'enrollment.cancelled', 'enrollment.session_excluded', 'enrollment.session_restored'];

    /**
     * @return list<array{audience: string, recipient_kind: string, recipient_key: string, recipient_user_id: string|null, status: string, skipped_reason: string|null}>
     */
    public function for(EnrollmentEvent $event): array
    {
        $parentEmail = in_array($event->type, self::PARENT, true);
        $teamEmail = in_array($event->type, self::TEAM, true) || in_array($event->type, self::TEAM_IF_PARENT, true);
        if (! $parentEmail && ! $teamEmail) {
            return [];
        }
        $application = $event->application_id === null ? null
            : EnrollmentApplication::query()->with(['parent', 'group.offering'])->find($event->application_id);
        if ($application === null) {
            return [];
        }

        $rows = [];
        if ($parentEmail) {
            $rows[] = $this->parent($application);
        }
        if (in_array($event->type, self::TEAM, true)
            || (in_array($event->type, self::TEAM_IF_PARENT, true) && self::byParent($event, $application))) {
            array_push($rows, ...$this->team($application));
        }

        return $rows;
    }

    /** The step was the parent's own: the transition's actor is the application's parent (a withdrawal always is). */
    public static function byParent(EnrollmentEvent $event, EnrollmentApplication $application): bool
    {
        if ($event->type === 'enrollment.application_withdrawn') {
            return true;
        }
        $transitionId = $event->payload['transition_id'] ?? null;
        $actor = is_string($transitionId) ? EnrollmentTransition::query()->whereKey($transitionId)->value('actor_user_id') : null;

        return $actor !== null && $actor === $application->parent_user_id;
    }

    /** @return array{audience: string, recipient_kind: string, recipient_key: string, recipient_user_id: string|null, status: string, skipped_reason: string|null} */
    private function parent(EnrollmentApplication $application): array
    {
        $parent = $application->parent;
        // Only a confirmed address: an unconfirmed one may be someone else's.
        $verified = $parent instanceof User && $parent->hasVerifiedEmail();

        return [
            'audience' => 'parent', 'recipient_kind' => 'user', 'recipient_key' => 'user:'.$application->parent_user_id,
            'recipient_user_id' => $application->parent_user_id,
            'status' => $verified ? 'pending' : 'skipped', 'skipped_reason' => $verified ? null : 'unverified_email',
        ];
    }

    /**
     * The activity's contact address, else the organization's, else its owners and administrators.
     *
     * @return list<array{audience: string, recipient_kind: string, recipient_key: string, recipient_user_id: string|null, status: string, skipped_reason: string|null}>
     */
    private function team(EnrollmentApplication $application): array
    {
        $row = fn (string $kind, string $key, ?string $userId = null, ?string $skipped = null): array => [
            'audience' => 'team', 'recipient_kind' => $kind, 'recipient_key' => $key, 'recipient_user_id' => $userId,
            'status' => $skipped === null ? 'pending' : 'skipped', 'skipped_reason' => $skipped,
        ];
        if (filled($application->group?->offering?->contact_email)) {
            return [$row('offering_contact', 'offering_contact')];
        }
        if (filled(Organization::query()->whereKey($application->organization_id)->value('public_contact_email'))) {
            return [$row('organization_contact', 'organization_contact')];
        }
        $userIds = OrganizationMembership::query()->withoutGlobalScopes()
            ->where('organization_id', $application->organization_id)
            ->whereIn('role', ['organization_owner', 'organization_admin'])
            ->where('status', 'active')
            ->pluck('user_id');
        $users = User::query()->whereKey($userIds->all())->where('is_active', true)->whereNotNull('email_verified_at')->orderBy('id')->pluck('id');
        if ($users->isEmpty()) {
            return [$row('organization_contact', 'no_recipient', null, 'no_recipient')];
        }

        return array_values($users->map(fn (string $id): array => $row('user', 'user:'.$id, $id))->all());
    }
}
