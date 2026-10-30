<?php

namespace Tests\Unit\PaymentTerms;

use App\Domain\PaymentTerms\Enums\BillingKind;
use App\Domain\PaymentTerms\Enums\TermsPreset;
use App\Domain\PaymentTerms\Rules\MarketplaceLimits;
use App\Domain\PaymentTerms\Rules\Presets;
use App\Domain\PaymentTerms\Rules\TermsSchema;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Step 2a of docs/product/payment-policies.md: the rules' shape, the ready-made sets and the marketplace frame. */
class TermsSchemaTest extends TestCase
{
    /** @return iterable<string, array{BillingKind, TermsPreset}> */
    public static function presets(): iterable
    {
        foreach (BillingKind::cases() as $kind) {
            foreach ([TermsPreset::Flexible, TermsPreset::Standard, TermsPreset::Strict] as $preset) {
                yield "{$kind->value} {$preset->value}" => [$kind, $preset];
            }
        }
    }

    #[DataProvider('presets')]
    public function test_every_ready_made_set_is_valid_and_fits_the_marketplace(BillingKind $kind, TermsPreset $preset): void
    {
        $rules = Presets::rules($kind, $preset);

        $this->assertEquals($rules, TermsSchema::validate($kind, $rules));
        $this->assertSame([], MarketplaceLimits::violations($kind, $rules));
        $this->assertEquals(Presets::selfService($preset), TermsSchema::validateSelfService(Presets::selfService($preset)));
    }

    public function test_the_standard_sets_hold_the_owners_numbers(): void
    {
        $monthly = Presets::rules(BillingKind::Monthly, TermsPreset::Standard);
        // Last day up to the 15th — half the month, after — all of it.
        $this->assertSame(['mode' => 'cutoff', 'cutoff_day' => 15, 'kept_until_cutoff' => 50, 'kept_after_cutoff' => 100, 'notice_days' => 0], $monthly['end']);
        // A pause asked for 7 days ahead costs nothing (the owner asked for less than 14).
        $this->assertSame([7, 0, 50, 61], [$monthly['pause']['notice_days'], $monthly['pause']['kept_on_time'], $monthly['pause']['kept_late'], $monthly['pause']['free_days_per_school_year']]);
        // Up to 3 absences — the whole fee; more — half; makeups within 14 days if told 3 hours ahead.
        $this->assertSame([['up_to' => 3, 'kept' => 100], ['up_to' => null, 'kept' => 50]], $monthly['absences']['tiers']);
        $this->assertSame([true, 14, 3], [$monthly['makeup']['enabled'], $monthly['makeup']['within_days'], $monthly['makeup']['notice_hours']]);
        $this->assertSame('parent_choice', $monthly['refund']);

        $visit = Presets::rules(BillingKind::Visit, TermsPreset::Standard);
        $this->assertSame([['min_hours' => 24, 'kept' => 0], ['min_hours' => 12, 'kept' => 50], ['min_hours' => 0, 'kept' => 100]], $visit['cancellation']['tiers']);

        $camp = Presets::rules(BillingKind::ResidentialCamp, TermsPreset::Standard);
        $this->assertSame([[30, 0], [15, 25], [8, 50], [0, 100]], array_map(fn (array $tier): array => [$tier['min_days'], $tier['kept']], $camp['before_start']['tiers']));

        $dayCamp = Presets::rules(BillingKind::DayCamp, TermsPreset::Standard);
        $this->assertSame(['kept_first_days' => 2, 'kept_after' => 50], $dayCamp['absences']);
        // Under 7 days nothing comes back, but a credit is offered.
        $this->assertSame(['min_days' => 0, 'kept' => 100, 'credit_offered' => true], end($dayCamp['before_start']['tiers']));

        // "Собствени" starts from "Стандартни".
        $this->assertSame($monthly, Presets::rules(BillingKind::Monthly, TermsPreset::Custom));
    }

    public function test_numbers_outside_their_bounds_are_refused_in_bulgarian(): void
    {
        $rules = Presets::rules(BillingKind::Monthly, TermsPreset::Standard);
        $rules['end']['cutoff_day'] = 31;
        $rules['pause']['kept_late'] = 120;
        $rules['refund'] = 'voucher';

        $errors = $this->errors(BillingKind::Monthly, $rules);

        $this->assertSame('„ден от месеца“ трябва да е от 1 до 28.', $errors['end.cutoff_day'][0]);
        $this->assertSame('„процент при късна пауза“ трябва да е от 0 до 100.', $errors['pause.kept_late'][0]);
        $this->assertSame('Изберете „начин на връщане“ от списъка.', $errors['refund'][0]);
    }

