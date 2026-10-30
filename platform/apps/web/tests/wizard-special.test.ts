import { describe, expect, it } from 'vitest';
import type { ActivityGroup, VisitOffer } from '../src/features/catalog/admin/api';
import { offerFacts } from '../src/features/catalog/admin/wizard/step-special';

const offer = (changes: Partial<VisitOffer> = {}): VisitOffer => ({
  id: 'offer-1', activity_id: 'activity-1', group_id: 'group-1', campaign_id: null, type: 'open_day', status: 'published', version: 1,
  title: 'Отворени врати — 14 ноември', description: null, audience_age_label: null, audience_class_label: null, visit_limit: 8,
  fee_type: 'free', amount_cents: 0, currency: 'EUR', fee_reason: null, booking_deadline_hours: 12, waitlist_enabled: true, price: null,
  sessions: [], published_at: null, closed_at: null, cancelled_at: null, ...changes,
});
const groups = [{ id: 'group-1', name: 'Група 1 · Пон 16:00' }] as ActivityGroup[];

describe('step 8 — special visits already made', () => {
  it('names the group, the kind, the price and the places', () => {
    expect(offerFacts(offer(), groups)).toBe('Група 1 · Пон 16:00 · отворени врати · безплатно · 8 места');
    const price = (amount_cents: number) => ({ id: 'price-1', enrollment_mode: 'single', unit: 'session', amount_cents, currency: 'EUR' as const });
    expect(offerFacts(offer({ type: 'single', price: price(1500), visit_limit: 1 }), groups)).toMatch(/^Група 1 · Пон 16:00 · еднократно · 15,00\s€ · 1 място$/);
    expect(offerFacts(offer({ type: 'trial', price: price(0) }), groups)).toBe('Група 1 · Пон 16:00 · пробно · безплатно · 8 места');
    // A trial or single visit is priced from step 6 only; its own old fee fields mean nothing.
    expect(offerFacts(offer({ type: 'trial', fee_type: 'paid', amount_cents: 900 }), groups)).toBe('Група 1 · Пон 16:00 · пробно · без цена от стъпка 6 · 8 места');
    expect(offerFacts(offer({ group_id: 'gone', fee_type: 'from_price', amount_cents: 1000 }), groups)).toMatch(/^отворени врати · от 10,00\s€ · 8 места$/);
  });
});
