<?php

namespace App\Domain\Enrollment\Delivery;

use App\Domain\Catalog\Enums\ActivityStatus;
use App\Domain\Catalog\Enums\VisitOfferStatus;
use App\Domain\Catalog\Models\Group;
use App\Domain\Catalog\Models\Location;
use App\Domain\Catalog\Models\OrganizationCatalogProfile;
use App\Domain\Catalog\Models\ScheduleChange;
use App\Domain\Catalog\Models\Session;
use App\Domain\Catalog\Models\SessionChange;
use App\Domain\Catalog\Services\TeacherRecipients;
use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\EnrollmentApplication;
use App\Domain\Enrollment\Models\EnrollmentEvent;
use App\Domain\Enrollment\Models\EnrollmentEventDelivery;
use App\Domain\Enrollment\Models\EnrollmentTransition;
use App\Domain\Enrollment\Services\EnrollmentCapacity;
use App\Domain\Enrollment\Services\ScheduleChangeEffects;
use App\Domain\Enrollment\Services\SessionApplicability;
use App\Domain\Identity\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The letters of a change of the schedule (docs/product/schedule-changes.md, т. 6): one to a family for everything the
 * change does to its children, one to a teacher for their sessions. The event says what was; the letter says what is:
 * every line is checked again here, under RLS, and drops out when it no longer holds — another parent, an enrollment
 * that no longer counts, a newer change of the session. An invitation for another day (a visit the cancellation ended,
 * an application it refused) never drops out for a later change: it tells the session's state now. A family that never
 * heard of the cancellation hears "ще се проведе" of the session that came back; one that did, "все пак ще се
 * проведе". The first name of the child only — never a surname, class, note or sum; the reason only when it goes to the
 * parents; a teacher reads how many children, never who.
 *
 * @phpstan-type Line array{effect: string, session: Session, enrollment: Enrollment|null, application: EnrollmentApplication}
 * @phpstan-type Item array{session_id: string, effect: string, enrollment_id: string|null, application_id: string|null}
 */
final class ScheduleLetters
{
    public const TYPES = [ScheduleChangeEffects::LETTER, ScheduleChangeEffects::TEACHER_LETTER];

    /** Up to 20 lines, then "и още N" (т. 6.3). */
    private const MAX_LINES = 20;

    /** Up to five free days in an invitation, from the next 60 (т. 5.5). */
    private const FREE_DAYS = 5;

    private const SEARCH_DAYS = 60;

    /** The order of a family's lines: what asks for an answer first. */
    private const ORDER = ['visit_cancelled' => 0, 'application_rejected' => 1, 'cancelled' => 2, 'restored' => 3, 'visit_day_free_again' => 4];

    public function __construct(
        private readonly SessionApplicability $applicability,
        private readonly EnrollmentCapacity $capacity,
    ) {}

    /**
     * @param  array{organization: string, organizationId: string, platform: string, webUrl: string, brand: Branding, replyTo: string|null, profile: OrganizationCatalogProfile|null}  $context
     * @return array{to: string, mail: MailContent}|array{skip: string}
     */
    public function handle(EnrollmentEventDelivery $delivery, EnrollmentEvent $event, array $context): array
    {
        $payload = $event->payload;
        $changeId = $payload['schedule_change_id'] ?? null;
        $change = is_string($changeId) ? ScheduleChange::query()->find($changeId) : null;
        $items = self::items($payload['items'] ?? null);
        $named = $payload[$event->type === ScheduleChangeEffects::LETTER ? 'parent_user_id' : 'user_id'] ?? null;
        if ($change === null || $items === [] || ! is_string($named) || $named !== $delivery->recipient_user_id) {
            return ['skip' => 'no_recipient'];
        }
        $recipient = User::query()->find($named);
        $address = $recipient !== null && $recipient->is_active && $recipient->hasVerifiedEmail() ? $recipient->email : null;
        if ($recipient === null || ! is_string($address) || filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            return ['skip' => 'unverified_email'];
        }
        if ($event->type === ScheduleChangeEffects::TEACHER_LETTER) {
            // Still a person of the team here, or nothing.
            return TeacherRecipients::team($context['organizationId'], [(string) $recipient->id]) !== []
                ? $this->teacher($change, $items, $recipient, $address, $context) : ['skip' => 'no_recipient'];
        }

        return $this->family($change, $items, $recipient, $address, $context);
    }

