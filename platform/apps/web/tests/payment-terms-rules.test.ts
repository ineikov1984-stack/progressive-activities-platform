import { describe, expect, it } from 'vitest';
import type { BillingKindInfo, Rules, SelfService } from '../src/features/payment-terms/api';
import {
  canonical, describeTerms, errorsFor, exampleFor, ladderRanges, matchPreset, money, paidShare, returnedShare, valueAt, withValue,
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

  it('says shares in words', () => {
    expect(paidShare(100, 'такса', 'от таксата')).toBe('цялата такса');
    expect(paidShare(50, 'такса', 'от таксата')).toBe('половината от таксата');
    expect(paidShare(30, 'такса', 'от таксата')).toBe('30 % от таксата');
    expect(paidShare(0, 'такса', 'от таксата')).toBe('нищо');
    expect(returnedShare(0)).toBe('връщаме всичко');
    expect(returnedShare(25)).toBe('връщаме 75 %');
    expect(returnedShare(100)).toBe('не връщаме нищо');
    expect(ladderRanges([30, 15, 8, 0], 'd')).toEqual(['поне 30 дни', '15–29 дни', '8–14 дни', 'по-малко от 8 дни']);
    expect(ladderRanges([24, 12, 0], 'h')).toEqual(['поне 24 часа', 'между 12 и 24 часа', 'по-малко от 12 часа']);
    expect(money(7.5)).toBe('7,50 €');
    expect(money(30)).toBe('30 €');
  });

  it('describes the standard monthly terms the way the owner put them', () => {
    const lines = Object.fromEntries(describeTerms('monthly', monthly).map((line) => [line.title, line.text]));
    expect(lines['Начало']).toBeUndefined();
    expect(lines['Прекратяване']).toBe('Ако последният ден е до 15-то число, плащате половината от таксата за месеца; след това — цялата такса за месеца.');
    expect(lines['Пауза']).toBe('Кажете ни поне 7 дни по-рано и заниманията в паузата не се плащат. По-късно — плащате половината от стойността им. Това важи за до 61 дни пауза в учебната година.');
    expect(lines['Отсъствия']).toBe('До 3 отсъствия в месеца — цялата такса, при повече — половината от таксата. Пропуснато занимание наваксвате до 14 дни, ако ни кажете поне 3 часа по-рано.');
    expect(lines['Връщане']).toBe('Върнатата сума получавате обратно или като кредит — по ваш избор.');
  });

  it('describes cancellation ladders for a visit and a camp', () => {
    expect(describeTerms('visit', visit)[0].text).toBe('Поне 24 часа преди началото — не плащате. Между 12 и 24 часа преди началото — плащате половината от сумата. По-малко от 12 часа преди началото — плащате цялата сума.');
    const campLines = Object.fromEntries(describeTerms('residential_camp', camp).map((line) => [line.title, line.text]));
    expect(campLines['Капаро']).toBe('25 % от цената при записване — приспада се от нея.');
    expect(campLines['Отказ преди тръгване']).toBe('Поне 30 дни преди тръгване — връщаме всичко. 15–29 дни преди тръгване — връщаме 75 %. 8–14 дни преди тръгване — връщаме половината. По-малко от 8 дни преди тръгване — не връщаме нищо.');
    expect(campLines['Болест']).toBe('При болест преди тръгване с документ от лекар връщаме всичко без реално направените разходи.');
    expect(describeTerms('day_camp', dayCamp)[0].text).toContain('По-малко от 7 дни преди седмицата — не връщаме нищо, но предлагаме кредит за друга седмица.');
  });

  it('works the spec examples out', () => {
    expect(exampleFor('monthly', monthly)?.lines).toEqual([
      'Последен ден 10 ноември → за ноември 30 €.',
      'Последен ден 20 ноември → 60 €.',
      '4 отсъствия в ноември → 30 €.',
    ]);
    expect(exampleFor('visit', visit)?.lines).toEqual(['Отказ 30 часа преди → 0 €.', 'Отказ 18 часа преди → 7,50 €.', 'Отказ 6 часа преди → 15 €.']);
    expect(exampleFor('residential_camp', camp)?.lines).toEqual([
      'Отказ 36 дни преди тръгване → връщат се 600 €.',
      'Отказ 22 дни преди тръгване → връщат се 450 €.',
      'Отказ 11 дни преди тръгване → връщат се 300 €.',
      'Отказ 4 дни преди тръгване → връщат се 0 €.',
    ]);
    expect(exampleFor('day_camp', dayCamp)?.lines).toEqual(['3 дни отсъствие без документ → връщат се 15 €.']);
    expect(exampleFor('package', {})).toBeNull();
  });
});
