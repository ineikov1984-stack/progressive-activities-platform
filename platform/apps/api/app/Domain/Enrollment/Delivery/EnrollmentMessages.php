<?php

namespace App\Domain\Enrollment\Delivery;

use App\Domain\Catalog\Models\Group;
use App\Domain\Catalog\Models\Location;
use App\Domain\Catalog\Models\Session;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\EnrollmentApplication;
use App\Domain\Enrollment\Models\EnrollmentEvent;
use App\Domain\Enrollment\Services\SessionApplicability;
use Carbon\CarbonImmutable;

/**
 * The words of each email (docs/product/enrollment-contacts-and-emails.md, т. 6.2–6.4; the owner approved the
 * mock-up on 30.10.2026). The parent reads the child's first name, the activity, the group, days, the place and
 * what the team wrote for them — never a surname, class, birth date, the parent's note, a pause's reason or an
 * internal note. The team reads the activity and the group with a link — never the child or the parent.
 */
final class EnrollmentMessages
{
    public function __construct(private readonly SessionApplicability $sessions) {}

    /**
     * @param  array{organization: string, platform: string, brand: Branding, replyTo: string|null, reason: string|null, byParent: bool}  $context
     */
    public function parent(EnrollmentEvent $event, EnrollmentApplication $application, ?Enrollment $enrollment, array $context): MailContent
    {
        $child = trim((string) $application->child?->first_name) ?: 'детето';
        $group = $enrollment->group ?? $application->group;
        $where = $this->where($group);
        $org = $context['organization'];
        $payload = $event->payload;
        $text = fn (string $key): string => is_string($payload[$key] ?? null) ? $payload[$key] : '';
        $reply = fn (string $line): string => $context['replyTo'] !== null ? $line : "Не отговаряйте на този имейл — свържете се с {$org} по телефона.";
        $quote = $context['reason'] === null || trim($context['reason']) === '' ? null
            : ['label' => $context['byParent'] ? 'Вашата бележка' : 'Обяснение от екипа', 'text' => '„'.trim($context['reason']).'“'];
        $weekly = BulgarianDates::weekly($group?->weeklySlots());
        $place = $this->place($group);
        $mail = fn (string $subject, string $heading, array $paragraphs, array $facts = [], ?array $callout = null, ?array $quoteBlock = null, ?array $button = null, array $after = []): MailContent => new MailContent(
            team: false,
            fromName: "{$org} чрез {$context['platform']}",
            replyTo: $context['replyTo'],
            subject: $subject,
            brand: $context['brand'],
            greeting: $this->greeting($application),
            heading: $heading,
            paragraphs: array_values($paragraphs),
            facts: array_values(array_filter($facts)),
            callout: $callout,
            quote: $quoteBlock,
            button: $button,
            after: array_values($after),
            footer: "Получавате това писмо, защото подадохте заявка за {$child} в {$org}. Изпратено чрез {$context['platform']}.",
        );
        $fact = fn (string $label, string $value, ?string $hint = null): ?array => $value === '' ? null : ['label' => $label, 'value' => $value, 'hint' => $hint];
        $placeFact = $place === null ? null : $fact('Място', $place['text'], $place['hint']);
        $mapButton = $place['url'] ?? null ? ['label' => 'Как да стигнете', 'url' => $place['url']] : null;

        switch ($event->type) {
            case 'enrollment.application_submitted':
                if ($text('status') === 'waitlisted') {
                    return $this->waitlisted($mail, $child, $where, $quote, $reply);
                }

                return $mail(
                    "Получихме заявката за {$child} — {$where}",
                    "Получихме заявката за {$child}",
                    ["Екипът на {$org} ще я разгледа и ще ви пише, когато реши. Дотогава мястото не е запазено."],
                    [$fact('Дейност', $this->groupTitle($group)), $fact('Ден и час', $weekly), $fact('Подадена', BulgarianDates::day((string) $application->created_at?->toDateString()))],
                    after: [$reply('Искате да добавите нещо? Отговорете на този имейл.')],
                );
            case 'enrollment.application_approved':
                $first = $enrollment === null ? null : $this->firstSession($enrollment, null);
                $visit = $application->scope->value !== 'full_period';
                $kind = $application->scope->value === 'trial' ? 'пробно занимание' : 'еднократно посещение';

                return $mail(
                    $visit ? "Записахме {$child} за {$kind} в {$where}" : "Записахме {$child} в {$where}",
                    $visit ? "Записахме {$child} за {$kind}" : "Записахме {$child} в {$where}",
                    [$visit ? "Очакваме {$child} на заниманието." : "Мястото в групата е запазено. Очакваме {$child} на първото занимание."],
                    [
                        $first === null ? null : $fact($visit ? 'Занимание' : 'Първо занимание', $this->sessionText($first, $group)),
                        $visit ? null : $fact('После', $weekly),
                        $placeFact,
                    ],
                    button: $mapButton,
                    after: [$reply("Имате въпрос или {$child} няма да може да дойде? Отговорете на този имейл — ще го получи екипът на {$org}.")],
                );
            case 'enrollment.application_waitlisted':
                return $this->waitlisted($mail, $child, $where, $quote, $reply);
            case 'enrollment.application_rejected':
                return $mail(
                    "Заявката за {$child} за {$where} не е одобрена",
                    "Заявката за {$where} не е одобрена",
                    ["Благодарим за интереса. Екипът на {$org} този път не може да запише {$child} в тази група."],
                    quoteBlock: $quote,
                    after: [$reply('Имате въпрос? Отговорете на този имейл — ще го получи екипът.')],
                );
            case 'enrollment.transferred':
                $from = $enrollment?->transferredFrom;
                $day = $text('effective_date');
                $first = $enrollment === null ? null : $this->firstSession($enrollment, $day ?: null);

                return $mail(
                    "{$child} се премества в {$where}".($day !== '' ? ' от '.BulgarianDates::day($day) : ''),
                    "{$child} се премества в {$where}",
                    [],
                    [
                        $from?->ends_on === null ? null : $fact('Последен ден в „'.($from->group->name ?? 'старата група').'“', BulgarianDates::weekdayDay($from->ends_on->toDateString())),
                        $first === null ? null : $fact('Първо в новата група', $this->sessionText($first, $group)),
                        $fact('Дни и час', $weekly),
                        $placeFact,
                    ],
                    quoteBlock: $quote,
                    button: $mapButton,
                );
            case 'enrollment.paused':
                $back = $enrollment === null || $text('ends_on') === '' ? null
                    : $this->firstSession($enrollment, CarbonImmutable::parse($text('ends_on'))->addDay()->toDateString());

                return $mail(
                    "Пауза за {$child}: ".BulgarianDates::day($text('starts_on')).' – '.BulgarianDates::day($text('ends_on')),
                    "Пауза за {$child}: ".BulgarianDates::day($text('starts_on')).' – '.BulgarianDates::day($text('ends_on')),
                    ["Мястото на {$child} в {$where} се пази. Заниманията в паузата не се броят."],
                    [$back === null ? null : $fact('Връща се', $this->sessionText($back, $group))],
                );
            case 'enrollment.pause_ended':
                $cancelled = ($payload['cancelled'] ?? false) === true;
                $next = $enrollment === null ? null : $this->firstSession($enrollment, $text('resume_on') ?: null);

                return $mail(
                    $cancelled ? "Паузата за {$child} е отменена" : "{$child} се връща от паузата на ".BulgarianDates::day($text('resume_on')),
                    $cancelled ? "Паузата за {$child} е отменена" : "{$child} се връща от паузата",
                    [$cancelled ? "{$child} продължава в {$where} без пауза." : "{$child} продължава в {$where}."],
                    [$next === null ? null : $fact('Следващо занимание', $this->sessionText($next, $group))],
                );
            case 'enrollment.ended':
                $last = $text('last_day');

                return $mail(
                    "Последен ден на {$child} в {$where}: ".BulgarianDates::day($last),
                    'Записването приключва на '.BulgarianDates::day($last),
                    ["Последният ден на {$child} в {$where} е ".BulgarianDates::weekdayDay($last).'. След това мястото се освобождава.'],
                    quoteBlock: $quote,
                    after: [$reply('Грешка ли е? Отговорете на този имейл до '.BulgarianDates::day($last).'.')],
                );
            case 'enrollment.cancelled':
                return $mail(
                    "Записването на {$child} в {$where} е отменено",
                    'Записването е отменено',
                    ["Записването на {$child} в {$where} е отменено преди първото занимание. Мястото се освобождава."],
                    quoteBlock: $quote,
                    after: [$reply('Грешка ли е? Отговорете на този имейл.')],
                );
            case 'enrollment.reopened':
                $next = $enrollment === null ? null : $this->firstSession($enrollment, null);

                return $mail(
                    "{$child} продължава в {$where}",
                    "{$child} продължава в {$where}",
                    ["Прекратяването е отменено — {$child} продължава в групата."],
                    [$next === null ? null : $fact('Следващо занимание', $this->sessionText($next, $group))],
                );
            case 'enrollment.session_excluded':
            case 'enrollment.session_restored':
                $session = is_string($payload['session_id'] ?? null) ? Session::query()->find($payload['session_id']) : null;
                $day = $session === null ? '' : BulgarianDates::day($session->starts_at->setTimezone($this->timezone($group))->toDateString());
                $excluded = $event->type === 'enrollment.session_excluded';

                return $mail(
                    $excluded ? "{$child} пропуска заниманието на {$day}" : "Заниманието на {$day} отново се брои за {$child}",
                    $excluded ? "{$child} пропуска заниманието на {$day}" : "Заниманието на {$day} отново се брои",
                    [$excluded ? "Заниманието на {$day} в {$where} не се брои за {$child}." : "{$child} е очаквано на заниманието на {$day} в {$where}."],
                    quoteBlock: $excluded ? $quote : null,
                );
        }

        throw new \LogicException('No parent email for '.$event->type);
    }

