import { describe, expect, it } from 'vitest';
import { placesText, priceText } from '../src/features/enrollment/presentation';

describe('enrollment presentation', () => {
  it('describes free places in plain Bulgarian', () => {
    expect(placesText(null)).toBe('Без ограничение на местата');
    expect(placesText(0)).toBe('Няма свободни места');
    expect(placesText(1)).toBe('1 свободно място');
    expect(placesText(4)).toBe('4 свободни места');
  });

  it('shows the price that was agreed when the parent applied', () => {
    expect(priceText({ amount_cents: 4500, currency: 'EUR', unit: 'month' })).toMatch(/45,00\s*€ на месец/);
    expect(priceText({ amount_cents: 0, currency: 'EUR', unit: 'session' })).toBe('Безплатно');
  });
});
