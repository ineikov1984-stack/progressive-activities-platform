<?php

namespace App\Domain\PaymentTerms\Rules;

use App\Domain\PaymentTerms\Enums\BillingKind;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The shape and bounds of the rules for each way of paying (docs/product/payment-policies.md, т. 5). A
 * percentage is always the share KEPT (0–100); the screen phrases it as "дължи се" or "връщат се".
 * validate() returns the rules with unknown keys dropped, or throws with Bulgarian messages.
 */
final class TermsSchema
{
    /** Bumped when the JSON shape changes; stored with every version of a set of terms. */
    public const VERSION = 1;

    public const REFUND = ['money', 'credit', 'parent_choice'];

    private const PERCENT = ['required', 'integer', 'between:0,100'];

    private const MESSAGES = [
        'required' => 'Попълнете „:attribute“.',
        'required_if' => 'Попълнете „:attribute“.',
        'present' => 'Попълнете „:attribute“.',
        'integer' => '„:attribute“ трябва да е цяло число.',
        'between' => '„:attribute“ трябва да е от :min до :max.',
        'in' => 'Изберете „:attribute“ от списъка.',
        'boolean' => '„:attribute“ трябва да е да или не.',
        'array' => 'Липсва „:attribute“.',
        'list' => 'Липсва „:attribute“.',
        'min' => '„:attribute“ трябва да има поне :min стъпало.',
        'max' => '„:attribute“ може да има най-много :max стъпала.',
    ];

    private const ATTRIBUTES = [
        'start.mode' => 'начало в средата на месеца',
        'start.cutoff_day' => 'ден от месеца',
        'start.kept_until_cutoff' => 'процент до този ден',
        'start.kept_after_cutoff' => 'процент след този ден',
        'end.mode' => 'прекратяване',
        'end.cutoff_day' => 'ден от месеца',
        'end.kept_until_cutoff' => 'процент до този ден',
        'end.kept_after_cutoff' => 'процент след този ден',
        'end.notice_days' => 'предизвестие в дни',
        'pause.notice_days' => 'предизвестие за пауза',
        'pause.kept_on_time' => 'процент при навременна пауза',
        'pause.kept_late' => 'процент при късна пауза',
        'pause.free_days_per_school_year' => 'дни пауза без такса в годината',
        'pause.document_required' => 'пауза само с документ',
        'pause.min_days' => 'най-кратка пауза',
        'absences.tiers' => 'стъпала за отсъствия',
        'absences.tiers.*.up_to' => 'брой отсъствия',
        'absences.tiers.*.kept' => 'процент',
        'absences.kept_first_days' => 'дни отсъствие без връщане',
        'absences.kept_after' => 'процент за следващите дни',
        'makeup.enabled' => 'наваксване',
        'makeup.within_days' => 'дни за наваксване',
        'makeup.notice_hours' => 'часове предварително',
        'makeup.per_month' => 'наваксвания в месеца',
        'makeup.only_if_space' => 'само ако има място',
        'refund' => 'начин на връщане',
        'cancellation.tiers' => 'стъпала за отказ',
        'cancellation.tiers.*.min_hours' => 'часове преди началото',
        'cancellation.tiers.*.kept' => 'процент',
        'no_show_kept' => 'процент при неявяване',
        'before_start.tiers' => 'стъпала преди началото',
        'before_start.tiers.*.min_days' => 'дни преди началото',
        'before_start.tiers.*.kept' => 'процент',
        'before_start.tiers.*.credit_offered' => 'предложен кредит',
        'after_start.full_refund_days' => 'дни след началото с пълно връщане',
        'after_start.kept_of_remaining' => 'процент от оставащите занимания',
        'freeze_days_with_document' => 'дни замразяване с документ',
        'extension_days_on_request' => 'дни удължаване по молба',
        'week_change_free_until_days' => 'дни за смяна на седмицата без такса',
        'deposit.mode' => 'капаро',
        'deposit.amount_cents' => 'сума на капарото',
        'deposit.percent' => 'процент капаро',
        'illness_with_document.kept' => 'процент при болест',
        'illness_with_document.plus_costs' => 'реални разходи при болест',
        'transfer_to_another_child_until_days' => 'дни за друго дете на мястото',
        'early_departure_kept' => 'процент при ранно прибиране',
        'notice_days' => 'предизвестие в дни',
        'deposit_months' => 'депозит в месечни такси',
        'meals_by_attendance' => 'храна по присъствие',
        'sick_over_working_days' => 'работни дни болест',
        'sick_next_month_kept' => 'процент за следващия месец',
        'absence' => 'отбелязване на отсъствие',
        'pause_weeks' => 'седмици пауза без одобрение',
        'cancel_visit' => 'отказ от посещение',
    ];

    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public static function validate(BillingKind $kind, array $rules): array
    {
        $validator = Validator::make($rules, self::rulesFor($kind), self::MESSAGES, self::ATTRIBUTES);
        $validator->after(function ($validator) use ($kind, $rules): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            foreach (self::orderErrors($kind, $rules) as $key => $message) {
                $validator->errors()->add($key, $message);
            }
        });