    /**
     * @param  array{organization: string, organizationId: string, platform: string, webUrl: string, recipientKind: string, placesText: string, waitingInGroup: int, submittedHere: int}  $context
     */
    public function team(EnrollmentEvent $event, EnrollmentApplication $application, ?Enrollment $enrollment, array $context): MailContent
    {
        $group = $enrollment->group ?? $application->group;
        $where = $this->where($group);
        $org = $context['organization'];
        $payload = $event->payload;
        $text = fn (string $key): string => is_string($payload[$key] ?? null) ? $payload[$key] : '';
        $fact = fn (string $label, string $value, ?string $hint = null): ?array => $value === '' ? null : ['label' => $label, 'value' => $value, 'hint' => $hint];
        $link = $context['webUrl'].'/dashboard/enrollments?organization='.rawurlencode($context['organizationId']);
        $groupLink = $context['webUrl'].'/dashboard/enrollments?view=groups&group='.rawurlencode((string) $group?->id).'&organization='.rawurlencode($context['organizationId']);
        $footer = match ($context['recipientKind']) {
            'offering_contact' => 'Получавате това писмо като адрес за контакт на „'.($group->offering->activity->title ?? 'дейността')."“ в {$org}. Изпратено чрез {$context['platform']}.",
            'organization_contact' => "Получавате това писмо като адрес за контакт на {$org}. Изпратено чрез {$context['platform']}.",
            default => "Получавате това писмо като собственик или администратор на {$org}. Изпратено чрез {$context['platform']}.",
        };
        $private = 'Кое дете е и какво е написал родителят виждате след вход — в „Записвания“.';
        $waiting = $context['waitingInGroup'] > 0 ? ' · в групата чакат '.$context['waitingInGroup'] : '';
        $mail = fn (string $subject, array $paragraphs, array $facts, string $buttonLabel, string $url): MailContent => new MailContent(
            team: true,
            fromName: $context['platform'],
            replyTo: null,
            subject: $subject,
            brand: Branding::platform($context['platform']),
            greeting: null,
            heading: $subject,
            paragraphs: array_values($paragraphs),
            facts: array_values(array_filter($facts)),
            callout: null,
            quote: null,
            button: ['label' => $buttonLabel, 'url' => $url],
            after: [$private],
            footer: $footer,
        );
        $sessionDay = function () use ($payload, $group): string {
            $session = is_string($payload['session_id'] ?? null) ? Session::query()->find($payload['session_id']) : null;

            return $session === null ? '' : BulgarianDates::day($session->starts_at->setTimezone($this->timezone($group))->toDateString());
        };

        return match ($event->type) {
            'enrollment.application_submitted' => $mail(
                "Нова заявка за {$where}",
                [$text('status') === 'waitlisted' ? 'Групата е пълна — заявката е в чакащите.' : 'Родител подаде заявка. Тя чака вашето решение.'],
                [
                    $fact('Група', $this->groupTitle($group), BulgarianDates::weekly($group?->weeklySlots()) ?: null),
                    $fact('Места днес', $context['placesText']),
                    $fact('Чакат решение', $context['submittedHere'] === 1 ? '1 заявка в организацията' : $context['submittedHere'].' заявки в организацията'),
                ],
                'Отвори заявката',
                $link,
            ),
            'enrollment.application_withdrawn' => $mail("Родител оттегли заявка за {$where}", ['Заявката вече не чака решение.'], [], 'Отвори „Записвания“', $link),
            'enrollment.ended' => $mail(
                "Родител прекрати записване в {$where}",
                [],
                [
                    $fact('Последен ден', $text('last_day') === '' ? '' : BulgarianDates::weekdayDay($text('last_day'))),
                    $fact('Мястото', $text('last_day') === '' ? '' : 'се освобождава от '.BulgarianDates::day(CarbonImmutable::parse($text('last_day'))->addDay()->toDateString()).$waiting),
                ],
                'Отвори групата',
                $groupLink,
            ),
            'enrollment.cancelled' => $mail("Родител отмени записване в {$where} преди началото", ['Мястото се освобождава.'.($waiting !== '' ? ' В групата чакат '.$context['waitingInGroup'].'.' : '')], [], 'Отвори групата', $groupLink),
            'enrollment.session_excluded' => $mail("Родител отбеляза пропуск на {$sessionDay()} в {$where}", ['Мястото в това занимание се освобождава.'], [], 'Отвори групата', $groupLink),
            'enrollment.session_restored' => $mail("Родител отмени пропуска на {$sessionDay()} в {$where}", ['Детето ще бъде на заниманието.'], [], 'Отвори групата', $groupLink),
            default => throw new \LogicException('No team email for '.$event->type),
        };
    }