    /**
     * @param  list<Item>  $items
     * @param  array{organization: string, organizationId: string, platform: string, webUrl: string, brand: Branding, replyTo: string|null, profile: OrganizationCatalogProfile|null}  $context
     * @return array{to: string, mail: MailContent}|array{skip: string}
     */
    private function family(ScheduleChange $change, array $items, User $parent, string $address, array $context): array
    {
        $parentId = (string) $parent->id;
        $sessions = $this->sessions($items);
        $enrollments = Enrollment::query()->with(['application', 'child', 'group', 'sessionExceptions', 'pauses'])
            ->whereKey(array_values(array_filter(array_column($items, 'enrollment_id'))))->get()->keyBy('id');
        $applications = EnrollmentApplication::query()->with(['child', 'visitOffer'])
            ->whereKey(array_values(array_filter(array_column($items, 'application_id'))))->get()->keyBy('id');
        $newer = $this->newer($change, $sessions);

        /** @var list<Line> $lines */
        $lines = [];
        foreach ($items as $item) {
            $session = $sessions->get($item['session_id']);
            $enrollment = $item['enrollment_id'] === null ? null : $enrollments->get($item['enrollment_id']);
            $application = $enrollment instanceof Enrollment ? $enrollment->application
                : ($item['application_id'] === null ? null : $applications->get($item['application_id']));
            // The family is the application's parent, now as then.
            if (! $session instanceof Session || ! $application instanceof EnrollmentApplication || (string) $application->parent_user_id !== $parentId) {
                continue;
            }
            $effect = $item['effect'];
            $lasting = in_array($effect, ['visit_cancelled', 'application_rejected'], true);
            if (! $lasting && in_array((string) $session->id, $newer, true)) {
                continue;
            }
            $enrollment = $enrollment instanceof Enrollment ? $enrollment : null;
            $holds = match ($effect) {
                'cancelled' => $enrollment !== null && $this->wouldCome($enrollment, $session),
                'moved', 'moved_skip_dropped' => $enrollment !== null
                    ? ($enrollment->scope->value === 'full_period' || $enrollment->status === EnrollmentStatus::Active)
                        && $this->applicability->appliesTo($enrollment, $session)
                    : in_array($application->status->value, ['submitted', 'waitlisted'], true) && $session->status === 'scheduled',
                'moved_not_attending' => $enrollment !== null && $this->cameBefore($change, $enrollment, $session)
                    && ! $this->applicability->appliesTo($enrollment, $session),
                'restored' => $enrollment !== null && $this->applicability->appliesTo($enrollment, $session),
                'visit_day_free_again' => $this->invitationSent($change, $session, $enrollment, $application, $parentId),
                default => $lasting,
            };
            if ($holds) {
                $lines[] = ['effect' => $effect, 'session' => $session, 'enrollment' => $enrollment, 'application' => $application];
            }
        }
        if ($lines === []) {
            return ['skip' => 'superseded'];
        }
        usort($lines, fn (array $a, array $b): int => [self::ORDER[$a['effect']] ?? 9, $a['session']->starts_at->getTimestamp(), self::child($a)]
            <=> [self::ORDER[$b['effect']] ?? 9, $b['session']->starts_at->getTimestamp(), self::child($b)]);

        $org = $context['organization'];
        $replyTo = self::replyTo($lines, $context['replyTo']);
        $several = count(array_unique(array_map(fn (array $line): string => self::child($line), $lines))) > 1;
        $paragraphs = [];
        $facts = [];
        $button = null;
        $invites = false;
        $freeDays = false;
        foreach (array_slice($lines, 0, self::MAX_LINES) as $line) {
            $session = $line['session'];
            $group = $session->group;
            $child = self::child($line);
            $title = self::title($group);
            $moment = self::moment($session);
            $day = self::day($session);
            $kind = $line['application']->scope->value === 'trial' ? 'пробно занимание' : 'еднократно посещение';
            $back = $session->status === 'scheduled';
            switch ($line['effect']) {
                case 'moved':
                case 'moved_skip_dropped':
                case 'moved_not_attending':
                    $old = $this->before($change, $session);
                    $paragraphs[] = "Заниманието на {$child} ".self::in($title).' се мести от '.self::moment($old).' на '.self::moment($session).'.';
                    $facts[] = self::fact('Преди', self::sessionText($old), $context['webUrl']);
                    if ($session->original_starts_at !== null && ! $session->original_starts_at->equalTo($old->starts_at)) {
                        $original = $session->original_starts_at->setTimezone(self::timezone($session));
                        $facts[] = self::fact('По график', BulgarianDates::weekdayDay($original->toDateString()).', '.$original->format('H:i'), $context['webUrl']);
                    }
                    $facts[] = self::fact('Сега', self::sessionText($session), $context['webUrl']);
                    if ($line['effect'] === 'moved_skip_dropped') {
                        $paragraphs[] = "Бяхте ни предупредили, че {$child} няма да дойде на ".self::day($old).'. Новият ден е друг — ако и тогава няма да може, предупредете ни отново.';
                    } elseif ($line['effect'] === 'moved_not_attending') {
                        $paragraphs[] = "Новият ден е извън дните за посещение на {$child} — за {$child} заниманието отпада.";
                    }
                    if ($line['application']->scope->value !== 'full_period') {
                        $paragraphs[] = $replyTo === null
                            ? 'Новият ден не ви е удобен? Свържете се с екипа по телефона — отказът е без такса.'
                            : 'Новият ден не ви е удобен? Отговорете на този имейл — отказът е без такса.';
                    }
                    $place = self::place($session, $change->action === 'move');
                    if ($place !== null) {
                        $facts[] = self::fact('Място', $place['text'], $context['webUrl'], $place['hint']);
                        $button ??= $place['url'] === null ? null : ['label' => 'Как да стигнете', 'url' => $place['url']];
                    }
                    break;
                case 'cancelled':
                    $paragraphs[] = "Заниманието на {$child} ".self::in($title).' '.self::in($moment).', няма да се проведе.';
                    $next = $line['enrollment'] === null ? null : $this->next($line['enrollment'], $session);
                    if ($next !== null) {
                        $facts[] = self::fact($several ? "Следващо занимание на {$child}" : 'Следващо занимание', self::sessionText($next), $context['webUrl']);
                    }
                    break;
                case 'restored':
                    $what = $session->extra ? 'Допълнителното занимание' : 'Заниманието';
                    $paragraphs[] = "{$what} ".self::in($title).' '.self::in($moment).($this->heard($change, $session, $line['enrollment'], $parentId)
                        ? ", няма да бъде отменено. Очакваме {$child}." : ", ще се проведе. Очакваме {$child}.");
                    $facts[] = self::fact('Занимание', self::sessionText($session), $context['webUrl']);
                    $place = self::place($session, $change->action === 'move');
                    if ($place !== null) {
                        $facts[] = self::fact('Място', $place['text'], $context['webUrl'], $place['hint']);
                        $button ??= $place['url'] === null ? null : ['label' => 'Как да стигнете', 'url' => $place['url']];
                    }
                    break;
                case 'visit_cancelled':
                case 'application_rejected':
                    $invites = true;
                    $visit = $kind === 'пробно занимание' ? 'Пробното занимание' : 'Еднократното посещение';
                    $paragraphs[] = match (true) {
                        $line['effect'] === 'visit_cancelled' && ! $back => "{$visit} на {$child} по ".self::activity($group).' '.self::in($moment).', няма да се проведе. Изберете друг ден:',
                        $line['effect'] === 'visit_cancelled' => "Заниманието на {$day} беше отменено и после върнато. {$visit} на {$child} остава отменено — изберете ден, и {$day}, ако ви е удобен:",
                        ! $back => "Заявката за {$kind} на {$day} не може да бъде одобрена — заниманието е отменено. Изберете друг ден:",
                        default => "Заниманието на {$day} беше отменено и после върнато. Заявката за {$kind} на {$child} остава отказана — изберете ден, и {$day}, ако ви е удобен:",
                    };
                    foreach ($this->freeDays($line['application'], $session) as $free) {
                        $freeDays = true;
                        $facts[] = self::fact('Свободен ден', $free, $context['webUrl']);
                    }
                    $page = $this->publicPage($group, $context);
                    $button = $page === null ? $button : ['label' => 'Виж свободните дни', 'url' => $page];
                    break;
                default:
                    $paragraphs[] = "Заниманието на {$day} ще се проведе. Денят отново е свободен. Ако искате {$child} да дойде тогава и има място, запишете се отново. Ако вече сте избрали друг ден, не е нужно нищо.";
                    $page = $this->publicPage($group, $context);
                    $button ??= $page === null ? null : ['label' => 'Виж свободните дни', 'url' => $page];
            }
        }
        if (count($lines) > self::MAX_LINES) {
            $more = count($lines) - self::MAX_LINES;
            $paragraphs[] = 'И още '.$more.' '.($more === 1 ? 'занимание' : 'занимания').'.';
        }

        $first = $lines[0];
        $subject = $this->subject($change, $first, $parentId);
        // How to answer (т. 6.3); without an address to answer to, one line to call instead.
        $after = [];
        if ($replyTo === null) {
            $after[] = "Не отговаряйте на този имейл — свържете се с {$org} по телефона.";
        } else {
            if ($invites) {
                $after[] = $freeDays ? 'Отговорете на този имейл с деня, който ви е удобен.' : 'Отговорете на този имейл и екипът ще ви предложи друг ден.';
            }
            $after[] = "Имате въпрос? Отговорете на този имейл — ще го получи екипът на {$org}.";
        }
        $reason = $change->reason_to_parents ? trim((string) $change->reason) : '';
        $names = array_values(array_unique(array_map(fn (array $line): string => self::child($line), $lines)));

        return ['to' => $address, 'mail' => new MailContent(
            team: false,
            fromName: "{$org} чрез {$context['platform']}",
            replyTo: $replyTo,
            subject: $subject,
            brand: $context['brand'],
            greeting: self::greeting($parent),
            heading: $subject,
            paragraphs: $paragraphs,
            facts: $facts,
            callout: null,
            quote: $reason === '' ? null : ['label' => 'Обяснение от екипа', 'text' => '„'.$reason.'“'],
            button: $button,
            after: $after,
            footer: 'Получавате това писмо, защото подадохте заявка за '.implode(', ', $names)." в {$org}. Изпратено чрез {$context['platform']}.",
        )];
    }

