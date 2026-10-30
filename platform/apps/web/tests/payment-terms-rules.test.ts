import { describe, expect, it } from 'vitest';
import type { BillingKindInfo, Rules, SelfService } from '../src/features/payment-terms/api';
import {
  absenceExample, canonical, count, describeTerms, errorsFor, exampleFor, matchPreset, monthChoiceExample, money, ordinalSuffix, pauseExample, pays, percent, presetBlurb, returns, valueAt, withValue,
} from '../src/features/payment-terms/rules';

// The server's ready-made "Стандартни" sets (app/Domain/PaymentTerms/Rules/Presets.php).
const monthly: Rules = {
  start: { mode: 'by_sessions', cutoff_day: null, kept_until_cutoff: null, kept_after_cutoff: null },
  end: { mode: 'cutoff', cutoff_day: 15, kept_until_cutoff: 50, kept_after_cutoff: 100, notice_days: 0 },
  pause: { notice_days: 7, kept_on_time: 0, kept_late: 50, free_days_per_school_year: 61, document_required: false, min_days: 0 },
  absences: { tiers: [{ up_to: 3, kept: 100 }, { up_to: null, kept: 50 }] },
  makeup: { enabled: true, within_days: 14, notice_hours: 3, per_month: null, only_if_space: false },
  refund: 'parent_choice',
};
const visit: Rules = { cancellation: { tiers: [{ min_hours: 24, kept: 0 }, { min_hours: 12, kept: 50 }, { min_hours: 0, kept: 100 }] }, no_show_kept: 100, refund: 'parent_choice' };
const camp: Rules = {
  deposit: { mode: 'percent', amount_cents: null, percent: 25 },
  before_start: { tiers: [30, 15, 8, 0].map((min_days, index) => ({ min_days, kept: [0, 25, 50, 100][index], credit_offered: false })) },
  illness_with_document: { kept: 0, plus_costs: true },
  transfer_to_another_child_until_days: 7,
  early_departure_kept: 100,
  refund: 'parent_choice',
};
const dayCamp: Rules = {
  before_start: { tiers: [{ min_days: 14, kept: 0, credit_offered: false }, { min_days: 7, kept: 50, credit_offered: false }, { min_days: 0, kept: 100, credit_offered: true }] },
  absences: { kept_first_days: 2, kept_after: 50 },
  week_change_free_until_days: 3,
  refund: 'parent_choice',
};
const standardSelf: SelfService = { absence: true, pause_weeks: 2, end: false, cancel_visit: true };

