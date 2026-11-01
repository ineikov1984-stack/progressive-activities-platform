<?php

namespace App\Domain\Enrollment\Delivery;

use App\Domain\Catalog\Models\InstructorProfile;
use App\Domain\Catalog\Models\OrganizationCatalogProfile;
use App\Domain\Catalog\Models\ScheduleChange;
use App\Domain\Catalog\Models\Session;
use App\Domain\Catalog\Services\TeacherRecipients;
use App\Domain\Enrollment\Models\EnrollmentEvent;
use App\Domain\Enrollment\Models\EnrollmentEventDelivery;
use App\Domain\Enrollment\Services\EnrollmentCapacity;
use App\Domain\Enrollment\Services\ScheduleChangeEffects;
use App\Domain\Identity\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Team-only assignment letters: immutable identity, current time/place, no children's names or private notes. */
final readonly class SubstituteAssignmentLetters
{
    private const EFFECTS = ['substitute_assigned', 'substitute_for_you', 'substitute_removed', 'session_back', 'released', 'colleague_away'];

    public function __construct(private TeacherRecipients $recipients, private EnrollmentCapacity $capacity) {}

    /**
     * @param  list<array{session_id: string, effect: string, enrollment_id: string|null, application_id: string|null, replaced_profile_id: string|null, profile_id: string|null}>  $items
     * @param  array{organization: string, organizationId: string, platform: string, webUrl: string, brand: Branding, replyTo: string|null, profile: OrganizationCatalogProfile|null}  $context
     * @return array{to: string, mail: MailContent}|array{skip: string}
     */
    public function handle(ScheduleChange $change, array $items, User $teacher, string $address, array $context): array
    {
        $org = $context['organizationId'];
        $sessionIds = array_values(array_unique(array_column($items, 'session_id')));
        $sessions = Session::query()->where('organization_id', $org)->whereIn('id', $sessionIds)->with(['group.offering.activity', 'group.offering.location', 'group.location', 'location'])->get()->keyBy('id');
        $history = DB::table('session_changes')->where('organization_id', $org)->where('schedule_change_id', $change->id)->whereIn('session_id', $sessionIds)->get()->keyBy('session_id');
        $newer = DB::table('session_changes as s')->join('schedule_changes as c', 'c.id', '=', 's.schedule_change_id')->where('s.organization_id', $org)->where('c.organization_id', $org)->where('c.action', 'substitute')->where('c.id', '>', $change->id)->whereIn('s.session_id', $sessionIds)->get(['s.session_id', 's.replaced_profile_id']);
        $on = DB::table('activity_session_instructors')->where('organization_id', $org)->whereIn('session_id', $sessionIds)->get();
        $profiles = InstructorProfile::query()->where('organization_id', $org)->whereIn('id', array_values(array_unique(array_filter([...$history->pluck('replaced_profile_id'), ...$history->pluck('removed_profile_id'), ...$history->pluck('added_profile_id'), ...array_column($items, 'profile_id'), ...$on->pluck('instructor_profile_id')]))))->get(['id', 'public_name'])->keyBy('id');
        $users = $this->recipients->users(array_values(array_filter(array_column($items, 'profile_id'))));
        $previous = $this->heard($org, $change, $teacher, $sessionIds);
        $lines = [];
        foreach ($items as $item) {
            $session = $sessions->get($item['session_id']);
            $h = $history->get($item['session_id']);
            $effect = $item['effect'];
            $profile = $item['profile_id'];
            if ($session === null || $h === null || ! in_array($effect, self::EFFECTS, true) || $profile === null || ($users[$profile] ?? null) !== (string) $teacher->id
                || $h->replaced_profile_id !== $item['replaced_profile_id']
                || $newer->contains(fn ($row) => $row->session_id === $session->id && $row->replaced_profile_id === $h->replaced_profile_id)) {
                continue;
            }
            $valid = match ($effect) {
                'substitute_assigned' => $h->added_profile_id === $profile && $profile !== $h->replaced_profile_id,
                'substitute_for_you' => $h->replaced_profile_id === $profile && $h->added_profile_id !== null && $h->added_profile_id !== $profile,
                'substitute_removed' => $h->removed_profile_id === $profile && $profile !== $h->replaced_profile_id && in_array('substitute_assigned', $previous[$session->id.'|'.$h->replaced_profile_id.'|'.$profile] ?? [], true),
                'session_back' => $h->replaced_profile_id === $profile && $h->added_profile_id === $profile && count(array_intersect(['substitute_for_you', 'released'], $previous[$session->id.'|'.$h->replaced_profile_id.'|'.$profile] ?? [])) > 0,
                'released' => $h->replaced_profile_id === $profile && $h->added_profile_id === null,
                default => $h->added_profile_id === null && $on->contains(fn ($row) => $row->session_id === $session->id && $row->instructor_profile_id === $profile),
            };
            if ($valid) {
                $lines[] = ['session' => $session, 'history' => $h, 'effect' => $effect];
            }
        }
        if ($lines === []) {
            return ['skip' => 'superseded'];
        }
        usort($lines, fn ($a, $b) => [$a['session']->starts_at->getTimestamp(), $a['session']->id] <=> [$b['session']->starts_at->getTimestamp(), $b['session']->id]);
        $actor = User::query()->find($change->actor_user_id)->name ?? 'Екипът';
        $children = $this->capacity->takenForSessions(new Collection(array_map(function ($line): Session {
            $session = clone $line['session'];
            $session->status = 'scheduled';

            return $session;
        }, $lines)));
        $name = fn (?string $id): string => $id === null ? '' : ($profiles->get($id)->public_name ?? 'колега');
        $paragraphs = [];
        $facts = [];
        $subjects = [];
        foreach (array_slice($lines, 0, 20) as $line) {
            $s = $line['session'];
            $h = $line['history'];
            $effect = $line['effect'];
            $title = $s->group->offering->activity->title.' · '.$s->group->name;
            $start = $s->starts_at->setTimezone('Europe/Sofia');
            $day = BulgarianDates::day($start->toDateString());
            $moment = BulgarianDates::weekdayDay($start->toDateString());
            $original = $name($h->replaced_profile_id);
            $subjects[] = match ($effect) {
                'substitute_assigned' => "Ще замествате {$original}: {$title}, {$moment}",
                'substitute_for_you' => $name($h->added_profile_id)." ще ви замести: {$title}, {$moment}",
                'substitute_removed' => "Вече не замествате на {$day}: {$title}",
                'session_back' => "Заниманието {$title} на {$day} отново е ваше",
                'released' => "Не е нужно да идвате на {$day}: {$title}",
                default => "{$original} няма да е на заниманието {$title} на {$day}",
            };
            $paragraphs[] = match ($effect) {
                'substitute_assigned' => "Назначи ви {$actor}.",
                'substitute_for_you' => "Назначи {$actor}.",
                'substitute_removed' => "{$actor} смени заместника.",
                'session_back' => "Заниманието {$title} на {$day} отново е ваше.",
                'released' => 'Заниманието ще води '.implode(', ', $on->where('session_id', $s->id)->map(fn ($t) => $name($t->instructor_profile_id))->all()).". Реши {$actor}.",
                default => "Заниманието ще се проведе без {$original}. Реши {$actor}.",
            };
            if ($effect === 'substitute_for_you' && $h->removed_profile_id !== null && $h->removed_profile_id !== $h->replaced_profile_id) {
                $paragraphs[] = 'Досега беше '.$name($h->removed_profile_id).'.';
            }
            $facts[] = ['label' => 'Занимание', 'value' => $title.' · '.BulgarianDates::session($s->starts_at, $s->ends_at, 'Europe/Sofia')];
            $location = $s->location ?? $s->group->location ?? $s->group->offering->location;
            if (! in_array($effect, ['substitute_removed'], true) && $location !== null) {
                $facts[] = ['label' => 'Място', 'value' => $location->name];
            }
            if (in_array($effect, ['substitute_assigned', 'session_back', 'colleague_away'], true)) {
                $facts[] = ['label' => 'Деца', 'value' => (string) ($children[$s->id] ?? 0)];
            }
            if ($effect === 'substitute_assigned') {
                $paragraphs[] = 'На '.$day.' и до '.BulgarianDates::day($start->addDay()->toDateString()).', 23:59, ще виждате децата в групата — име и клас.';
            }
        }
        if (count($lines) > 20) {
            $paragraphs[] = 'И още '.(count($lines) - 20).' занимания.';
        }
        $subject = count($lines) > 1 ? 'Промени във вашите занимания: '.count($lines) : $subjects[0];
        $firstName = trim((string) strtok(trim($teacher->name), ' '));

        return ['to' => $address, 'mail' => new MailContent(team: true, fromName: $context['platform'], replyTo: null, subject: $subject,
            brand: Branding::platform($context['platform']), greeting: $firstName === '' ? 'Здравейте,' : "Здравейте, {$firstName},", heading: $subject,
            paragraphs: $paragraphs, facts: $facts, callout: null, quote: null,
            button: ['label' => 'Моят график', 'url' => rtrim($context['webUrl'], '/').'/dashboard/schedule?organization='.rawurlencode($org)], after: [],
            footer: "Получавате това писмо за вашето занимание в {$context['organization']}. Изпратено чрез {$context['platform']}.")];
    }

    /**
     * @param  list<string>  $sessionIds
     * @return array<string, list<string>> session|original|recipient profile => effects the teacher could have heard
     */
    private function heard(string $org, ScheduleChange $change, User $teacher, array $sessionIds): array
    {
        $events = EnrollmentEvent::query()->where('organization_id', $org)->where('type', ScheduleChangeEffects::TEACHER_LETTER)->where('payload->user_id', $teacher->id)->where('payload->schedule_change_id', '<', $change->id)->get(['id', 'payload']);
        $deliveries = EnrollmentEventDelivery::query()->where('organization_id', $org)->whereIn('event_id', $events->modelKeys())->where('recipient_user_id', $teacher->id)->where('audience', 'team')->whereIn('status', ['sent', 'sending'])->get(['event_id', 'status', 'sent_at', 'updated_at'])->keyBy('event_id');
        $changes = DB::table('session_changes as s')->join('schedule_changes as c', 'c.id', '=', 's.schedule_change_id')
            ->where('s.organization_id', $org)->where('c.organization_id', $org)->where('c.action', 'substitute')
            ->whereIn('s.session_id', $sessionIds)->where('c.id', '<=', $change->id)
            ->get(['s.session_id', 's.replaced_profile_id', 'c.id', 'c.created_at']);
        $heard = [];
        foreach ($events as $event) {
            $delivery = $deliveries->get($event->id);
            $deliveredAt = $delivery === null ? null : ($delivery->status === 'sent' ? $delivery->sent_at : $delivery->updated_at);
            $previousChangeId = $event->payload['schedule_change_id'] ?? null;
            // A batch status does not prove every payload line was rendered. Refuse equal/ambiguous timing and
            // any line superseded before delivery; this is deliberately conservative without per-line receipts.
            if ($deliveredAt === null || ! is_string($previousChangeId) || ! $deliveredAt->lessThan($change->created_at)) {
                continue;
            }
            foreach ($event->payload['items'] ?? [] as $item) {
                if (! is_array($item) || ! is_string($item['session_id'] ?? null) || ! is_string($item['effect'] ?? null)
                    || ! is_string($item['replaced_profile_id'] ?? null) || ! is_string($item['profile_id'] ?? null)) {
                    continue;
                }
                $superseded = $changes->contains(fn ($later) => $later->session_id === $item['session_id']
                    && $later->replaced_profile_id === $item['replaced_profile_id'] && $later->id > $previousChangeId
                    && CarbonImmutable::parse($later->created_at, 'UTC')->lessThanOrEqualTo($deliveredAt));
                if (! $superseded) {
                    $heard[$item['session_id'].'|'.$item['replaced_profile_id'].'|'.$item['profile_id']][] = $item['effect'];
                }
            }
        }

        return $heard;
    }
}