    /**
     * @param  list<Item>  $items
     * @param  array{organization: string, organizationId: string, platform: string, webUrl: string, brand: Branding, replyTo: string|null, profile: OrganizationCatalogProfile|null}  $context
     * @return array{to: string, mail: MailContent}|array{skip: string}
     */
    private function teacher(ScheduleChange $change, array $items, User $teacher, string $address, array $context): array
    {
        $sessions = $this->sessions($items);
        $newer = $this->newer($change, $sessions);
        $lines = [];
        foreach ($items as $item) {
            $session = $sessions->get($item['session_id']);
            // A cancelled session with a newer change drops out; one back always reaches the teacher (т. 6.1).
            if (! $session instanceof Session || ($item['effect'] !== 'restored' && in_array((string) $session->id, $newer, true))) {
                continue;
            }
            $lines[] = ['effect' => $item['effect'], 'session' => $session];
        }
        if ($lines === []) {
            return ['skip' => 'superseded'];
        }
        usort($lines, fn (array $a, array $b): int => $a['session']->starts_at->getTimestamp() <=> $b['session']->starts_at->getTimestamp());
        // How many children, never who.
        $children = $this->capacity->takenForSessions(new Collection(array_map(function (array $line): Session {
            $planned = clone $line['session'];
            $planned->status = 'scheduled';

            return $planned;
        }, $lines)));

        $paragraphs = [];
        $facts = [];
        foreach (array_slice($lines, 0, self::MAX_LINES) as $line) {
            $session = $line['session'];
            $title = self::title($session->group);
            if ($line['effect'] === 'moved') {
                $old = $this->before($change, $session);
                $paragraphs[] = "Заниманието {$title} се мести от ".self::moment($old).' на '.self::moment($session).'.';
                $facts[] = self::fact('Преди', self::sessionText($old), $context['webUrl']);
            } else {
                $paragraphs[] = "Заниманието {$title} ".self::in(self::moment($session))
                .($line['effect'] === 'cancelled' ? ', няма да се проведе.' : ', ще се проведе.');
            }
            $facts[] = self::fact('Занимание', self::sessionText($session), $context['webUrl']);
            $place = self::place($session, $change->action === 'move');
            if ($place !== null) {
                $facts[] = self::fact('Място', $place['text'], $context['webUrl']);
            }
            $count = $children[(string) $session->id] ?? 0;
            $facts[] = self::fact('Деца', (string) $count, $context['webUrl']);
        }
        if (count($lines) > self::MAX_LINES) {
            $paragraphs[] = 'И още '.(count($lines) - self::MAX_LINES).' занимания.';
        }
        $first = $lines[0];
        $title = self::title($first['session']->group);
        $subject = count($lines) > 1 ? 'Промени във вашите занимания: '.count($lines)
            : "Вашето занимание {$title} на ".self::day($first['session']).match ($first['effect']) {
                'cancelled' => ' е отменено', 'moved' => ' се мести', default => ' ще се проведе'
            };
        $reason = $change->reason_to_parents ? trim((string) $change->reason) : '';

        return ['to' => $address, 'mail' => new MailContent(
            team: true,
            fromName: $context['platform'],
            replyTo: null,
            subject: $subject,
            brand: Branding::platform($context['platform']),
            greeting: self::greeting($teacher),
            heading: $subject,
            paragraphs: $paragraphs,
            facts: $facts,
            callout: null,
            quote: $reason === '' ? null : ['label' => 'Обяснение от екипа', 'text' => '„'.$reason.'“'],
            button: ['label' => 'Моят график', 'url' => rtrim($context['webUrl'], '/').'/dashboard/schedule?organization='.rawurlencode($context['organizationId'])],
            after: [],
            footer: "Получавате това писмо, защото водите това занимание в {$context['organization']}. Изпратено чрез {$context['platform']}.",
        )];
    }