    /** "Здравейте, Мария," — by the parent's first name (decision of 30.10.2026). */
    private function greeting(EnrollmentApplication $application): string
    {
        $first = trim((string) strtok(trim((string) $application->parent?->name), ' '));

        return $first === '' ? 'Здравейте,' : "Здравейте, {$first},";
    }

    /**
     * @param  callable(string, string, array<int, string>, array<int, array<string, mixed>|null>=, array{title: string, text: string}|null=, array{label: string, text: string}|null=, array{label: string, url: string}|null=, array<int, string>=): MailContent  $mail
     * @param  array{label: string, text: string}|null  $quote
     * @param  callable(string): string  $reply
     */
    private function waitlisted(callable $mail, string $child, string $where, ?array $quote, callable $reply): MailContent
    {
        return $mail(
            "{$child} е в списъка на чакащите за {$where}",
            "{$child} е в списъка на чакащите",
            ["Групата {$where} в момента е пълна. Ще ви пишем, когато има място за {$child}."],
            [],
            ['title' => 'Чака място', 'text' => "Мястото не е запазено, докато не получите имейл „Записахме {$child}“."],
            $quote,
            null,
            [$reply('Ако вече не търсите място, отговорете на този имейл.')],
        );
    }

    /** „Шахмат · Група 2“ */
    private function where(?Group $group): string
    {
        return '„'.$this->groupTitle($group).'“';
    }