        return $validator->validate();
    }

    /**
     * What the parent may do without asking the team (review 1.10). Kept on the set of terms, not in the copy
     * on an enrollment: it follows the organization's current choice.
     *
     * @param  array<string, mixed>  $selfService
     * @return array<string, mixed>
     */
    public static function validateSelfService(array $selfService): array
    {
        return Validator::make($selfService, [
            'absence' => ['required', 'boolean'],
            'pause_weeks' => ['required', 'integer', 'between:0,12'],
            'end' => ['required', 'boolean'],
            'cancel_visit' => ['required', 'boolean'],
        ], self::MESSAGES, self::ATTRIBUTES)->validate();
    }

    /** @return array<string, list<mixed>> */
    private static function rulesFor(BillingKind $kind): array
    {
        $refund = ['refund' => ['required', Rule::in(self::REFUND)]];
        $pause = [
            'pause' => ['required', 'array'],
            'pause.notice_days' => ['required', 'integer', 'between:0,60'],
            'pause.kept_on_time' => self::PERCENT,
            'pause.kept_late' => self::PERCENT,
            'pause.free_days_per_school_year' => ['required', 'integer', 'between:0,183'],
            'pause.document_required' => ['required', 'boolean'],
            'pause.min_days' => ['required', 'integer', 'between:0,183'],
        ];
        $makeup = [
            'makeup' => ['required', 'array'],
            'makeup.enabled' => ['required', 'boolean'],
            'makeup.within_days' => ['required', 'integer', 'between:1,60'],
            'makeup.notice_hours' => ['required', 'integer', 'between:0,72'],
            'makeup.per_month' => ['present', 'nullable', 'integer', 'between:1,31'],
            'makeup.only_if_space' => ['required', 'boolean'],
        ];

        return match ($kind) {
            BillingKind::Monthly => [
                ...self::monthPart('start'),
                ...self::monthPart('end'),
                'end.notice_days' => ['required', 'integer', 'between:0,30'],
                ...$pause,
                'absences' => ['required', 'array'],
                'absences.tiers' => ['required', 'array', 'list', 'min:1', 'max:3'],
                'absences.tiers.*.up_to' => ['present', 'nullable', 'integer', 'between:0,31'],
                'absences.tiers.*.kept' => self::PERCENT,
                ...$makeup,
                ...$refund,
            ],
            BillingKind::FullPeriod => [
                ...self::beforeStart(false),
                'after_start' => ['required', 'array'],
                'after_start.full_refund_days' => ['required', 'integer', 'between:0,60'],
                'after_start.kept_of_remaining' => self::PERCENT,
                ...$pause,
                ...$makeup,
                ...$refund,
            ],
            BillingKind::Visit => [
                'cancellation' => ['required', 'array'],
                'cancellation.tiers' => ['required', 'array', 'list', 'min:1', 'max:3'],
                'cancellation.tiers.*.min_hours' => ['required', 'integer', 'between:0,168'],
                'cancellation.tiers.*.kept' => self::PERCENT,
                'no_show_kept' => self::PERCENT,
                ...$refund,
            ],
            BillingKind::Package => [
                'freeze_days_with_document' => ['required', 'integer', 'between:0,90'],
                'extension_days_on_request' => ['required', 'integer', 'between:0,90'],
                ...$refund,
            ],
            BillingKind::DayCamp => [
                ...self::beforeStart(true),
                'absences' => ['required', 'array'],
                'absences.kept_first_days' => ['required', 'integer', 'between:0,5'],
                'absences.kept_after' => self::PERCENT,
                'week_change_free_until_days' => ['required', 'integer', 'between:0,30'],
                ...$refund,
            ],
            BillingKind::ResidentialCamp => [
                'deposit' => ['required', 'array'],
                'deposit.mode' => ['required', Rule::in(['none', 'amount', 'percent'])],
                'deposit.amount_cents' => ['required_if:deposit.mode,amount', 'nullable', 'integer', 'between:0,10000000'],
                'deposit.percent' => ['required_if:deposit.mode,percent', 'nullable', 'integer', 'between:0,30'],
                ...self::beforeStart(true),
                'illness_with_document' => ['required', 'array'],
                'illness_with_document.kept' => self::PERCENT,
                'illness_with_document.plus_costs' => ['required', 'boolean'],
                'transfer_to_another_child_until_days' => ['required', 'integer', 'between:0,60'],
                'early_departure_kept' => self::PERCENT,
                ...$refund,
            ],
            BillingKind::Kindergarten => [
                'notice_days' => ['required', 'integer', 'between:0,30'],
                'deposit_months' => ['required', 'integer', 'between:0,1'],
                'meals_by_attendance' => ['required', 'boolean'],
                'sick_over_working_days' => ['required', 'integer', 'between:0,60'],
                'sick_next_month_kept' => self::PERCENT,
                ...$refund,
            ],
        };
    }

    /**
     * The start or the end of a monthly enrollment: by sessions, by a day of the month, or the whole month.
     *
     * @return array<string, list<mixed>>
     */
    private static function monthPart(string $part): array
    {
        return [
            $part => ['required', 'array'],
            "{$part}.mode" => ['required', Rule::in(['by_sessions', 'cutoff', 'full_month'])],
            "{$part}.cutoff_day" => ["required_if:{$part}.mode,cutoff", 'nullable', 'integer', 'between:1,28'],
            "{$part}.kept_until_cutoff" => ["required_if:{$part}.mode,cutoff", 'nullable', 'integer', 'between:0,100'],
            "{$part}.kept_after_cutoff" => ["required_if:{$part}.mode,cutoff", 'nullable', 'integer', 'between:0,100'],
        ];
    }

    /**
     * A ladder by days before the start: "N and more days → keep %", optionally with a credit offered.
     *
     * @return array<string, list<mixed>>
     */
    private static function beforeStart(bool $credit): array
    {
        return [
            'before_start' => ['required', 'array'],
            'before_start.tiers' => ['required', 'array', 'list', 'min:1', 'max:6'],
            'before_start.tiers.*.min_days' => ['required', 'integer', 'between:0,365'],
            'before_start.tiers.*.kept' => self::PERCENT,
            ...($credit ? ['before_start.tiers.*.credit_offered' => ['required', 'boolean']] : []),
        ];
    }

    /**
     * Ladders read in one direction: more absences never keep more; a later cancellation never keeps less;
     * the last step closes the ladder ("повече" / 0 hours / 0 days).
     *
     * @param  array<string, mixed>  $rules
     * @return array<string, string>
     */
    private static function orderErrors(BillingKind $kind, array $rules): array
    {
        $errors = [];
        if ($kind === BillingKind::Monthly) {
            $tiers = array_values((array) Arr::get($rules, 'absences.tiers', []));
            $bounds = array_column($tiers, 'up_to');
            $kept = array_column($tiers, 'kept');
            $open = end($bounds) === null;
            $closed = array_slice($bounds, 0, -1);
            if (! $open || in_array(null, $closed, true) || $closed !== self::sorted($closed, ascending: true, strict: true)) {
                $errors['absences.tiers'] = 'Стъпалата за отсъствия вървят от по-малко към повече отсъствия, а последното е „повече“.';
            } elseif ($kept !== self::sorted($kept, ascending: false)) {
                $errors['absences.tiers'] = 'При повече отсъствия процентът не може да е по-голям.';
            }
        }
        $ladders = match ($kind) {
            BillingKind::Visit => ['cancellation.tiers' => 'min_hours'],
            BillingKind::FullPeriod, BillingKind::DayCamp, BillingKind::ResidentialCamp => ['before_start.tiers' => 'min_days'],
            default => [],
        };
        foreach ($ladders as $path => $unit) {
            $tiers = array_values((array) Arr::get($rules, $path, []));
            $steps = array_column($tiers, $unit);
            $kept = array_column($tiers, 'kept');
            if ($steps !== self::sorted($steps, ascending: false, strict: true) || end($steps) !== 0) {
                $errors[$path] = $unit === 'min_hours'
                    ? 'Стъпалата вървят от повече към по-малко часове преди началото, а последното е 0 часа.'
                    : 'Стъпалата вървят от повече към по-малко дни преди началото, а последното е 0 дни.';
            } elseif ($kept !== self::sorted($kept, ascending: true)) {
                $errors[$path] = 'При по-късен отказ процентът, който се задържа, не може да е по-малък.';
            }
        }

        return $errors;
    }

    /**
     * @param  list<mixed>  $values
     * @return list<mixed>
     */
    private static function sorted(array $values, bool $ascending, bool $strict = false): array
    {
        $sorted = $values;
        $ascending ? sort($sorted) : rsort($sorted);

        return $strict && count(array_unique($sorted, SORT_REGULAR)) !== count($sorted) ? [] : $sorted;
    }
}