    /**
     * @param  Line  $first
     */
    private function subject(ScheduleChange $change, array $first, string $parentId): string
    {
        $session = $first['session'];
        $child = self::child($first);
        $day = self::day($session);
        $activity = self::activity($session->group);
        $back = $session->status === 'scheduled';
        $trial = $first['application']->scope->value === 'trial';

        return match ($first['effect']) {
            'moved', 'moved_skip_dropped', 'moved_not_attending' => "Заниманието по {$activity} на {$day} се мести",
            'visit_cancelled' => ($trial ? 'Пробното занимание' : 'Еднократното посещение')." на {$child} на {$day} ".($back ? 'е отменено — изберете ден' : 'се отменя — изберете друг ден'),
            'application_rejected' => 'Заявката за '.($trial ? 'пробно занимание' : 'еднократно посещение')." на {$child} на {$day} — изберете друг ден",
            'cancelled' => "Заниманието по {$activity} на {$day} няма да се проведе",
            'restored' => $this->heard($change, $session, $first['enrollment'], $parentId)
                ? "Заниманието на {$day} все пак ще се проведе"
                : ($session->extra ? 'Допълнително занимание' : 'Заниманието')." по {$activity}: ".self::moment($session),
            default => "Заниманието на {$day} ще се проведе — денят отново е свободен",
        };
    }

