import { describe, expect, it } from 'vitest';
import { activityStatusLabel, availabilityLabel, compactOptionalSections, conflictPresentation, formatEur, slugPreview, summarizeSchedule } from '../src/features/catalog/admin/presentation';

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
});