    private function groupTitle(?Group $group): string
    {
        $activity = $group?->offering?->activity?->title;

        return trim(($activity === null ? '' : $activity.' · ').($group->name ?? 'групата'));
    }

    /** The first session that counts for the child, from a day on (default: from now). */
    private function firstSession(Enrollment $enrollment, ?string $fromDay): ?Session
    {
        $timezone = SessionApplicability::timezone($enrollment);

        return $this->sessions->sessionsFor($enrollment)->first(fn (Session $session): bool => $session->starts_at->isFuture()
            && ($fromDay === null || $session->starts_at->setTimezone($timezone)->toDateString() >= $fromDay));
    }

    private function sessionText(Session $session, ?Group $group): string
    {
        return BulgarianDates::session($session->starts_at, $session->ends_at, $this->timezone($group));
    }

    private function timezone(?Group $group): string
    {
        $timezone = $group?->schedule_pattern['timezone'] ?? null;

        return is_string($timezone) && $timezone !== '' ? $timezone : 'Europe/Sofia';
    }

    /**
     * The group's place (else the offering's): name and address, the directions, and a map link.
     *
     * @return array{text: string, hint: string|null, url: string|null}|null
     */
    private function place(?Group $group): ?array
    {
        $location = $group->location ?? $group?->offering?->location;
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