    /**
     * Answers go to the organization, never to the platform (enrollment-contacts-and-emails.md, т. 6.5): the activity's
     * contact address when every line of the letter is of that one activity, else the organization's; null — neither.
     *
     * @param  list<Line>  $lines
     */
    private static function replyTo(array $lines, ?string $organization): ?string
    {
        $offerings = array_unique(array_map(fn (array $line): string => (string) $line['session']->group?->offering_id, $lines));
        $contact = count($offerings) === 1 ? $lines[0]['session']->group?->offering?->contact_email : null;

        return is_string($contact) && filter_var($contact, FILTER_VALIDATE_EMAIL) !== false ? $contact : $organization;
    }

    /**
     * @param  list<Item>  $items
     * @return Collection<string, Session>
     */
    private function sessions(array $items): Collection
    {
        return Session::query()->with(['group.offering.activity', 'group.offering.location', 'group.location', 'location'])
            ->whereKey(array_values(array_unique(array_column($items, 'session_id'))))->get()
            ->keyBy(fn (Session $session): string => (string) $session->id)->toBase();
    }

    /**
     * The sessions changed again after this change: their lines are old news (т. 6.4).
     *
     * @param  Collection<string, Session>  $sessions
     * @return list<string>
     */
    private function newer(ScheduleChange $change, Collection $sessions): array
    {
        return SessionChange::query()->whereIn('session_id', $sessions->keys()->all())->where('schedule_change_id', '>', $change->id)
            ->pluck('session_id')->map(fn (mixed $id): string => (string) $id)->unique()->values()->all();
    }