    public function test_a_cut_off_day_is_needed_only_for_the_cut_off_rule(): void
    {
        $rules = Presets::rules(BillingKind::Monthly, TermsPreset::Standard);
        $rules['end']['cutoff_day'] = null;
        $this->assertSame('Попълнете „ден от месеца“.', $this->errors(BillingKind::Monthly, $rules)['end.cutoff_day'][0]);

        $rules['end'] = ['mode' => 'by_sessions', 'cutoff_day' => null, 'kept_until_cutoff' => null, 'kept_after_cutoff' => null, 'notice_days' => 14];
        $this->assertSame(14, TermsSchema::validate(BillingKind::Monthly, $rules)['end']['notice_days']);
    }

    public function test_ladders_read_in_one_direction(): void
    {
        $visit = Presets::rules(BillingKind::Visit, TermsPreset::Standard);
        $visit['cancellation']['tiers'] = [['min_hours' => 12, 'kept' => 50], ['min_hours' => 24, 'kept' => 0], ['min_hours' => 0, 'kept' => 100]];
        $this->assertSame('Стъпалата вървят от повече към по-малко часове преди началото, а последното е 0 часа.', $this->errors(BillingKind::Visit, $visit)['cancellation.tiers'][0]);

        // A later cancellation never keeps less.
        $visit['cancellation']['tiers'] = [['min_hours' => 24, 'kept' => 50], ['min_hours' => 0, 'kept' => 20]];
        $this->assertSame('При по-късен отказ процентът, който се задържа, не може да е по-малък.', $this->errors(BillingKind::Visit, $visit)['cancellation.tiers'][0]);

        // The last step closes the ladder at 0 days.
        $camp = Presets::rules(BillingKind::ResidentialCamp, TermsPreset::Standard);
        array_pop($camp['before_start']['tiers']);
        $this->assertArrayHasKey('before_start.tiers', $this->errors(BillingKind::ResidentialCamp, $camp));

        // More absences never keep more; the last step is "more".
        $monthly = Presets::rules(BillingKind::Monthly, TermsPreset::Standard);
        $monthly['absences']['tiers'] = [['up_to' => 3, 'kept' => 50], ['up_to' => null, 'kept' => 100]];
        $this->assertSame('При повече отсъствия процентът не може да е по-голям.', $this->errors(BillingKind::Monthly, $monthly)['absences.tiers'][0]);
        $monthly['absences']['tiers'] = [['up_to' => 3, 'kept' => 100], ['up_to' => 5, 'kept' => 50]];
        $this->assertArrayHasKey('absences.tiers', $this->errors(BillingKind::Monthly, $monthly));
    }

    public function test_unknown_keys_are_dropped(): void
    {
        $rules = [...Presets::rules(BillingKind::Visit, TermsPreset::Standard), 'note' => 'не е правило'];

        $this->assertArrayNotHasKey('note', TermsSchema::validate(BillingKind::Visit, $rules));
    }

    public function test_the_marketplace_frame(): void
    {
        // "Nothing comes back, ever" is not allowed in the public catalog.
        $visit = Presets::rules(BillingKind::Visit, TermsPreset::Strict);
        $visit['cancellation']['tiers'] = [['min_hours' => 48, 'kept' => 100], ['min_hours' => 0, 'kept' => 100]];
        $this->assertEquals($visit, TermsSchema::validate(BillingKind::Visit, $visit));
        $this->assertSame(['cancellation.tiers' => 'В каталога на платформата поне при ранен отказ трябва да се връща нещо.'], MarketplaceLimits::violations(BillingKind::Visit, $visit));

        // A deposit above 30 % is refused by the rules themselves.
        $camp = Presets::rules(BillingKind::ResidentialCamp, TermsPreset::Standard);
        $camp['deposit']['percent'] = 40;
        $this->assertArrayHasKey('deposit.percent', $this->errors(BillingKind::ResidentialCamp, $camp));
    }

    public function test_the_kind_comes_from_the_price(): void
    {
        $this->assertSame(BillingKind::Monthly, BillingKind::fromEnrollmentMode('monthly'));
        $this->assertSame(BillingKind::FullPeriod, BillingKind::fromEnrollmentMode('full_period'));
        $this->assertSame(BillingKind::Package, BillingKind::fromEnrollmentMode('package'));
        $this->assertSame(BillingKind::Visit, BillingKind::fromEnrollmentMode('trial'));
        $this->assertSame(BillingKind::Visit, BillingKind::fromEnrollmentMode('single'));
        $this->assertNull(BillingKind::fromEnrollmentMode('unknown'));
        $this->assertSame('Месечна такса', BillingKind::Monthly->label());
        $this->assertSame('Стандартни', TermsPreset::Standard->label());
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, list<string>>
     */
    private function errors(BillingKind $kind, array $rules): array
    {
        try {
            TermsSchema::validate($kind, $rules);
        } catch (ValidationException $exception) {
            return $exception->errors();
        }
        $this->fail('The rules were accepted.');
    }
}
