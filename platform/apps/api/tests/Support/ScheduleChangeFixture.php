<?php

namespace Tests\Support;

use App\Domain\Catalog\Models\Group;
use App\Domain\Catalog\Models\Session;
use App\Domain\Enrollment\Delivery\DeliverEvents;
use App\Domain\Enrollment\Delivery\MailSender;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\EnrollmentApplication;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\Organization;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Changes of the schedule in the tests (docs/product/schedule-changes.md): the body of a change, the change and its
 * preview as a person of the team, and the visits, applications and enrollments it touches. Built on TeamRightsFixture:
 * "Шах · Вторник" on 3, 10, 17 and 24 November at 17:00 in Sofia, Ани for the period, Вики for one visit on the 3rd.
 * Every name is synthetic.
 */
trait ScheduleChangeFixture
{
    use TeamRightsFixture;

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function changeBody(string $action, Session $session, int $version, array $extra = []): array
    {
        return [
            'action' => $action,
            'sessions' => [['id' => $session->id, 'version' => $version]],
            'reason' => $action === 'cancel' ? 'Залата е заета за родителска среща.' : null,
            'reason_to_parents' => false,
            ...$extra,
        ];
    }

    /** @param  array<string, mixed>  $body */
    private function applyChange(User $user, Organization $organization, array $body, ?string $key = null): TestResponse
    {
        return $this->signInTo($user, $organization)->withHeader('Idempotency-Key', $key ?? (string) Str::uuid())
            ->postJson('/api/v1/schedule/changes', $body);
    }

    /** @param  array<string, mixed>  $body */
    private function previewChange(User $user, Organization $organization, array $body): TestResponse
    {
        return $this->signInTo($user, $organization)->postJson('/api/v1/schedule/changes/preview', $body);
    }

    /**
     * The owner cancels or restores the session as it is now.
     *
     * @param  array<string, mixed>  $f
     * @param  array<string, mixed>  $extra
     */
    private function changeAsOwner(array $f, string $action, Session $session, array $extra = [], ?string $key = null): TestResponse
    {
        return $this->applyChange($f['owner'], $f['organization'], $this->changeBody($action, $session, $this->current($f, $session)->version, $extra), $key);
    }

    /** @param  array<string, mixed>  $f */
    private function current(array $f, Session $session): Session
    {
        return $this->inTenant($f['organization'], fn (): Session => Session::query()->with('instructors')->findOrFail($session->id));
    }

    /** @param  array<string, mixed>  $f */
    private function sessionOn(array $f, Group $group, string $day): Session
    {
        return $this->inTenant($f['organization'], fn (): Session => Session::query()->where('group_id', $group->id)
            ->where('starts_at', '>=', "{$day} 00:00:00")->where('starts_at', '<', "{$day} 23:59:59")->firstOrFail());
    }

    /**
     * A trial or a single visit on the session, from a price, approved.
     *
     * @param  array<string, mixed>  $f
     */
    private function visitOn(array $f, Session $session, string $firstName, string $scope = 'trial', ?User $parent = null, string $status = 'active'): Enrollment
    {
        return $this->inTenant($f['organization'], function () use ($f, $session, $firstName, $scope, $parent, $status): Enrollment {
            $parent ??= User::factory()->create(['name' => 'Родител '.$firstName]);
            $child = $parent->children()->create(['first_name' => $firstName, 'last_name' => 'Тестова', 'class_label' => '2 клас']);
            $application = $this->scheduleApplication($f, $parent, $child->id, (string) $session->group_id, $session, $scope, 'approved');

            return Enrollment::query()->create([
                'application_id' => $application->id, 'child_id' => $child->id, 'group_id' => $session->group_id,
                'price_option_id' => $f['price']->id, 'session_id' => $session->id, 'scope' => $scope, 'status' => $status,
                'starts_on' => $session->starts_at->setTimezone('Europe/Sofia')->toDateString(),
            ]);
        });
    }

    /**
     * A visit application waiting for the session.
     *
     * @param  array<string, mixed>  $f
     */
    private function waitingFor(array $f, Session $session, string $firstName, string $status = 'submitted', ?User $parent = null): EnrollmentApplication
    {
        return $this->inTenant($f['organization'], function () use ($f, $session, $firstName, $status, $parent): EnrollmentApplication {
            $parent ??= User::factory()->create(['name' => 'Родител '.$firstName]);
            $child = $parent->children()->create(['first_name' => $firstName, 'last_name' => 'Тестова']);

            return $this->scheduleApplication($f, $parent, $child->id, (string) $session->group_id, $session, 'trial', $status);
        });
    }

    /**
     * A child enrolled for the period in the group from a day.
     *
     * @param  array<string, mixed>  $f
     */
    private function enrolledIn(array $f, Group $group, string $firstName, string $startsOn, ?User $parent = null, ?string $notBefore = null): Enrollment
    {
        return $this->inTenant($f['organization'], function () use ($f, $group, $firstName, $startsOn, $parent, $notBefore): Enrollment {
            $parent ??= User::factory()->create(['name' => 'Родител '.$firstName]);
            $child = $parent->children()->create(['first_name' => $firstName, 'last_name' => 'Тестова', 'class_label' => '2 клас']);
            $application = $this->scheduleApplication($f, $parent, $child->id, $group->id, null, 'full_period', 'approved');

            return Enrollment::query()->create([
                'application_id' => $application->id, 'child_id' => $child->id, 'group_id' => $group->id,
                'price_option_id' => $f['price']->id, 'scope' => 'full_period', 'status' => 'active', 'starts_on' => $startsOn,
                'starts_not_before' => $notBefore,
            ]);
        });
    }

    /** @param  array<string, mixed>  $f */
    private function scheduleApplication(array $f, User $parent, string $childId, string $groupId, ?Session $session, string $scope, string $status): EnrollmentApplication
    {
        return EnrollmentApplication::query()->create([
            'parent_user_id' => $parent->id, 'child_id' => $childId, 'group_id' => $groupId, 'price_option_id' => $f['price']->id,
            'session_id' => $session?->id, 'scope' => $scope, 'source' => 'organization_catalog', 'status' => $status,
            'idempotency_key' => (string) Str::uuid(), 'accepted_terms_version' => 'v1', 'accepted_terms_at' => now(),
            'amount_cents_snapshot' => 1500, 'currency_snapshot' => 'EUR', 'unit_snapshot' => $scope === 'full_period' ? 'month' : 'session',
        ]);
    }

    /** Sends what is due, as the worker does every minute, and keeps it. */
    private function deliverLetters(): CapturingMailSender
    {
        $sender = new CapturingMailSender;
        $this->app->instance(MailSender::class, $sender);
        app(DeliverEvents::class)->run();

        return $sender;
    }
}