    /** The old date and time of this change, from its immutable history, never the first move's time. */
    private function before(ScheduleChange $change, Session $session): Session
    {
        $history = SessionChange::query()->where('schedule_change_id', $change->id)->where('session_id', $session->id)->firstOrFail();
        $before = clone $session;
        $before->starts_at = $history->from_starts_at;
        $before->ends_at = $history->from_ends_at;

        return $before;
    }

    /** A "no longer attending" line still applies to this enrollment and its old day. */
    private function cameBefore(ScheduleChange $change, Enrollment $enrollment, Session $session): bool
    {
        return $this->wouldCome($enrollment, $this->before($change, $session));
    }

    /**
     * Would the child have come, had the session not been cancelled: the enrollment runs on its day, no pause, no skip.
     * The start counts from the day itself — cancelling a first session moves the start on (т. 5.4).
     */
    private function wouldCome(Enrollment $enrollment, Session $session): bool
    {
        if ($enrollment->status === EnrollmentStatus::Cancelled) {
            return false;
        }
        $day = $session->starts_at->setTimezone(SessionApplicability::timezone($enrollment))->toDateString();
        $planned = clone $session;
        $planned->status = 'scheduled';
        $probe = clone $enrollment;
        $probe->starts_on = CarbonImmutable::parse(min($day, $enrollment->starts_on->toDateString()));

        return $this->applicability->appliesTo($probe, $planned);
    }

    /** The next session that counts for the child after this one. */
    private function next(Enrollment $enrollment, Session $session): ?Session
    {
        return $this->applicability->sessionsFor($enrollment)->first(fn (Session $candidate): bool => (string) $candidate->id !== (string) $session->id
            && $candidate->starts_at->greaterThan($session->starts_at) && $candidate->starts_at->isFuture());
    }

    /**
     * The family heard that the session was cancelled: the letter of its latest cancellation before this change is sent
     * or being sent (т. 6.4). A session cancelled in the draft's editor has no such letter.
     */
    private function heard(ScheduleChange $change, Session $session, ?Enrollment $enrollment, string $parentId): bool
    {
        $cancellation = SessionChange::query()->where('session_id', $session->id)->where('to_status', 'cancelled')
            ->where('schedule_change_id', '<', $change->id)->orderByDesc('schedule_change_id')->value('schedule_change_id');

        return is_string($cancellation) && $this->letterSent($cancellation, $parentId, (string) $session->id, ['cancelled'], $enrollment?->id);
    }

    /**
     * The invitation of the cancellation that ended this visit or refused this application went before the session came
     * back: then "the day is free again" goes. Otherwise the invitation is still to go, or went out after, and says
     * itself that the session is back (т. 5.5, 6.4) — the family gets exactly one of the two.
     */
    private function invitationSent(ScheduleChange $change, Session $session, ?Enrollment $enrollment, EnrollmentApplication $application, string $parentId): bool
    {
        $metadata = EnrollmentTransition::query()
            ->when($enrollment !== null,
                fn ($query) => $query->where('enrollment_id', $enrollment?->id),
                fn ($query) => $query->where('application_id', $application->id)->whereNull('enrollment_id'))
            ->whereIn('metadata->effect', ['visit_cancelled', 'application_rejected'])
            ->where('metadata->session_id', (string) $session->id)
            ->orderByDesc('created_at')->orderByDesc('id')->first(['metadata'])?->metadata;
        $cancellation = $metadata['schedule_change_id'] ?? null;

        return is_string($cancellation) && $this->letterSent($cancellation, $parentId, (string) $session->id,
            ['visit_cancelled', 'application_rejected'], $enrollment->id ?? $application->id, $change->created_at);
    }

