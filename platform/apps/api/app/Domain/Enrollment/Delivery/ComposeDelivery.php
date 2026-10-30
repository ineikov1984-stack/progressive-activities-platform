<?php

namespace App\Domain\Enrollment\Delivery;

use App\Domain\Catalog\Models\OrganizationCatalogProfile;
use App\Domain\Enrollment\Enums\EnrollmentApplicationStatus;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\EnrollmentApplication;
use App\Domain\Enrollment\Models\EnrollmentEvent;
use App\Domain\Enrollment\Models\EnrollmentEventDelivery;
use App\Domain\Enrollment\Models\EnrollmentTransition;
use App\Domain\Enrollment\Services\EnrollmentCapacity;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\Organization;
use Carbon\CarbonImmutable;

/**
 * Builds one email in memory, inside the organization's read transaction: every record is read under RLS
 * (docs/product/enrollment-contacts-and-emails.md, т. 6.1 step 5). The reason is the transition's, never the
 * application's current text. Returns why it is skipped when there is nothing to send.
 */
final class ComposeDelivery
{
    public function __construct(
        private readonly EnrollmentMessages $messages,
        private readonly EnrollmentCapacity $capacity,
    ) {}

    /** @return array{to: string, mail: MailContent}|array{skip: string} */
    public function handle(EnrollmentEventDelivery $delivery): array
    {
        $event = EnrollmentEvent::query()->find($delivery->event_id);
        $application = $event?->application_id === null ? null : EnrollmentApplication::query()
            ->with(['child', 'parent', 'group.offering.activity', 'group.offering.location', 'group.location'])
            ->find($event->application_id);
        if ($event === null || $application === null) {
            return ['skip' => 'no_recipient'];
        }
        // An application that has moved on: only its latest decision is told (т. 6.2, "Заменени събития").
        if (str_starts_with($event->type, 'enrollment.application_')) {
            $told = $event->payload['status'] ?? null;
            if (is_string($told) && $application->status->value !== $told) {
                return ['skip' => 'superseded'];
            }
        }
        $organization = Organization::query()->find($application->organization_id);
        $profile = OrganizationCatalogProfile::query()->first();
        $name = trim((string) ($profile?->public_name ?: $organization?->name)) ?: 'Организацията';
        $to = $this->address($delivery, $application, $organization);
        if ($to === null) {
            return ['skip' => $delivery->recipient_kind === 'user' ? 'unverified_email' : 'no_recipient'];
        }
        $enrollment = $event->enrollment_id === null ? null : Enrollment::query()
            ->with(['group.offering.activity', 'group.offering.location', 'group.location', 'session', 'transferredFrom.group'])
            ->find($event->enrollment_id);
        if ($enrollment === null && $event->type === 'enrollment.application_approved') {
            $enrollment = Enrollment::query()->with(['group.offering.activity', 'group.offering.location', 'group.location', 'session'])
                ->where('application_id', $application->id)->first();
        }
        $platform = (string) config('platform.name');

        if ($delivery->audience === 'team') {
            $group = $enrollment?->group ?? $application->group;
            $today = CarbonImmutable::now('Europe/Sofia')->toDateString();
            $places = $group === null || $group->capacity_max === null ? 'Без ограничение на местата' : (function () use ($group, $today): string {
                $free = max(0, (int) $group->capacity_max - $this->capacity->placesTaken($group, $today));

                return $free === 0 ? "Групата е пълна · {$group->capacity_max} от {$group->capacity_max}"
                    : ($free === 1 ? '1 свободно място' : "{$free} свободни места")." от {$group->capacity_max}";
            })();

            return ['to' => $to, 'mail' => $this->messages->team($event, $application, $enrollment, [
                'organization' => $name,
                'organizationId' => $application->organization_id,
                'platform' => $platform,
                'webUrl' => (string) config('platform.web_url'),
                'recipientKind' => $delivery->recipient_kind,
                'placesText' => $places,
                'waitingInGroup' => $group === null ? 0 : EnrollmentApplication::query()->where('group_id', $group->id)
                    ->where('status', EnrollmentApplicationStatus::Waitlisted->value)->count(),
                'submittedHere' => EnrollmentApplication::query()->where('status', EnrollmentApplicationStatus::Submitted->value)->count(),
            ])];
        }

        $transitionId = $event->payload['transition_id'] ?? null;
        $transition = is_string($transitionId) ? EnrollmentTransition::query()->find($transitionId) : null;

        return ['to' => $to, 'mail' => $this->messages->parent($event, $application, $enrollment, [
            'organization' => $name,
            'platform' => $platform,
            'brand' => Branding::forOrganization($name, $profile, (string) config('app.url')),
            'replyTo' => $this->replyTo($application, $organization),
            'reason' => $transition?->public_reason,
            'byParent' => $transition !== null && $transition->actor_user_id === $application->parent_user_id,
        ])];
    }

    /** The address is read now, from the account or the contact field; a confirmed account address only. */
    private function address(EnrollmentEventDelivery $delivery, EnrollmentApplication $application, ?Organization $organization): ?string
    {
        $address = match ($delivery->recipient_kind) {
            'user' => (function () use ($delivery): ?string {
                $user = $delivery->recipient_user_id === null ? null : User::query()->find($delivery->recipient_user_id);

                return $user !== null && $user->is_active && $user->hasVerifiedEmail() ? $user->email : null;
            })(),
            'offering_contact' => $application->group?->offering?->contact_email,
            'organization_contact' => $delivery->recipient_key === 'organization_contact' ? $organization?->public_contact_email : null,
            default => null,
        };

        return is_string($address) && filter_var($address, FILTER_VALIDATE_EMAIL) !== false ? $address : null;
    }

    /** Answers go to the organization, never to the platform: the activity's contact address, else the organization's. */
    private function replyTo(EnrollmentApplication $application, ?Organization $organization): ?string
    {
        foreach ([$application->group?->offering?->contact_email, $organization?->public_contact_email] as $address) {
            if (is_string($address) && filter_var($address, FILTER_VALIDATE_EMAIL) !== false) {
                return $address;
            }
        }

        return null;
    }
}
