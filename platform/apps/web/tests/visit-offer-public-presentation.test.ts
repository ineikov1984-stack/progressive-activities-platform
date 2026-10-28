import { describe, expect, it } from 'vitest';
import * as presentation from '../src/features/catalog/public/presentation';

describe('public visit offer presentation', () => {
  it.each([
    ['available', 'Има места'], ['limited', 'Остават 2 места'],
    ['last_place', 'Последно място'], ['full', 'Няма свободни места'], ['closed', 'Записването е затворено'],
  ] as const)('renders the server %s state without deriving it from capacity', (status, label) => {
    expect(presentation.availabilityLabel({ status, remaining_places: 2 })).toBe(label);
  });

  it('limits badges to availability and the server special offer, keeping audience separate', () => {
    expect(presentation.publicBadges({ availability: { status: 'last_place', remaining_places: 1 }, special_offer: 'trial' })).toEqual(['Последно място', 'Пробно посещение']);
    expect(presentation.publicBadges({ availability: { status: 'full', remaining_places: 0 }, special_offer: 'open_day' })).toEqual(['Няма свободни места', 'Отворени врати']);
    expect(presentation.publicBadges({ availability: { status: 'available', remaining_places: null }, special_offer: 'single' })).toEqual(['Има места', 'Еднократно посещение']);
    expect(presentation.audienceLabel({ audience: ' 6–8 г. · I–II клас ', age_label: '6–8 г.' })).toBe('6–8 г. · I–II клас');
    expect(presentation.audienceLabel({ audience: ' ', age_label: '6–8 г.' })).toBe('6–8 г.');
  });

  it('preserves the authoritative card label and from flag and detail from_price semantics', () => {
    expect(presentation.cardPriceLabel({ label: 'Безплатно', from: false })).toBe('Безплатно');
    expect(presentation.cardPriceLabel({ label: '12,50 € / посещение', from: false })).toBe('12,50 € / посещение');
    expect(presentation.cardPriceLabel({ label: 'От 0,00 € / посещение', from: true })).toBe('От 0,00 € / посещение');
    expect(presentation.cardPriceLabel({ label: '12,50 € / посещение', from: true })).toBe('От 12,50 € / посещение');
    expect(presentation.visitPriceLabel({ fee_type: 'from_price', amount_cents: 1250, currency: 'EUR' })).toBe('От 12,50 € / посещение');
    expect(presentation.visitPriceLabel({ fee_type: 'free', amount_cents: 0, currency: 'EUR' })).toBe('Безплатно');
    expect(presentation.cardPriceLabel(null)).toBe('Все още не е обявено');
  });

  it('filters whitespace strings and invalid optional entries before deciding section visibility', () => {
    expect(presentation.optionalActivitySections({ about: ' ', benefits: [' ', ' Концентрация '], methodology: '\n', gallery: [{ url: ' ', alt: ' ' }], faq: [{ question: ' ', answer: 'Да' }, { question: 'Как?', answer: ' ' }] })).toEqual([{ key: 'benefits', title: 'Какво развива заниманието?', value: ['Концентрация'] }]);
  });

  it.each([['apply', 'Има възможност за записване'], ['waitlist', 'Има списък на чакащи'], ['closed', 'Записването е затворено']] as const)('provides honest %s information without an enrollment href', (action, label) => {
    const result = presentation.offerAction(action);
    expect(result.label).toBe(label);
    expect(result).not.toHaveProperty('href');
  });

  it('requires an explicit choice for multiple offers without choosing an offer or session', () => {
    expect(presentation.offerTarget(2)).toEqual({ href: '#visit-offers', label: 'Избери посещение' });
    expect(presentation.offerTarget(1)).toEqual({ href: '#visit-offers', label: 'Виж посещението' });
    expect(presentation.offerTarget(0)).toEqual({ href: '#groups', label: 'Виж графика' });
  });

  it('formats actual local session time including timezone, without changing action eligibility', () => {
    expect(presentation.visitSchedule({ starts_at: '2035-11-03T23:30:00Z', ends_at: '2035-11-04T00:30:00Z', timezone: 'Asia/Tokyo' })).toContain('08:30–09:30 · Asia/Tokyo');
    expect(presentation.visitSchedule({ starts_at: '2035-11-03T23:30:00Z', ends_at: '2035-11-04T00:30:00Z', timezone: 'Asia/Tokyo' })).toContain('4 ноември');
  });

  it.each([['refund', 'Възстановяване на сумата'], ['credit', 'Кредит за бъдещо посещение'], ['non_refundable', 'Сумата не се възстановява']] as const)('keeps the public %s cancellation outcome visible even without prose', (outcome, label) => {
    expect(presentation.cancellationSummary(outcome)).toBe(label);
  });

  it('shows every published weekly slot in practical group information', () => {
    expect(presentation.groupScheduleLines({ frequency: 'weekly', timezone: 'Europe/Sofia', slots: [{ weekday: 1, starts_at: '16:00', duration_minutes: 60 }, { weekday: 4, starts_at: '17:30', duration_minutes: 90 }] })).toEqual(['Всеки понеделник · 16:00–17:00 · Europe/Sofia', 'Всеки четвъртък · 17:30–19:00 · Europe/Sofia']);
    expect(presentation.groupScheduleLines(null)).toEqual(['Все още не е обявено']);
  });
});