    /**
     * A letter of that change to this family about this session (and this enrollment or application) is sent or being
     * sent — and, when asked, went before a moment.
     *
     * @param  list<string>  $effects
     */
    private function letterSent(string $changeId, string $parentId, string $sessionId, array $effects, ?string $recordId, ?CarbonImmutable $before = null): bool
    {
        $events = EnrollmentEvent::query()->where('type', ScheduleChangeEffects::LETTER)
            ->where('payload->schedule_change_id', $changeId)->where('payload->parent_user_id', $parentId)->get(['id', 'payload']);
        $ids = [];
        foreach ($events as $event) {
            foreach (self::items($event->payload['items'] ?? null) as $item) {
                if ($item['session_id'] === $sessionId && in_array($item['effect'], $effects, true)
                    && ($recordId === null || $item['enrollment_id'] === $recordId || $item['application_id'] === $recordId)) {
                    $ids[] = (string) $event->id;
                }
            }
        }

        return $ids !== [] && EnrollmentEventDelivery::query()->whereIn('event_id', array_values(array_unique($ids)))
            ->where('audience', 'parent')->whereIn('status', ['sent', 'sending'])
            ->when($before !== null, fn ($query) => $query->where(fn ($when) => $when->where('sent_at', '<', $before)
                ->orWhere(fn ($sending) => $sending->whereNull('sent_at')->where('updated_at', '<', $before))))
            ->exists();
    }

    /**
     * Up to five days with a free place for another visit (т. 5.5): from a price — the sessions open for visits in the
     * groups of the same activity that take this way; from an offer — its other sessions. Including the session itself
     * when it is back and has a place.
     *
     * @return list<string> „петък, 20 ноември, 16:00 · Група 1 · 3 свободни места“
     */
    private function freeDays(EnrollmentApplication $application, Session $session): array
    {
        $now = CarbonImmutable::now();
        $until = $now->addDays(self::SEARCH_DAYS);
        $found = [];
        $offer = $application->visitOffer;
        if ($offer !== null) {
            if ($offer->status !== VisitOfferStatus::Published) {
                return [];
            }
            $group = $session->group;
            foreach ($offer->sessions()->where('activity_sessions.status', 'scheduled')
                ->where('activity_sessions.starts_at', '>', $now->addHours((int) $offer->booking_deadline_hours))
                ->where('activity_sessions.starts_at', '<=', $until)->orderBy('activity_sessions.starts_at')->get() as $candidate) {
                $left = $this->capacity->remainingForOffer($offer, $candidate);
                if ($left > 0) {
                    $found[] = ['session' => $candidate, 'group' => $group, 'remaining' => $left];
                }
                if (count($found) >= self::FREE_DAYS) {
                    break;
                }
            }
        } else {
            $mode = $application->scope->value;
            $activityId = $session->group?->offering?->activity_id;
            $groups = $activityId === null ? [] : Group::query()->with(['offering.priceOptions', 'priceOptions'])
                ->where('status', 'published')->whereHas('offering', fn ($query) => $query->where('activity_id', $activityId))
                ->orderBy('id')->get()->all();
            foreach ($groups as $group) {
                if (! $group->takesMode($mode) || $group->availablePriceFor($mode) === null) {
                    continue;
                }
                $sessions = Session::query()->where('group_id', $group->id)->where('status', 'scheduled')
                    ->where('starts_at', '>', $now)->where('starts_at', '<=', $until)->orderBy('starts_at')->orderBy('id')->get();
                foreach ($this->capacity->visitDays($group, $sessions, $now, self::FREE_DAYS) as $day) {
                    if ($day['remaining'] === null || $day['remaining'] > 0) {
                        $found[] = ['session' => $day['session'], 'group' => $group, 'remaining' => $day['remaining']];
                    }
                }
            }
        }
        usort($found, fn (array $a, array $b): int => $a['session']->starts_at->getTimestamp() <=> $b['session']->starts_at->getTimestamp());

        return array_map(fn (array $free): string => self::moment($free['session']).' · '.($free['group']->name ?? '').' · '
            .match (true) {
                $free['remaining'] === null => 'има свободни места',
                $free['remaining'] === 1 => '1 свободно място',
                default => $free['remaining'].' свободни места',
            }, array_slice($found, 0, self::FREE_DAYS));
    }

    /**
     * The public page of the activity — only when the organization's profile and the activity are published: a school
     * with a closed system has none (т. 5.5).
     *
     * @param  array{organization: string, organizationId: string, platform: string, webUrl: string, brand: Branding, replyTo: string|null, profile: OrganizationCatalogProfile|null}  $context
     */
    private function publicPage(?Group $group, array $context): ?string
    {
        $activity = $group?->offering?->activity;
        $profile = $context['profile'];
        if ($activity === null || $profile === null || ! $profile->is_published || $activity->status !== ActivityStatus::Published) {
            return null;
        }

        return rtrim($context['webUrl'], '/').'/o/'.rawurlencode((string) $profile->slug).'/activities/'.rawurlencode((string) $activity->slug);
    }

