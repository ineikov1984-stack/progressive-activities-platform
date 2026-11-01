<?php

namespace Tests\Feature\Enrollment;

use App\Domain\Catalog\Models\InstructorProfile;
use App\Domain\Catalog\Models\ScheduleChange;
use App\Domain\Catalog\Models\Session;
use App\Domain\Catalog\Services\TeacherRecipients;
use App\Domain\Catalog\ValueObjects\PlannedSessionChange;
use App\Domain\Enrollment\Delivery\Branding;
use App\Domain\Enrollment\Delivery\ScheduleLetters;
use App\Domain\Enrollment\Models\EnrollmentEvent;
use App\Domain\Enrollment\Models\EnrollmentEventDelivery;
use App\Domain\Enrollment\Services\ScheduleChangeEffects;
use App\Domain\Enrollment\ValueObjects\ScheduleEffects;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\StaffMember;
use App\Mail\EnrollmentMail;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CapturingMailSender;
use Tests\Support\TeamRightsFixture;
use Tests\TestCase;

class SubstituteAssignmentLettersTest extends TestCase
{
    use RefreshDatabase, TeamRightsFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-02 10:00:00');
    }

    public function test_assignment_effects_are_per_person_with_no_family_letters_and_private_data(): void
    {
        $f = $this->fixture();
        $this->inTenant($f['organization'], function () use ($f): void {
            $change = $this->substitute($f, $f['original']->id, $f['original']->id, $f['substitute']->id);
            $events = EnrollmentEvent::query()->get();
            $this->assertCount(2, $events);
            $this->assertSame(['schedule.teacher_sessions_changed'], $events->pluck('type')->unique()->values()->all());
            $assigned = $this->compose($f, $change, $f['substituteUser']);
            $this->assertStringStartsWith('Ще замествате Иван Петров:', $assigned['mail']->subject);
            $this->assertStringContainsString('Назначи ви', implode(' ', $assigned['mail']->paragraphs));
            $this->assertStringContainsString('4 ноември, 23:59', implode(' ', $assigned['mail']->paragraphs));
            $this->assertTrue($assigned['mail']->team);
            $this->assertNull($assigned['mail']->replyTo);
            $this->assertNull($assigned['mail']->quote);
            $this->assertSame(Branding::PLATFORM, $assigned['mail']->brand->accent);
            $rendered = new EnrollmentMail($assigned['mail'], 'substitute-test@platform.example');
            $this->assertSame([], $rendered->envelope()->replyTo);
            $body = CapturingMailSender::html($rendered).' '.CapturingMailSender::text($rendered);
            foreach (['Ани', 'Вики', 'Иванова', 'частна бележка'] as $private) {
                $this->assertStringNotContainsString($private, $body);
            }
            $this->assertStringStartsWith('Мария Колева ще ви замести:', $this->compose($f, $change, $f['originalUser'])['mail']->subject);
        });
    }

    public function test_two_replacements_before_delivery_send_only_latest_for_same_original(): void
    {
        $f = $this->fixture();
        $this->inTenant($f['organization'], function () use ($f): void {
            $first = $this->substitute($f, $f['original']->id, $f['original']->id, $f['substitute']->id);
            $second = $this->substitute($f, $f['original']->id, $f['substitute']->id, $f['other']->id);
            $this->assertSame(['skip' => 'superseded'], $this->compose($f, $first, $f['substituteUser']));
            $this->assertSame(['skip' => 'superseded'], $this->compose($f, $first, $f['originalUser']));
            $this->assertSame(['skip' => 'superseded'], $this->compose($f, $second, $f['substituteUser']));
            $this->assertStringStartsWith('Петър Стоянов ще ви замести:', $this->compose($f, $second, $f['originalUser'])['mail']->subject);
            $this->assertStringContainsString('Досега беше Мария Колева.', implode(' ', $this->compose($f, $second, $f['originalUser'])['mail']->paragraphs));
        });
    }

    public function test_sent_or_sending_assignment_allows_removed_and_sent_for_you_allows_session_back(): void
    {
        foreach (['sent', 'sending'] as $status) {
            $f = $this->fixture();
            $this->inTenant($f['organization'], function () use ($f, $status): void {
                $first = $this->substitute($f, $f['original']->id, $f['original']->id, $f['substitute']->id);
                $this->travel(1)->seconds();
                $this->markDelivery($f, $first, $f['substituteUser'], $status);
                $this->markDelivery($f, $first, $f['originalUser'], $status);
                $this->travel(1)->seconds();
                $back = $this->substitute($f, $f['original']->id, $f['substitute']->id, $f['original']->id);
                $this->assertStringStartsWith('Вече не замествате на 3 ноември:', $this->compose($f, $back, $f['substituteUser'])['mail']->subject);
                $this->assertStringContainsString('отново е ваше', $this->compose($f, $back, $f['originalUser'])['mail']->subject);
            });
        }
    }

    public function test_return_before_delivery_and_no_login_do_not_send_spurious_letters(): void
    {
        $f = $this->fixture();
        $this->inTenant($f['organization'], function () use ($f): void {
            $first = $this->substitute($f, $f['original']->id, $f['original']->id, $f['substitute']->id);
            $back = $this->substitute($f, $f['original']->id, $f['substitute']->id, $f['original']->id);
            $this->assertSame(['skip' => 'superseded'], $this->compose($f, $back, $f['originalUser']));
            $this->assertSame(['skip' => 'superseded'], $this->compose($f, $back, $f['substituteUser']));
            $person = StaffMember::query()->create(['full_name' => 'Без вход']);
            $profile = InstructorProfile::query()->create(['public_name' => 'Без вход']);
            $profile->forceFill(['staff_member_id' => $person->id])->save();
            $change = $this->substitute($f, $f['original']->id, $f['original']->id, $profile->id);
            $this->assertSame(1, EnrollmentEvent::query()->where('payload->schedule_change_id', $change->id)->count());
        });
    }

    public function test_releasing_original_and_releasing_substitute_tell_the_right_people(): void
    {
        $f = $this->fixture();
        $this->inTenant($f['organization'], function () use ($f): void {
            $f['session']->instructors()->attach($f['helper']->id, ['organization_id' => $f['organization']->id]);
            $release = $this->substitute($f, $f['original']->id, $f['original']->id, null);
            $this->assertStringStartsWith('Не е нужно да идвате на 3 ноември:', $this->compose($f, $release, $f['originalUser'])['mail']->subject);
            $this->assertStringStartsWith('Иван Петров няма да е на заниманието', $this->compose($f, $release, $f['helperUser'])['mail']->subject);
            $assigned = $this->substitute($f, $f['original']->id, null, $f['substitute']->id);
            $this->travel(1)->seconds();
            $this->markDelivery($f, $assigned, $f['substituteUser'], 'sent');
            $this->travel(1)->seconds();
            $release = $this->substitute($f, $f['original']->id, $f['substitute']->id, null);
            $this->assertStringStartsWith('Вече не замествате', $this->compose($f, $release, $f['substituteUser'])['mail']->subject);
            $this->assertStringStartsWith('Не е нужно да идвате', $this->compose($f, $release, $f['originalUser'])['mail']->subject);
        });
    }

    public function test_assignments_for_different_originals_in_same_session_do_not_suppress_each_other(): void
    {
        $f = $this->fixture();
        $this->inTenant($f['organization'], function () use ($f): void {
            $f['session']->instructors()->attach($f['helper']->id, ['organization_id' => $f['organization']->id]);
            $first = $this->substitute($f, $f['original']->id, $f['original']->id, $f['substitute']->id);
            $this->substitute($f, $f['helper']->id, $f['helper']->id, $f['other']->id);
            $this->assertStringStartsWith('Ще замествате Иван Петров:', $this->compose($f, $first, $f['substituteUser'])['mail']->subject);
            $this->assertStringStartsWith('Мария Колева ще ви замести:', $this->compose($f, $first, $f['originalUser'])['mail']->subject);
        });
    }

    public function test_normal_family_and_teacher_letters_survive_substitution_for_each_normal_action(): void
    {
        foreach (['move', 'add', 'change_location', 'restore'] as $action) {
            $f = $this->fixture();
            $this->inTenant($f['organization'], function () use ($f, $action): void {
                $normal = $this->normalChange($f, $action);
                $this->substitute($f, $f['original']->id, $f['original']->id, $f['substitute']->id);
                $parent = User::query()->findOrFail(DB::table('enrollment_applications')->where('id', $f['enrollment']->application_id)->value('parent_user_id'));
                $this->assertArrayHasKey('mail', $this->compose($f, $normal, $parent));
                $this->assertArrayHasKey('mail', $this->compose($f, $normal, $f['originalUser']));
            });
        }
    }

    public function test_substitute_assignment_survives_later_move_or_cancel_and_uses_current_time(): void
    {
        foreach (['move', 'cancel'] as $action) {
            $f = $this->fixture();
            $this->inTenant($f['organization'], function () use ($f, $action): void {
                $assignment = $this->substitute($f, $f['original']->id, $f['original']->id, $f['substitute']->id);
                $normal = $this->normalChange($f, $action);
                $assigned = $this->compose($f, $assignment, $f['substituteUser']);
                $this->assertStringStartsWith('Ще замествате Иван Петров:', $assigned['mail']->subject);
                $this->assertArrayHasKey('mail', $this->compose($f, $normal, $f['substituteUser']));
                if ($action === 'move') {
                    $this->assertStringContainsString('18:00', json_encode($assigned['mail']->facts, JSON_UNESCAPED_UNICODE));
                }
            });
        }
    }

    public function test_two_normal_moves_still_supersede_the_earlier_normal_letters(): void
    {
        $f = $this->fixture();
        $this->inTenant($f['organization'], function () use ($f): void {
            $first = $this->normalChange($f, 'move');
            $second = $this->normalChange($f, 'move');
            $this->assertSame(['skip' => 'superseded'], $this->compose($f, $first, $f['originalUser']));
            $this->assertArrayHasKey('mail', $this->compose($f, $second, $f['originalUser']));
        });
    }

    public function test_released_letter_names_current_remaining_colleague_and_allows_return_after_delivery(): void
    {
        $f = $this->fixture();
        $this->inTenant($f['organization'], function () use ($f): void {
            $f['session']->instructors()->attach($f['helper']->id, ['organization_id' => $f['organization']->id]);
            $release = $this->substitute($f, $f['original']->id, $f['original']->id, null);
            $this->assertStringContainsString('Заниманието ще води Елена Иванова.', implode(' ', $this->compose($f, $release, $f['originalUser'])['mail']->paragraphs));
            $this->travel(1)->seconds();
            $this->markDelivery($f, $release, $f['originalUser'], 'sent');
            $this->travel(1)->seconds();
            $back = $this->substitute($f, $f['original']->id, null, $f['original']->id);
            $this->assertStringContainsString('отново е ваше', $this->compose($f, $back, $f['originalUser'])['mail']->subject);
        });
    }

    public function test_recipient_must_still_own_the_explicit_teacher_identity(): void
    {
        $f = $this->fixture();
        $this->inTenant($f['organization'], function () use ($f): void {
            $change = $this->substitute($f, $f['original']->id, $f['original']->id, $f['substitute']->id);
            DB::table('staff_members')->where('id', $f['substitute']->staff_member_id)->update(['user_id' => null]);
            $this->assertSame(['skip' => 'superseded'], $this->compose($f, $change, $f['substituteUser']));
        });
    }

    public function test_bulk_assignment_queues_one_letter_per_person_and_limits_rendering_to_twenty_sessions(): void
    {
        $f = $this->fixture();
        $this->inTenant($f['organization'], function () use ($f): void {
            $change = ScheduleChange::query()->create(['action' => 'substitute', 'session_count' => 21, 'idempotency_key' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64), 'result' => [], 'actor_user_id' => $f['owner']->id]);
            $planned = [];
            for ($i = 0; $i < 21; $i++) {
                $before = $i === 0 ? $f['session'] : $f['tuesday']->sessions()->create(['starts_at' => $f['session']->starts_at->addDays($i), 'ends_at' => $f['session']->ends_at->addDays($i), 'status' => 'scheduled']);
                $before->instructors()->sync([$f['substitute']->id => ['organization_id' => $f['organization']->id, 'replaces_instructor_profile_id' => $f['original']->id]]);
                $after = clone $before;
                $planned[] = new PlannedSessionChange($before, $after, null, [$f['original']->id], [$f['substitute']->id], 'substituted', $f['original']->id, $f['original']->id, $f['substitute']->id);
                DB::table('session_changes')->insert(['id' => (string) Str::uuid7(), 'organization_id' => $f['organization']->id, 'schedule_change_id' => $change->id, 'session_id' => $before->id, 'from_status' => 'scheduled', 'to_status' => 'scheduled', 'to_starts_at' => $after->starts_at, 'to_ends_at' => $after->ends_at, 'replaced_profile_id' => $f['original']->id, 'removed_profile_id' => $f['original']->id, 'added_profile_id' => $f['substitute']->id, 'confirmed_overlaps' => '[]', 'created_at' => now()]);
            }
            app(ScheduleChangeEffects::class)->apply($f['owner'], $change->id, ScheduleEffects::none(), null, [], app(TeacherRecipients::class)->letters($planned), CarbonImmutable::now());
            $this->assertSame(2, EnrollmentEvent::query()->where('payload->schedule_change_id', $change->id)->count());
            $mail = $this->compose($f, $change, $f['substituteUser'])['mail'];
            $this->assertCount(20, array_filter($mail->facts, fn ($fact) => $fact['label'] === 'Занимание'));
            $this->assertContains('И още 1 занимания.', $mail->paragraphs);
        });
    }

    public function test_partial_batch_delivery_is_not_evidence_for_removed_or_returned_session(): void
    {
        foreach (['removed', 'back'] as $effect) {
            $f = $this->fixture();
            $this->inTenant($f['organization'], function () use ($f, $effect): void {
                [$batch, $otherSession] = $this->assignmentBatch($f);
                $this->travel(1)->seconds();
                $next = $this->substitute($f, $f['original']->id, $f['substitute']->id, $effect === 'removed' ? $f['other']->id : $f['original']->id);
                $this->travel(1)->seconds();
                $recipient = $effect === 'removed' ? $f['substituteUser'] : $f['originalUser'];
                $mail = $this->compose($f, $batch, $recipient)['mail'];
                $this->assertCount(1, array_filter($mail->facts, fn ($fact) => $fact['label'] === 'Занимание'));
                $this->assertStringContainsString('10 ноември', $mail->subject);
                $this->markDelivery($f, $batch, $recipient, 'sent');
                $this->travel(1)->seconds();
                $this->assertSame(['skip' => 'superseded'], $this->compose($f, $next, $recipient));
            });
        }
    }

    public function test_intermediate_same_original_change_prevents_old_partial_batch_evidence_resurfacing(): void
    {
        foreach (['removed', 'back'] as $effect) {
            $f = $this->fixture();
            $this->inTenant($f['organization'], function () use ($f, $effect): void {
                [$batch] = $this->assignmentBatch($f);
                $this->travel(1)->seconds();
                $this->substitute($f, $f['original']->id, $f['substitute']->id, $effect === 'removed' ? $f['other']->id : $f['original']->id);
                $this->travel(1)->seconds();
                $recipient = $effect === 'removed' ? $f['substituteUser'] : $f['originalUser'];
                $this->assertArrayHasKey('mail', $this->compose($f, $batch, $recipient));
                $this->markDelivery($f, $batch, $recipient, 'sent');
                $this->travel(1)->seconds();
                $this->substitute($f, $f['original']->id, $effect === 'removed' ? $f['other']->id : $f['original']->id, $effect === 'removed' ? $f['substitute']->id : $f['other']->id);
                $this->travel(1)->seconds();
                $last = $this->substitute($f, $f['original']->id, $effect === 'removed' ? $f['substitute']->id : $f['other']->id, $effect === 'removed' ? $f['other']->id : $f['original']->id);
                $this->assertSame(['skip' => 'superseded'], $this->compose($f, $last, $recipient));
            });
        }
    }

    public function test_equal_delivery_and_change_timestamps_are_not_sufficient_evidence(): void
    {
        $f = $this->fixture();
        $this->inTenant($f['organization'], function () use ($f): void {
            $first = $this->substitute($f, $f['original']->id, $f['original']->id, $f['substitute']->id);
            $this->markDelivery($f, $first, $f['substituteUser'], 'sent');
            $this->markDelivery($f, $first, $f['originalUser'], 'sending');
            $back = $this->substitute($f, $f['original']->id, $f['substitute']->id, $f['original']->id);
            $this->assertSame(['skip' => 'superseded'], $this->compose($f, $back, $f['substituteUser']));
            $this->assertSame(['skip' => 'superseded'], $this->compose($f, $back, $f['originalUser']));
        });
    }

    public function test_sent_assignment_for_another_absent_teacher_does_not_satisfy_removal_guard(): void
    {
        $f = $this->fixture();
        $this->inTenant($f['organization'], function () use ($f): void {
            $f['session']->instructors()->attach($f['helper']->id, ['organization_id' => $f['organization']->id]);
            $first = $this->substitute($f, $f['original']->id, $f['original']->id, $f['substitute']->id);
            $this->travel(1)->seconds();
            $this->markDelivery($f, $first, $f['substituteUser'], 'sent');
            $this->travel(1)->seconds();
            $this->substitute($f, $f['original']->id, $f['substitute']->id, $f['original']->id);
            $this->travel(1)->seconds();
            $this->substitute($f, $f['helper']->id, $f['helper']->id, $f['substitute']->id);
            $this->travel(1)->seconds();
            $last = $this->substitute($f, $f['helper']->id, $f['substitute']->id, $f['other']->id);
            $this->assertSame(['skip' => 'superseded'], $this->compose($f, $last, $f['substituteUser']));
        });
    }

    private function assignmentBatch(array $f): array
    {
        $second = Session::query()->where('group_id', $f['tuesday']->id)->where('id', '!=', $f['session']->id)->orderBy('starts_at')->firstOrFail();
        $change = ScheduleChange::query()->create(['action' => 'substitute', 'session_count' => 2, 'idempotency_key' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64), 'result' => [], 'actor_user_id' => $f['owner']->id]);
        $planned = [];
        foreach ([$f['session'], $second] as $session) {
            $session->instructors()->sync([$f['substitute']->id => ['organization_id' => $f['organization']->id, 'replaces_instructor_profile_id' => $f['original']->id]]);
            $planned[] = new PlannedSessionChange($session, clone $session, null, [$f['original']->id], [$f['substitute']->id], 'substituted', $f['original']->id, $f['original']->id, $f['substitute']->id);
            DB::table('session_changes')->insert(['id' => (string) Str::uuid7(), 'organization_id' => $f['organization']->id, 'schedule_change_id' => $change->id, 'session_id' => $session->id, 'from_status' => 'scheduled', 'to_status' => 'scheduled', 'to_starts_at' => $session->starts_at, 'to_ends_at' => $session->ends_at, 'replaced_profile_id' => $f['original']->id, 'removed_profile_id' => $f['original']->id, 'added_profile_id' => $f['substitute']->id, 'confirmed_overlaps' => '[]', 'created_at' => now()]);
        }
        app(ScheduleChangeEffects::class)->apply($f['owner'], $change->id, ScheduleEffects::none(), null, [], app(TeacherRecipients::class)->letters($planned), CarbonImmutable::now());

        return [$change, $second];
    }

    private function normalChange(array $f, string $action): ScheduleChange
    {
        $before = Session::query()->findOrFail($f['session']->id);
        $after = clone $before;
        if ($action === 'move') {
            $after->starts_at = $before->starts_at->addHour();
            $after->ends_at = $before->ends_at->addHour();
        }
        if ($action === 'cancel') {
            $after->status = 'cancelled';
        }
        $after->save();
        $change = $this->record($f, $action, $before, $after);
        $effect = match ($action) {
            'change_location' => 'location_changed', 'restore' => 'restored', 'cancel' => 'cancelled', 'add' => 'added', default => 'moved'
        };
        $teachers = $before->instructors()->pluck('instructor_profiles.id')->all();
        $byUser = [];
        foreach (app(TeacherRecipients::class)->users($teachers) as $user) {
            $byUser[$user] = [['session_id' => $before->id, 'effect' => $effect]];
        }
        app(ScheduleChangeEffects::class)->apply($f['owner'], $change->id, ScheduleEffects::none(), null, [], $byUser, CarbonImmutable::now());
        $parent = DB::table('enrollment_applications')->where('id', $f['enrollment']->application_id)->value('parent_user_id');
        EnrollmentEvent::query()->create(['type' => ScheduleChangeEffects::LETTER, 'payload' => ['schedule_change_id' => $change->id, 'parent_user_id' => $parent, 'items' => [['session_id' => $before->id, 'effect' => $effect, 'enrollment_id' => $f['enrollment']->id, 'application_id' => null]]], 'occurred_at' => now()]);

        return $change;
    }

    private function fixture(): array
    {
        $f = $this->rightsFixture();
        foreach (['original' => 'Иван Петров', 'substitute' => 'Мария Колева', 'other' => 'Петър Стоянов', 'helper' => 'Елена Иванова'] as $key => $name) {
            $user = $this->memberOf($f['organization'], 'instructor');
            $person = $this->personFor($f['organization'], $user, true);
            $profile = $this->profileOf($f['organization'], $person);
            $this->inTenant($f['organization'], fn () => $profile->update(['public_name' => $name]));
            $f[$key] = $profile;
            $f[$key.'User'] = $user;
        }
        $this->inTenant($f['organization'], fn () => $f['session']->instructors()->attach($f['original']->id, ['organization_id' => $f['organization']->id]));

        return $f;
    }

    private function substitute(array $f, string $original, ?string $removed, ?string $added): ScheduleChange
    {
        $before = Session::query()->findOrFail($f['session']->id);
        $profilesBefore = $before->instructors()->pluck('instructor_profiles.id')->all();
        if ($removed !== null) {
            $before->instructors()->detach($removed);
        }
        if ($added !== null) {
            $before->instructors()->attach($added, ['organization_id' => $f['organization']->id, 'replaces_instructor_profile_id' => $added === $original ? null : $original]);
        }
        $after = $before->fresh();
        $plan = new PlannedSessionChange($before, $after, null, $profilesBefore, $after->instructors()->pluck('instructor_profiles.id')->all(), 'substituted', $original, $removed, $added);
        $change = $this->record($f, 'substitute', $before, $after, $original, $removed, $added);
        app(ScheduleChangeEffects::class)->apply($f['owner'], $change->id, ScheduleEffects::none(), null, [], app(TeacherRecipients::class)->letters([$plan]), CarbonImmutable::now());

        return $change;
    }

    private function record(array $f, string $action, Session $before, Session $after, ?string $original = null, ?string $removed = null, ?string $added = null): ScheduleChange
    {
        $change = ScheduleChange::query()->create(['action' => $action, 'reason' => 'частна бележка', 'session_count' => 1, 'idempotency_key' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64), 'result' => [], 'actor_user_id' => $f['owner']->id]);
        DB::table('session_changes')->insert(['id' => (string) Str::uuid7(), 'organization_id' => $f['organization']->id, 'schedule_change_id' => $change->id, 'session_id' => $before->id, 'from_status' => $before->status, 'to_status' => $after->status, 'from_starts_at' => $before->starts_at, 'from_ends_at' => $before->ends_at, 'to_starts_at' => $after->starts_at, 'to_ends_at' => $after->ends_at, 'replaced_profile_id' => $original, 'removed_profile_id' => $removed, 'added_profile_id' => $added, 'confirmed_overlaps' => '[]', 'created_at' => now()]);

        return $change;
    }

    private function compose(array $f, ScheduleChange $change, User $user): array
    {
        $event = EnrollmentEvent::query()->where('payload->schedule_change_id', $change->id)->where(fn ($q) => $q->where('payload->user_id', $user->id)->orWhere('payload->parent_user_id', $user->id))->firstOrFail();
        $delivery = new EnrollmentEventDelivery(['recipient_user_id' => $user->id]);

        return app(ScheduleLetters::class)->handle($delivery, $event, ['organization' => 'Школа', 'organizationId' => $f['organization']->id, 'platform' => 'Платформа', 'webUrl' => 'https://platform.example', 'brand' => Branding::platform('Платформа'), 'replyTo' => 'info@school.example', 'profile' => null]);
    }

    private function markDelivery(array $f, ScheduleChange $change, User $user, string $status): void
    {
        $event = EnrollmentEvent::query()->where('payload->schedule_change_id', $change->id)->where(fn ($q) => $q->where('payload->user_id', $user->id)->orWhere('payload->parent_user_id', $user->id))->firstOrFail();
        EnrollmentEventDelivery::query()->create(['event_id' => $event->id, 'audience' => 'team', 'recipient_kind' => 'user', 'recipient_key' => 'user:'.$user->id, 'recipient_user_id' => $user->id, 'status' => $status, 'sent_at' => $status === 'sent' ? now() : null]);
    }
}
