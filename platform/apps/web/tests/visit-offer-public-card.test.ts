import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it } from 'vitest';
import type { PublicVisitOffer } from '../src/features/catalog/public/api';
import { VisitOfferList } from '../src/features/catalog/public/visit-offer-list';

const terms = (version: string): PublicVisitOffer['payment_terms'] => ({
  version, billing_kind: 'visit', billing_kind_label: 'Посещение', name: 'Стандартни', preset: 'standard', preset_label: 'Стандартни', schema_version: 1,
  rules: { cancellation: { tiers: [{ min_hours: 24, kept: 0 }, { min_hours: 0, kept: 100 }] }, no_show_kept: 100, refund: 'money' },
});
const offer = (changes: Partial<PublicVisitOffer> = {}): PublicVisitOffer => ({
  visit_offer_id: 'offer-1', session_id: 'session-1', campaign_slug: null, type: 'trial', title: 'Пробно занимание по шахмат',
  description: null, audience: null, starts_at: '2035-11-09T14:00:00Z', ends_at: '2035-11-09T15:00:00Z', timezone: 'Europe/Sofia',
  fee_type: 'free', amount_cents: 0, currency: 'EUR', fee_reason: null, booking_deadline_hours: 12, payment_terms: terms('visit:set-1:3'),
  availability: { status: 'available', remaining_total_places: 3, remaining_offer_places: 3, waitlist_available: false }, action: 'apply',
  ...changes,
});

describe('public visit card — terms from step 6 (docs/product/visits.md, т. 8a.10)', () => {
  it('shows the booking deadline and the visit terms instead of the offer’s own cancellation fields', () => {
    const html = renderToStaticMarkup(createElement(VisitOfferList, { offers: [offer()] }));
    expect(html).toContain('Безплатно');
    expect(html).toContain('До 12 часа преди началото');
    expect(html).toContain('Такси, отсъствия и отказ');
    expect(html).toContain('Отказ:');
    expect(html).toContain('Неявяване:');
    expect(html).toContain('Пълни условия');
    expect(html).toContain('Всяко дете може да посети едно пробно занимание по тази дейност през учебната година.');
    expect(html).not.toContain('Срок за отказ');
    expect(html).not.toContain('При отказ в срок');
  });

  it('says the next card has the same terms once, and repeats them when they differ', () => {
    const same = renderToStaticMarkup(createElement(VisitOfferList, { offers: [offer(), offer({ visit_offer_id: 'offer-2', type: 'single', title: 'Еднократно посещение', fee_type: 'paid', amount_cents: 1500 })] }));
    expect(same.match(/Същите такси, отсъствия и отказ\./g)).toHaveLength(1);
    expect(same.match(/Всяко дете може да посети едно пробно занимание/g)).toHaveLength(1);
    const other = renderToStaticMarkup(createElement(VisitOfferList, { offers: [offer(), offer({ visit_offer_id: 'offer-2', payment_terms: terms('visit:set-2:1') })] }));
    expect(other).not.toContain('Същите такси');
    expect(other.match(/Неявяване:/g)).toHaveLength(2);
  });
});
