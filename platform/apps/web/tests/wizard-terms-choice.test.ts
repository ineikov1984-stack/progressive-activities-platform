import { describe, expect, it } from 'vitest';
import type { PaymentTermsSet, PaymentTermsWorkspace, Rules } from '../src/features/payment-terms/api';
import { freeName, kindsOf, optionSummary, organizationTerms, otherOptions, stillFits } from '../src/features/catalog/admin/wizard/terms-choice';

const bySessions = { mode: 'by_sessions', cutoff_day: null, kept_until_cutoff: null, kept_after_cutoff: null };
const monthly = (end: Rules, noticeDays = 7): Rules => ({
  start: bySessions, end,
  pause: { notice_days: noticeDays, kept_on_time: 0, kept_late: 50, free_days_per_school_year: 61, document_required: false, min_days: 0 },
  absences: { tiers: [{ up_to: 3, kept: 100 }, { up_to: null, kept: 50 }] },
  makeup: { enabled: true, within_days: 14, notice_hours: 3, per_month: null, only_if_space: false },
  refund: 'parent_choice',
});
const standard = monthly({ mode: 'cutoff', cutoff_day: 15, kept_until_cutoff: 50, kept_after_cutoff: 100, notice_days: 0 });
const flexible = monthly({ ...bySessions, notice_days: 0 }, 3);
const strict = monthly({ mode: 'full_month', cutoff_day: null, kept_until_cutoff: null, kept_after_cutoff: null, notice_days: 0 }, 0);
const self = { absence: true, pause_weeks: 2, end: false, cancel_visit: true };

function set(id: string, changes: Partial<PaymentTermsSet> = {}): PaymentTermsSet {
  return {
    id, billing_kind: 'monthly', billing_kind_label: 'Месечна такса', name: 'Условията на организацията', preset: 'standard', preset_label: 'Стандартни',
    is_default: false, status: 'active', version: 1, self_service: self,
    current_version: { id: `${id}-v1`, number: 1, schema_version: 1, rules: standard, created_at: '2026-10-30T12:00:00Z' },
    marketplace_violations: {}, ...changes,
  };
}

function workspace(sets: PaymentTermsSet[], changes: Partial<PaymentTermsWorkspace> = {}): PaymentTermsWorkspace {
  return {
    payment_terms: sets,
    billing_kinds: [
      { value: 'monthly', label: 'Месечна такса', presets: {
        flexible: { label: 'Гъвкави', rules: flexible, self_service: self },
        standard: { label: 'Стандартни', rules: standard, self_service: self },
        strict: { label: 'Строги', rules: strict, self_service: self },
      } },
    ],
    in_marketplace: false, can_manage: true, schema_version: 1, ...changes,
  };
}

describe('payment terms in the activity editor', () => {
  it('chooses terms only for prices paid by the month, for the course or with a card', () => {
    expect(kindsOf(['trial', 'package', 'monthly', 'monthly', 'single'])).toEqual(['monthly', 'package']);
    expect(kindsOf(['trial', 'single'])).toEqual([]);
  });

  it('shows the organization\'s default, or the ready-made "Стандартни" when it has none', () => {
    const none = organizationTerms(workspace([]), 'monthly');
    expect(none).toMatchObject({ kindLabel: 'Месечна такса', presetLabel: 'Стандартни' });
    expect(none.lines.map((line) => line.title)).toEqual(['Ако спрете', 'Пауза', 'Отсъствия']);
    const own = organizationTerms(workspace([set('d', { is_default: true, preset: 'flexible', preset_label: 'Гъвкави' })]), 'monthly');
    expect(own.presetLabel).toBe('Гъвкави');
  });

  it('offers the other sets first, then the ready-made ones the organization does not use yet', () => {
    const sets = [set('d', { is_default: true }), set('s', { name: 'Летен интензив', preset: 'strict', preset_label: 'Строги', current_version: { id: 's-v1', number: 1, schema_version: 1, rules: strict, created_at: '' } }), set('old', { name: 'Стари', status: 'archived', preset: 'flexible' })];
    expect(otherOptions(workspace(sets), ['monthly']).map((option) => [option.value, option.label]))
      .toEqual([['s', 'Летен интензив (Строги)'], ['ready:monthly:flexible', 'Гъвкави']]);
    // Without a default the organization's terms are "Стандартни" already.
    expect(otherOptions(workspace([]), ['monthly']).map((option) => option.label)).toEqual(['Гъвкави', 'Строги']);
  });

  it('warns about terms outside the catalog only for an organization in the catalog', () => {
    const harsh = set('h', { name: 'Дълго предизвестие', preset: 'custom', preset_label: 'Собствени', marketplace_violations: { 'end.notice_days': 'В каталога на платформата предизвестието е най-много 30 дни.' } });
    const [option] = otherOptions(workspace([harsh]), ['monthly']);
    expect(optionSummary(workspace([harsh]), option).violations).toEqual([]);
    expect(optionSummary(workspace([harsh], { in_marketplace: true }), option).violations).toEqual(['В каталога на платформата предизвестието е най-много 30 дни.']);
  });

  it('drops a choice that no longer fits and names a ready-made set without a clash', () => {
    const sets = [set('a'), set('b', { status: 'archived' }), set('g', { name: 'Гъвкави' })];
    expect(stillFits(workspace(sets), 'a', ['monthly'])).toBe(true);
    expect(stillFits(workspace(sets), 'a', ['package'])).toBe(false);
    expect(stillFits(workspace(sets), 'b', ['monthly'])).toBe(false);
    expect(stillFits(workspace(sets), 'gone', ['monthly'])).toBe(false);
    expect(freeName(workspace(sets), 'monthly', 'Гъвкави')).toBe('Гъвкави 2');
    expect(freeName(workspace(sets), 'monthly', 'Строги')).toBe('Строги');
  });
});
