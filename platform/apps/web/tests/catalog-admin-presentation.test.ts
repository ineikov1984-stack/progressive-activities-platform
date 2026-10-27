import { describe, expect, it } from 'vitest';
import { activityStatusLabel, availabilityLabel, buildSchedulePattern, compactOptionalSections, conflictPresentation, formatEur, mergeActivitySections, parseEuroCents, priceOptionsPayload, slugPreview, summarizeSchedule } from '../src/features/catalog/admin/presentation';

describe('catalog admin presentation', () => {
  it('formats integer euro cents for Bulgarian readers without float input', () => {
    expect(formatEur(1300)).toBe('13,00 €');
    expect(() => formatEur(13.5)).toThrow('Цената трябва да е в цели евроцентове.');
  });
  it('presents activity and price availability states in Bulgarian', () => {
    expect(activityStatusLabel('draft')).toBe('Чернова');
    expect(activityStatusLabel('published')).toBe('Публикувана');
    expect(availabilityLabel(true)).toBe('Достъпна за записване');
    expect(availabilityLabel(false)).toBe('Временно недостъпна');
  });
  it('creates a readable preview slug from a Bulgarian title', () => {
    expect(slugPreview('  Школа по шах № 1  ')).toBe('shkola-po-shah-1');
  });
  it('omits blank optional content while preserving meaningful structured sections', () => {
    expect(compactOptionalSections({
      about: '  ', methodology: 'Учение чрез практика', benefits: ['', 'Концентрация'],
      faq: [{ question: '', answer: '' }, { question: 'За начинаещи ли е?', answer: 'Да.' }],
      gallery: [{ path: '', alt: '' }, { path: 'catalog/chess.webp', alt: 'Деца играят шах' }],
      participation_terms: null, instructor_intro: undefined,
    })).toEqual({
      methodology: 'Учение чрез практика', benefits: ['Концентрация'],
      faq: [{ question: 'За начинаещи ли е?', answer: 'Да.' }],
      gallery: [{ path: 'catalog/chess.webp', alt: 'Деца играят шах' }],
    });
  });
  it('summarizes weekly and biweekly schedules with their local times', () => {
    expect(summarizeSchedule({
      timezone: 'Europe/Sofia', frequency: 'weekly', starts_on: '2026-11-02', ends_on: '2027-01-31',
      patterns: [{ weekday: 1, starts_at: '16:00', duration_minutes: 60 }, { weekday: 4, starts_at: '17:30', duration_minutes: 90 }],
    })).toBe('Всяка седмица · понеделник 16:00–17:00; четвъртък 17:30–19:00');
    expect(summarizeSchedule({
      timezone: 'Europe/Sofia', frequency: 'biweekly', starts_on: '2026-11-02', ends_on: '2027-01-31',
      patterns: [{ weekday: 3, starts_at: '15:00', duration_minutes: 45 }],
    })).toContain('През седмица');
  });
  it('distinguishes publication blockers from shared-space warnings', () => {
    expect(conflictPresentation({ severity: 'blocking', kind: 'instructor', message: 'Застъпване' })).toEqual({ label: 'Блокира публикуването', tone: 'danger', message: 'Застъпване' });
    expect(conflictPresentation({ severity: 'warning', kind: 'shared_location', message: 'Общо пространство' }).label).toBe('Провери преди публикуване');
  });
  it('parses only plain positive euro decimals with at most two fractional digits', () => {
    expect(parseEuroCents('1')).toBe(100); expect(parseEuroCents('1,05')).toBe(105); expect(parseEuroCents('0.01')).toBe(1);
    for (const invalid of ['1.005', '1e2', '-1', '0', '  ', '1,2,3']) expect(() => parseEuroCents(invalid)).toThrow('Въведете положителна цена с най-много два знака след десетичната запетая.');
  });
  it('preserves unedited FAQ and gallery while updating editable optional sections', () => {
    expect(mergeActivitySections({ about: 'Стар текст', faq: [{ question: 'Въпрос?', answer: 'Отговор.' }], gallery: [{ path: 'catalog/photo.webp', alt: 'Деца в залата' }] }, { about: 'Нов текст', benefits: ['Концентрация'] })).toEqual({ about: 'Нов текст', benefits: ['Концентрация'], faq: [{ question: 'Въпрос?', answer: 'Отговор.' }], gallery: [{ path: 'catalog/photo.webp', alt: 'Деца в залата' }] });
  });
  it('builds a multi-slot schedule with exclusions and added sessions', () => {
    expect(buildSchedulePattern({ startDate: '2026-11-02', endDate: '2026-12-20', frequency: 'weekly', slots: [{ weekday: 1, starts_at: '16:00', duration_minutes: 60 }, { weekday: 4, starts_at: '17:30', duration_minutes: 90 }], excludedDates: '2026-11-16\n2026-12-07', addedDates: [{ date: '2026-11-18', starts_at: '15:00', duration_minutes: 60 }] })).toEqual({ start_date: '2026-11-02', end_date: '2026-12-20', frequency: 'weekly', timezone: 'Europe/Sofia', slots: [{ weekday: 1, starts_at: '16:00', duration_minutes: 60 }, { weekday: 4, starts_at: '17:30', duration_minutes: 90 }], excluded_dates: ['2026-11-16', '2026-12-07'], added_dates: [{ date: '2026-11-18', starts_at: '15:00', duration_minutes: 60 }] });
  });
  it('serializes every edited enrollment option without losing its conditions', () => {
    expect(priceOptionsPayload([
      { id: 'price-1', enrollment_mode: 'trial', unit: 'session', amount: '12,50', currency: 'EUR', is_available: true, terms: 'Само веднъж' },
      { id: 'price-2', enrollment_mode: 'monthly', unit: 'month', amount: '40', currency: 'EUR', is_available: false, cancellation_policy: 'До 25-о число', payment_due: 'До 10-о число' },
    ])).toEqual([
      { enrollment_mode: 'trial', unit: 'session', amount_cents: 1250, currency: 'EUR', is_available: true, terms: 'Само веднъж' },
      { enrollment_mode: 'monthly', unit: 'month', amount_cents: 4000, currency: 'EUR', is_available: false, cancellation_policy: 'До 25-о число', payment_due: 'До 10-о число' },
    ]);
  });
});