describe('payment terms rules', () => {
  it('reads and changes a number by its path without touching the original', () => {
    const changed = withValue(monthly, 'absences.tiers.1.kept', 30);
    expect(valueAt(changed, 'absences.tiers.1.kept')).toBe(30);
    expect(valueAt(monthly, 'absences.tiers.1.kept')).toBe(50);
    expect(valueAt(changed, 'pause')).toBe(valueAt(monthly, 'pause'));
  });

  it('knows a ready-made set whatever the key order, and calls changed numbers "Собствени"', () => {
    const info = { value: 'monthly', label: 'Месечна такса', presets: {
      flexible: { label: 'Гъвкави', rules: { ...monthly, refund: 'money' }, self_service: standardSelf },
      standard: { label: 'Стандартни', rules: monthly, self_service: standardSelf },
      strict: { label: 'Строги', rules: { ...monthly, refund: 'credit' }, self_service: standardSelf },
    } } satisfies BillingKindInfo;
    const reordered = Object.fromEntries(Object.entries(monthly).reverse()) as Rules;
    expect(canonical(reordered)).toBe(canonical(monthly));
    expect(matchPreset(info, reordered, { cancel_visit: true, end: false, pause_weeks: 2, absence: true })).toBe('standard');
    expect(matchPreset(info, withValue(monthly, 'pause.notice_days', 10), standardSelf)).toBe('custom');
    expect(matchPreset(info, monthly, { ...standardSelf, end: true })).toBe('custom');
  });

  it('puts the server\'s messages under the row that edits them', () => {
    const errors = { 'rules.end.cutoff_day': ['„ден от месеца“ трябва да е от 1 до 28.'], 'rules.endless': ['друго'], 'self_service.pause_weeks': ['x'] };
    expect(errorsFor(errors, ['rules.end'])).toEqual(['„ден от месеца“ трябва да е от 1 до 28.']);
    expect(errorsFor(errors, ['self_service'])).toEqual(['x']);
  });

  it('says shares in words, with spaces that do not break inside a number', () => {
    const fee = { whole: 'цялата месечна такса', part: 'месечната такса', none: 'не плащате месечна такса' };
    expect(pays(100, fee)).toBe('плащате цялата месечна такса');
    expect(pays(50, fee)).toBe('плащате половината от месечната такса');
    expect(plain(pays(30, fee))).toBe('плащате 30 % от месечната такса');
    expect(pays(0, fee)).toBe('не плащате месечна такса');
    expect(returns(0, 'цената на лагера')).toBe('връщаме цялата сума');
    expect(returns(50, 'цената')).toBe('връщаме половината от цената');
    expect(plain(returns(25, 'цената на лагера'))).toBe('връщаме 75 % от цената на лагера');
    expect(returns(100, 'цената')).toBe('не връщаме пари');
    expect(percent(25)).toBe('25\u00A0%');
    expect([count(1, 'ден', 'дни'), count(2, 'ден', 'дни'), count(21, 'ден', 'дни'), count(1, 'час', 'часа'), count(24, 'час', 'часа')])
      .toEqual(['1\u00A0ден', '2\u00A0дни', '21\u00A0дни', '1\u00A0час', '24\u00A0часа']);
    expect(money(7.5)).toBe('7,50\u00A0€');
    expect(plain(money(30))).toBe('30,00 €');
    // The official spelling: "15-о число", not "15-то".
    expect([1, 2, 3, 4, 5, 7, 8, 10, 11, 12, 15, 20, 21, 22, 27, 28].map((day) => `${day}-${ordinalSuffix(day)}`)).toEqual(
      ['1-во', '2-ро', '3-то', '4-то', '5-о', '7-мо', '8-мо', '10-о', '11-о', '12-о', '15-о', '20-о', '21-во', '22-ро', '27-мо', '28-мо'],
    );
  });

  it('describes the standard monthly terms in full sentences', () => {
    const lines = Object.fromEntries(describeTerms('monthly', monthly).map((line) => [line.title, plain(line.text)]));
    // "By sessions" is what a parent expects; the line is shown only for the other two ways.
    expect(lines['Започване по средата на месеца']).toBeUndefined();
    expect(lines['Прекратяване']).toBe('Ако последният ден на детето в групата е до 15-о число включително, за този месец плащате половината от месечната такса. Ако е след 15-о число, плащате цялата такса.');
    expect(lines['Пауза']).toBe('По време на паузата детето запазва мястото си в групата. Ако ни предупредите поне 7 дни преди началото на паузата, не плащате заниманията в нея. Ако ни предупредите по-късно, плащате половината от стойността на заниманията в нея. Това важи за общо до 61 дни пауза в учебната година (от 1 септември до 31 август). След като ги използвате, плащате половината от стойността на заниманията в паузата.');
    // Without days at the on-time terms every pause counts as a late one.
    expect(plain(describeTerms('monthly', withValue(monthly, 'pause.free_days_per_school_year', 0)).find((line) => line.title === 'Пауза')?.text ?? ''))
      .toBe('По време на паузата детето запазва мястото си в групата. За заниманията в паузата плащате половината от стойността им.');
    expect(describeTerms('monthly', withValue(monthly, 'end.cutoff_day', 1)).find((line) => line.title === 'Прекратяване')?.text).toContain('до 1-во число включително');
    expect(describeTerms('monthly', withValue(monthly, 'end.kept_until_cutoff', 0)).find((line) => line.title === 'Прекратяване')?.text).toContain('за този месец не плащате месечна такса.');
    // The parent says before the missed session and makes it up after it (the owner, 31.10.2026).
    expect(lines['Отсъствия']).toBe('Ако детето отсъства до 3 пъти в месеца, плащате цялата месечна такса. Ако отсъства 4 или повече пъти, плащате половината от таксата. Ако ни предупредите поне 3 часа преди заниманието, че детето няма да дойде, можете да го наваксате в рамките на 14 дни след него.');
    expect(plain(describeTerms('monthly', withValue(monthly, 'absences.tiers', [{ up_to: 0, kept: 100 }, { up_to: null, kept: 80 }])).find((line) => line.title === 'Отсъствия')?.text ?? ''))
      .toContain('Ако детето няма отсъствия в месеца, плащате цялата месечна такса. Ако отсъства поне веднъж, плащате 80 % от таксата.');
    expect(lines['Връщане на пари']).toBe('Ако според условията ви се полагат пари, вие решавате дали да ви ги върнем, или да ги приспаднем от следващото ви плащане. Връщаме ги в срок до 14 дни по същия начин, по който сте платили.');
    const strictStart = withValue(monthly, 'start', { mode: 'cutoff', cutoff_day: 15, kept_until_cutoff: 100, kept_after_cutoff: 50 });
    expect(describeTerms('monthly', strictStart)[0]).toEqual({
      title: 'Започване по средата на месеца',
      text: 'Ако детето започне до 15-о число включително, за първия месец плащате цялата месечна такса. Ако започне след 15-о число, плащате половината от таксата.',
    });
  });

  it('describes cancellation ladders for a visit and a camp', () => {
    expect(plain(describeTerms('visit', visit)[0].text)).toBe('Ако откажете посещението поне 24 часа преди началото му, не плащате нищо. Ако откажете по-късно, но поне 12 часа преди началото, плащате половината от цената. Ако откажете по-малко от 12 часа преди началото, плащате цялата цена.');
    expect(describeTerms('visit', visit).map((line) => line.title)).toEqual(['Отказ', 'Пропуснато посещение', 'Връщане на пари']);
    const campLines = Object.fromEntries(describeTerms('residential_camp', camp).map((line) => [line.title, plain(line.text)]));
    expect(campLines['Капаро']).toBe('При записване плащате капаро в размер на 25 % от цената на лагера. Капарото е част от цената, а не допълнителна сума.');
    // A camp paid only in part keeps a share of the price, never more than was paid (the owner, 31.10.2026).
    expect(campLines['Отказ преди тръгване']).toBe('Ако се откажете поне 30 дни преди тръгване, връщаме всичко, което сте платили. Ако се откажете от 15 до 29 дни преди тръгване, задържаме 25 % от цената на лагера и ви връщаме останалото. Ако се откажете от 8 до 14 дни преди тръгване, задържаме половината от цената и ви връщаме останалото. Ако се откажете по-малко от 8 дни преди тръгване, не връщаме пари. Никога не задържаме повече, отколкото сте платили до момента. Ако сте платили само капарото, губите най-много капарото.');
    expect(campLines['Болест преди тръгване']).toBe('Ако детето се разболее преди тръгване и ни покажете бележка от лекар, връщаме всичко, което сте платили, без разходите, които вече сме направили за детето.');
    expect(campLines['Друго дете на мястото']).toBe('Ако ни предупредите поне 7 дни преди тръгване, друго дете може да заеме мястото на вашето без допълнителна такса.');
    expect(plain(describeTerms('day_camp', dayCamp)[0].text)).toContain('Ако се откажете по-малко от 7 дни преди началото ѝ, не връщаме пари, но можете да прехвърлите платената сума за друга седмица.');
    expect(plain(describeTerms('day_camp', withValue(dayCamp, 'absences.kept_first_days', 1)).find((line) => line.title === 'Отсъствия')?.text ?? ''))
      .toBe('За първия ден отсъствие в седмицата не връщаме пари. За всеки следващ ден отсъствие връщаме половината от дневната цена.');
    expect(describeTerms('kindergarten', { notice_days: 30, deposit_months: 1, meals_by_attendance: true, sick_over_working_days: 15, sick_next_month_kept: 50, refund: 'credit' }).map((line) => plain(line.text))).toEqual([
      'Ако детето напуска, предупредете ни поне 30 дни по-рано.',
      'При записване плащате депозит в размер на една месечна такса. Приспадаме го от таксата за последния месец.',
      'Храната плащате само за дните, в които детето е присъствало.',
      'Ако детето боледува повече от 15 работни дни в един месец, за следващия месец плащате половината от месечната такса.',
      'Ако според условията ви се полагат пари, можем да ги приспаднем от следващото ви плащане, ако желаете.',
    ]);
  });

  it('works the spec examples out in euro', () => {
    const shown = (example: { intro: string; lines: string[] } | null) => (example ? [example.intro, ...example.lines].map(plain) : null);
    expect(shown(exampleFor('monthly', monthly))).toEqual([
      'Например: месечната такса е 60,00 €, а през ноември има 4 занимания, по 15,00 € всяко.',
      'Ако последният ден на детето е 10 ноември, за ноември плащате 30,00 € вместо 60,00 €.',
      'Ако последният ден е 20 ноември, плащате цялата такса от 60,00 €.',
      'Ако детето отсъства 4 пъти през ноември, за ноември плащате 30,00 € вместо 60,00 €.',
    ]);
    expect(shown(exampleFor('visit', visit))).toEqual([
      'Например: посещението струва 15,00 €.',
      'Ако го откажете 30 часа преди началото, не плащате нищо.',
      'Ако го откажете 18 часа преди началото, плащате 7,50 €.',
      'Ако го откажете 6 часа преди началото, плащате 15,00 €.',
      'Ако детето не дойде, без да сте отказали, плащате 15,00 €.',
    ]);
    // With the price of the visit itself.
    expect(shown(exampleFor('visit', visit, 20))?.slice(0, 3)).toEqual(['Например: посещението струва 20,00 €.', 'Ако го откажете 30 часа преди началото, не плащате нищо.', 'Ако го откажете 18 часа преди началото, плащате 10,00 €.']);
    expect(shown(exampleFor('residential_camp', camp))).toEqual([
      'Например: лагерът струва 600,00 €. При записване сте платили капаро 150,00 €, а после и останалите 450,00 €.',
      'Ако се откажете 36 дни преди тръгване, връщаме ви 600,00 €.',
      'Ако се откажете 22 дни преди тръгване, връщаме ви 450,00 €.',
      'Ако се откажете 11 дни преди тръгване, връщаме ви 300,00 €.',
      'Ако се откажете 4 дни преди тръгване, не връщаме пари.',
      'Ако сте платили само капарото и се откажете 11 дни преди тръгване, губите 150,00 € и не дължите нищо повече.',
    ]);
    expect(shown(exampleFor('day_camp', dayCamp))).toEqual(['Например: седмицата струва 150,00 €, или 30,00 € на ден.', 'Ако детето отсъства 3 дни, връщаме 15,00 €.']);
    expect(exampleFor('package', {})).toBeNull();
  });

  it('puts a worked example under each monthly rule', () => {
    expect(plain(monthChoiceExample(monthly, 'end', 'by_sessions'))).toBe('Например: ако последният ден е 10 ноември, родителят плаща 30,00 € за 2 занимания.');
    expect(plain(monthChoiceExample(monthly, 'end', 'cutoff'))).toBe('Например: ако последният ден е 10 ноември, родителят плаща 30,00 €. Ако е 20 ноември, плаща цялата такса от 60,00 €.');
    expect(plain(monthChoiceExample(monthly, 'end', 'full_month'))).toBe('Например: ако последният ден е 10 ноември, родителят плаща цялата такса от 60,00 €.');
    expect(plain(monthChoiceExample(monthly, 'start', 'by_sessions'))).toBe('Например: ако детето започне на 17 ноември, родителят плаща 30,00 € за 2 занимания.');
    const strictStart = withValue(monthly, 'start', { mode: 'cutoff', cutoff_day: 15, kept_until_cutoff: 100, kept_after_cutoff: 50 });
    expect(plain(monthChoiceExample(strictStart, 'start', 'cutoff'))).toBe('Например: ако детето започне на 10 ноември, родителят плаща цялата такса от 60,00 €. Ако започне на 20 ноември, плаща 30,00 €.');
    expect(plain(pauseExample(monthly) ?? '')).toBe('Например: пауза от 2 до 15 ноември обхваща 2 занимания. Ако родителят ви предупреди навреме, за ноември плаща 30,00 €. Ако ви предупреди по-късно, плаща 45,00 €.');
    // "Строги": no notice, the same share either way and at least 21 days — the example is a whole month.
    const strictPause = withValue(monthly, 'pause', { notice_days: 0, kept_on_time: 50, kept_late: 50, free_days_per_school_year: 183, document_required: true, min_days: 21 });
    expect(plain(pauseExample(strictPause) ?? '')).toBe('Например: пауза от 2 до 30 ноември обхваща 4 занимания. За ноември родителят плаща 30,00 €.');
    // The yearly days change nothing here, so they are not said.
    expect(plain(describeTerms('monthly', strictPause).find((line) => line.title === 'Пауза')?.text ?? ''))
      .toBe('По време на паузата детето запазва мястото си в групата. За заниманията в паузата плащате половината от стойността им. Паузата трябва да е поне 21 дни. Пауза е възможна само ако ни покажете бележка от лекар.');
    expect(plain(absenceExample(monthly))).toBe('Например: ако детето отсъства 4 пъти през ноември, родителят плаща 30,00 € вместо 60,00 €.');
    expect(plain(absenceExample(withValue(monthly, 'absences.tiers', [{ up_to: null, kept: 100 }])))).toBe('Например: ако детето отсъства 4 пъти през ноември, родителят плаща цялата такса от 60,00 €.');
  });

  it('describes the ready-made sets in sentences, with "15-о"', () => {
    expect(presetBlurb('monthly', 'standard')).toBe('Ако детето спре до 15-о число, родителят плаща половината от таксата. Паузата е без такса, ако ви предупредят 7 дни по-рано.');
    expect(presetBlurb('package', 'standard')).toBe('Подходящи за повечето организации.');
  });
});

/** The text with its unbreakable spaces read as ordinary ones. */
function plain(text: string): string {
  return text.replace(/\u00A0/g, ' ');
}
