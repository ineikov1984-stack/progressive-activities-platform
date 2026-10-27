import { describe, expect, it } from 'vitest';
import {
  availabilityLabel,
  enrollmentCta,
  formatPublicPrice,
  navigationAction,
  optionalActivitySections,
  summarizePublicSchedule,
} from '../src/features/catalog/public/presentation';

describe('public catalog presentation', () => {
  it('formats integer EUR cents with the unit parents actually pay for', () => {
    expect(formatPublicPrice({ amount_cents: 1300, currency: 'EUR', unit: 'session' })).toBe('13,00 € / занимание');
    expect(formatPublicPrice({ amount_cents: 4500, currency: 'EUR', unit: 'month' })).toBe('45,00 € / месец');
  });

  it('derives Bulgarian availability from remaining capacity instead of a manual label', () => {
    expect(availabilityLabel({ status: 'available', remaining_places: 8 })).toBe('Има места');
    expect(availabilityLabel({ status: 'limited', remaining_places: 2 })).toBe('Остават 2 места');
    expect(availabilityLabel({ status: 'available', remaining_places: null })).toBe('Има места');
  });

  it('summarizes weekly and biweekly group schedules in Bulgarian', () => {
    expect(summarizePublicSchedule({ frequency: 'weekly', timezone: 'Europe/Sofia', slots: [{ weekday: 1, starts_at: '16:00', duration_minutes: 60 }] })).toBe('Всеки понеделник · 16:00–17:00');
    expect(summarizePublicSchedule({ frequency: 'biweekly', timezone: 'Europe/Sofia', slots: [{ weekday: 4, starts_at: '17:30', duration_minutes: 90 }] })).toBe('През седмица, четвъртък · 17:30–19:00');
  });

  it('omits empty optional sections without inventing public content', () => {
    expect(optionalActivitySections({ about: '  ', benefits: [], methodology: null, faq: [], instructor_intro: 'Опитен екип.' })).toEqual([
      { key: 'instructor_intro', title: 'Нашият екип', value: 'Опитен екип.' },
    ]);
  });

  it('offers navigation only for a physical place with a trusted URL', () => {
    expect(navigationAction({ type: 'physical', navigation_url: 'https://maps.example/school' })).toEqual({ label: 'Навигация до мястото', href: 'https://maps.example/school' });
    expect(navigationAction({ type: 'online', navigation_url: 'https://maps.example/online' })).toBeNull();
    expect(navigationAction({ type: 'physical', navigation_url: null })).toBeNull();
  });

  it('states clearly that enrollment is a future stage and never promises success', () => {
    expect(enrollmentCta).toBe('Записването ще бъде добавено в следващ етап');
  });
});
