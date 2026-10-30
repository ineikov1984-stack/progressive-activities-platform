import { describe, expect, it } from 'vitest';
import type { ActivityDraft } from '../src/features/catalog/admin/api';
import { amountText, blankPrice, checkOffering, offeringFromActivity, type OfferingDraft } from '../src/features/catalog/admin/wizard/offering';

const draft = (changes: Partial<OfferingDraft> = {}): OfferingDraft => ({
  location_id: null, lead_instructor_profile_id: null, contact_email: 'info@example.test', enrollment_instructions: '', cancellation_policy: '', terms: '', payment_terms: {},
  prices: [{ ...blankPrice(), amount: '30,00' }], ...changes,
});

describe('checkOffering', () => {
  it('sends valid prices in euro cents with only the fields the API accepts', () => {
    const { payload, problems } = checkOffering(draft({ prices: [{ ...blankPrice(), id: 'x', amount: '30,5', terms: ' ' }] }));
    expect(problems).toEqual({});
    expect(payload.price_options).toEqual([{ enrollment_mode: 'monthly', unit: 'month', amount_cents: 3050, currency: 'EUR', terms: null, is_available: true }]);
  });

  it('keeps saving other fields when a price or the email is wrong', () => {
    const { payload, problems } = checkOffering(draft({ contact_email: 'не-е-имейл', enrollment_instructions: ' Пишете ни ', prices: [{ ...blankPrice(), amount: 'abc' }] }));
    expect(Object.keys(problems).sort()).toEqual(['contact_email', 'price_0']);
    expect(payload.price_options).toBeUndefined();
    expect(payload.contact_email).toBeUndefined();
    expect(payload.enrollment_instructions).toBe('Пишете ни');
  });

  it('rejects the same way of enrolling twice', () => {
    const { problems } = checkOffering(draft({ prices: [{ ...blankPrice(), amount: '30' }, { ...blankPrice(), amount: '40' }] }));
    expect(problems.price_1).toMatch(/вече има цена/);
  });

  it('needs an email for questions and offers the organization\'s', () => {
    expect(checkOffering(draft({ contact_email: ' ' })).problems.contact_email).toBe('Въведете имейл — на него родителите пишат с въпроси.');
    const activity = { offering: { id: 'o', version: 1, contact_email: null, organization_contact_email: 'school@example.test', price_options: [] } } as unknown as ActivityDraft;
    expect(offeringFromActivity(activity)?.contact_email).toBe('school@example.test');
    const own = { offering: { id: 'o', version: 1, contact_email: 'chess@example.test', organization_contact_email: 'school@example.test', price_options: [] } } as unknown as ActivityDraft;
    expect(offeringFromActivity(own)?.contact_email).toBe('chess@example.test');
  });

  it('sends the terms chosen for each way of paying', () => {
    expect(checkOffering(draft()).payload.payment_terms).toEqual({});
    expect(checkOffering(draft({ payment_terms: { monthly: 'set-1', visit: 'set-2' } })).payload.payment_terms).toEqual({ monthly: 'set-1', visit: 'set-2' });
    // An empty map from PHP arrives as an array.
    const activity = { offering: { id: 'o', version: 1, payment_terms: [], price_options: [] } } as unknown as ActivityDraft;
    expect(offeringFromActivity(activity)?.payment_terms).toEqual({});
  });

  it('formats cents for the input field', () => {
    expect(amountText(1500)).toBe('15,00');
  });
});
