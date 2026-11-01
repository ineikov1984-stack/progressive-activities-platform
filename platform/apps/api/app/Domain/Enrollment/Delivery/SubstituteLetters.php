<?php

namespace App\Domain\Enrollment\Delivery;

use App\Domain\Catalog\Models\InstructorProfile;
use App\Domain\Catalog\Models\Session;
use App\Domain\Catalog\Models\SubstituteRequest;
use App\Domain\Catalog\Models\SubstituteRequestSession;
use App\Domain\Catalog\Services\InstructorNames;
use App\Domain\Catalog\Services\TeacherRecipients;
use App\Domain\Enrollment\Models\EnrollmentEvent;
use App\Domain\Enrollment\Models\EnrollmentEventDelivery;
use App\Domain\Enrollment\Services\TeamLetters;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Services\StaffRights;
use Illuminate\Database\Eloquent\Collection;

final class SubstituteLetters
{
    public function __construct(private readonly StaffRights $rights, private readonly InstructorNames $names) {}

    public function request(EnrollmentEvent $event): ?SubstituteRequest
    {
        $id = $event->payload['substitute_request_id'] ?? null;
        $request = is_string($id) ? SubstituteRequest::query()->find($id) : null;

        return $request !== null && $request->requester_user_id === ($event->payload['requester_user_id'] ?? null)
            && $request->closed_at === null ? $request : null;
    }

    /** @return Collection<int, Session> */
    public function sessions(SubstituteRequest $request): Collection
    {
        return Session::query()->with(['group.offering.activity', 'group.location', 'group.offering.location', 'location'])
            ->whereIn('id', SubstituteRequestSession::query()->where('request_id', $request->id)->where('status', 'open')->select('session_id'))
            ->where('status', 'scheduled')->where('starts_at', '>', now())
            ->whereHas('group', fn ($group) => $group->where('status', '!=', 'archived')
                ->whereHas('offering.activity', fn ($activity) => $activity->where('status', '!=', 'archived')))
            ->orderBy('starts_at')->orderBy('id')->get();
    }

    /** @return Collection<int, User> */
    public function recipients(SubstituteRequest $request): Collection
    {
        return User::query()->whereKey($this->rights->holdersEverywhere(['schedule.manage', 'team.assign']))
            ->where('id', '!=', $request->requester_user_id)->where('is_active', true)->orderBy('id')->get();
    }

    /** @return array{to: string, mail: MailContent}|array{skip: string} */
    public function handle(EnrollmentEventDelivery $delivery, EnrollmentEvent $event): array
    {
        if ($event->type === TeamLetters::DECLINED) {
            return $this->declined($delivery, $event);
        }
        $request = $this->request($event);
        $sessions = $request === null ? new Collection : $this->sessions($request);
        if ($request === null || $sessions->isEmpty()) {
            return ['skip' => 'superseded'];
        }
        $recipient = $this->recipients($request)->firstWhere('id', $delivery->recipient_user_id);
        if ($delivery->audience !== 'team' || $delivery->recipient_kind !== 'user' || $recipient === null
            || $delivery->recipient_key !== 'user:'.$recipient->id) {
            return ['skip' => 'no_recipient'];
        }
        if (! $recipient->hasVerifiedEmail() || filter_var($recipient->email, FILTER_VALIDATE_EMAIL) === false) {
            return ['skip' => 'unverified_email'];
        }
        $profiles = InstructorProfile::query()->whereIn('id', array_filter([$request->requester_profile_id, $request->suggested_profile_id]))->get()->keyBy('id');
        $names = $this->names->forIds($profiles->modelKeys());
        $requester = $profiles->get($request->requester_profile_id);
        $name = $requester instanceof InstructorProfile ? $names[$requester->id] : 'Преподавател';
        $first = $sessions->first();
        $last = $sessions->last();
        $zone = $first->group->schedule_pattern['timezone'] ?? 'Europe/Sofia';
        $from = $first->starts_at->setTimezone($zone)->toDateString();
        $to = $last->starts_at->setTimezone($zone)->toDateString();
        $when = $sessions->count() === 1 ? BulgarianDates::weekdayDay($from)
            : $sessions->count().' занимания, '.BulgarianDates::period($from, $to);
        $paragraphs = [$name.' няма да може да води:'];
        $facts = [];
        foreach ($sessions->take(20) as $session) {
            $group = $session->group;
            $facts[] = ['label' => 'Занимание', 'value' => $group->offering->activity->title.' · '.$group->name.' · '.BulgarianDates::session($session->starts_at, $session->ends_at, $group->schedule_pattern['timezone'] ?? 'Europe/Sofia')];
            $place = $session->location ?? $group->location ?? $group->offering->location;
            if ($place !== null) {
                $facts[] = ['label' => 'Място', 'value' => $place->name];
            }
        }
        if ($sessions->count() > 20) {
            $paragraphs[] = 'И още '.($sessions->count() - 20).' занимания — вижте заявката.';
        }
        $facts[] = ['label' => 'Причина', 'value' => match ($request->reason) {
            'vacation' => 'Отпуск', 'personal' => 'Лични причини', default => 'Друго',
        }];
        $suggested = $profiles->get($request->suggested_profile_id);
        if ($suggested !== null) {
            $facts[] = ['label' => 'Предлага', 'value' => $names[$suggested->id]];
        }
        if ($request->note !== null) {
            $paragraphs[] = 'Има бележка — вижте я в заявката.';
        }
        $organization = Organization::query()->find($event->organization_id);
        $platform = (string) config('platform.name');

        return ['to' => $recipient->email, 'mail' => new MailContent(
            team: true, fromName: $platform, replyTo: null,
            subject: $name.' търси заместник: '.$when, brand: Branding::platform($platform), greeting: null,
            heading: $name.' търси заместник', paragraphs: $paragraphs, facts: $facts, callout: null, quote: null,
            button: ['label' => 'Назначи заместник', 'url' => rtrim((string) config('platform.web_url'), '/').'/dashboard?substitute='.rawurlencode((string) $request->id).'&organization='.rawurlencode((string) $event->organization_id)],
            after: [], footer: 'Получавате това писмо, защото назначавате заместници в '.($organization instanceof Organization ? $organization->name : 'организацията').'.',
        )];
    }