    /**
     * @return list<Item>
     */
    private static function items(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }
        $result = [];
        foreach ($items as $item) {
            if (is_array($item) && is_string($item['session_id'] ?? null) && is_string($item['effect'] ?? null)) {
                $result[] = [
                    'session_id' => $item['session_id'],
                    'effect' => $item['effect'],
                    'enrollment_id' => is_string($item['enrollment_id'] ?? null) ? $item['enrollment_id'] : null,
                    'application_id' => is_string($item['application_id'] ?? null) ? $item['application_id'] : null,
                ];
            }
        }

        return $result;
    }

    /** @param  Line  $line */
    private static function child(array $line): string
    {
        $child = $line['enrollment']->child ?? $line['application']->child;

        return trim((string) $child?->first_name) ?: 'детето';
    }

    /** "Здравейте, Мария," — by the first name (decision of 30.10.2026). */
    private static function greeting(User $user): string
    {
        $first = trim((string) strtok(trim((string) $user->name), ' '));

        return $first === '' ? 'Здравейте,' : "Здравейте, {$first},";
    }

    /** „Шахмат · Група 2“ */
    private static function title(?Group $group): string
    {
        $activity = $group?->offering?->activity?->title;

        return trim(($activity === null ? '' : $activity.' · ').($group->name ?? 'групата'));
    }

    private static function activity(?Group $group): string
    {
        return (string) ($group->offering->activity->title ?? 'заниманието');
    }

    private static function timezone(Session $session): string
    {
        $timezone = $session->group?->schedule_pattern['timezone'] ?? null;

        return is_string($timezone) && $timezone !== '' ? $timezone : 'Europe/Sofia';
    }

    /** „в сряда“, „във вторник“, „във Футбол“ — "във" before в and ф. */
    private static function in(string $words): string
    {
        return (in_array(mb_strtolower(mb_substr($words, 0, 1)), ['в', 'ф'], true) ? 'във ' : 'в ').$words;
    }

    /** „сряда, 18 ноември, 17:30“ */
    private static function moment(Session $session): string
    {
        $start = $session->starts_at->setTimezone(self::timezone($session));

        return BulgarianDates::weekdayDay($start->toDateString()).', '.$start->format('H:i');
    }

    /** „18 ноември“ */
    private static function day(Session $session): string
    {
        return BulgarianDates::day($session->starts_at->setTimezone(self::timezone($session))->toDateString());
    }

    /** „сряда, 18 ноември · 17:30–18:30“ */
    private static function sessionText(Session $session): string
    {
        return BulgarianDates::session($session->starts_at, $session->ends_at, self::timezone($session));
    }

    /** @return array{label: string, value: string, hint: string|null, icon: string} */
    private static function fact(string $label, string $value, string $webUrl, ?string $hint = null): array
    {
        $icon = match ($label) {
            'Място' => 'pin',
            'Деца' => 'group',
            default => 'calendar',
        };

        return ['label' => $label, 'value' => $value, 'hint' => $hint, 'icon' => rtrim($webUrl, '/').'/email-icons/'.$icon.'.png'];
    }

    /**
     * Where the session is: its own place, else the group's, else the activity's — name and address, directions, a map.
     *
     * @return array{text: string, hint: string|null, url: string|null}|null
     */
    private static function place(Session $session, bool $explicit = false): ?array
    {
        if ($explicit && $session->location_id === null) {
            return ['text' => 'Без място', 'hint' => null, 'url' => null];
        }
        $location = $session->location ?? $session->group->location ?? $session->group->offering->location;
        if (! $location instanceof Location) {
            return null;
        }
        $address = trim((string) $location->address);
        $text = trim($location->name.($address !== '' ? ', '.$address : ''));
        $url = is_string($location->map_url) && str_starts_with($location->map_url, 'https://') ? $location->map_url
            : ($address !== '' ? 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($address) : null);
        $hint = trim((string) $location->public_directions);

        return ['text' => $text, 'hint' => $hint === '' ? null : $hint, 'url' => $url];
    }
}