    public function declinedRecipient(EnrollmentEvent $event): ?User
    {
        $id = $event->payload['user_id'] ?? null;
        $requestId = $event->payload['substitute_request_id'] ?? null;
        if (! is_string($id) || ! is_string($requestId) || ($event->payload['requester_user_id'] ?? null) !== $id
            || ! SubstituteRequest::query()->whereKey($requestId)->where('requester_user_id', $id)->exists()) {
            return null;
        }

        return User::query()->whereKey(TeacherRecipients::team((string) $event->organization_id, [$id]))->where('is_active', true)->first();
    }

    /** @return array{to:string,mail:MailContent}|array{skip:string} */
    private function declined(EnrollmentEventDelivery $delivery, EnrollmentEvent $event): array
    {
        $recipient = $this->declinedRecipient($event);
        if ($recipient === null || $delivery->audience !== 'team' || $delivery->recipient_kind !== 'user'
            || $delivery->recipient_user_id !== $recipient->id || $delivery->recipient_key !== 'user:'.$recipient->id) {
            return ['skip' => 'no_recipient'];
        }
        if (! $recipient->hasVerifiedEmail() || filter_var($recipient->email, FILTER_VALIDATE_EMAIL) === false) {
            return ['skip' => 'unverified_email'];
        }
        $ids = $event->payload['session_ids'] ?? [];
        if (! is_array($ids)) {
            return ['skip' => 'no_recipient'];
        }
        $items = SubstituteRequestSession::query()->where('request_id', $event->payload['substitute_request_id'])
            ->whereIn('session_id', $ids)->where('status', 'declined')->get();
        $sessions = Session::query()->whereIn('id', $items->pluck('session_id'))
            ->with(['group.offering.activity', 'group.location', 'group.offering.location', 'location'])->orderBy('starts_at')->orderBy('id')->get();
        if ($sessions->isEmpty()) {
            return ['skip' => 'no_recipient'];
        }
        $deciders = User::query()->whereIn('id', $items->pluck('decided_by_user_id')->filter())->get()->keyBy('id');
        $paragraphs = [];
        $facts = [];
        foreach ($sessions->take(20) as $session) {
            $item = $items->firstWhere('session_id', $session->id);
            $decider = $deciders->get($item->decided_by_user_id);
            $label = $session->group->offering->activity->title.' · '.$session->group->name;
            $when = BulgarianDates::session($session->starts_at, $session->ends_at, 'Europe/Sofia');
            $paragraphs[] = 'Заниманието '.$label.' · '.$when.' остава ваше. Ако все пак няма да можете, свържете се с '.($decider->name ?? 'офиса').'.';
            $facts[] = ['label' => 'Занимание', 'value' => $label.' · '.$when];
        }
        if ($sessions->count() > 20) {
            $paragraphs[] = 'И още '.($sessions->count() - 20).' занимания — вижте в „Моят график“.';
        }
        $from = $sessions->first()->starts_at->setTimezone('Europe/Sofia')->toDateString();
        $to = $sessions->last()->starts_at->setTimezone('Europe/Sofia')->toDateString();
        $platform = (string) config('platform.name');

        return ['to' => $recipient->email, 'mail' => new MailContent(
            team: true, fromName: $platform, replyTo: null, subject: 'Заявката за заместник за '.BulgarianDates::period($from, $to).' не е одобрена',
            brand: Branding::platform($platform), greeting: null, heading: 'Заявката за заместник не е одобрена',
            paragraphs: $paragraphs, facts: $facts, callout: null, quote: null,
            button: ['label' => 'Моят график', 'url' => rtrim((string) config('platform.web_url'), '/').'/dashboard/schedule?organization='.rawurlencode((string) $event->organization_id)],
            after: [], footer: 'Получавате това писмо по вашата заявка за заместник.',
        )];
    }
}
