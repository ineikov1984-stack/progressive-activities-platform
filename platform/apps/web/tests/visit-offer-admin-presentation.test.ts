import { describe, expect, it } from 'vitest';
import { campaignMembershipChange, changeVisitOfferGroup, newVisitOfferDraft, stepSixPrice, stepSixPriceText, visitOfferDraftFromRecord, visitOfferErrors, visitOfferErrorTarget, visitOfferPayload } from '../src/features/catalog/admin/presentation';
import type { ActivityDraft, ActivityGroup, OpenDayCampaign, PriceOption, VisitOffer } from '../src/features/catalog/admin/api';

const groups: ActivityGroup[] = [{ id: 'group-1', offering_id: 'offering-1', version: 1, name: 'Начинаещи', age_label: '6–8 г.', status: 'published', capacity_min: 1, capacity_max: 12, instructor_ids: [], schedule_pattern: { timezone: 'Europe/Sofia', frequency: 'weekly' }, price_options: [], conflicts: [], sessions: [{ id: 'session-1', starts_at: '2030-11-01T14:00:00Z', ends_at: '2030-11-01T15:00:00Z', status: 'scheduled' }, { id: 'cancelled', starts_at: '2030-11-02T14:00:00Z', ends_at: '2030-11-02T15:00:00Z', status: 'cancelled' }] }];
const valid = () => ({ ...newVisitOfferDraft('activity-1'), title: 'Пробно занимание', group_id: 'group-1', session_ids: ['session-1'] });
const now = Date.parse('2030-10-01T00:00:00Z');

describe('visit offer administration', () => {
  it('permits published content edits with unchanged historical sessions but rejects new past attachments', () => {
    const past = { id: 'past', starts_at: '2030-09-01T14:00:00Z', ends_at: '2030-09-01T15:00:00Z', status: 'scheduled' };
    const changedGroups = [{ ...groups[0], sessions: [...groups[0].sessions, past, { ...past, id: 'new-past' }] }];
    const draft = { ...valid(), session_ids: ['past', 'session-1'] };
    expect(visitOfferErrors(draft, changedGroups, true, now, ['past', 'session-1'])).toEqual({});
    expect(visitOfferErrors({ ...draft, session_ids: [...draft.session_ids, 'new-past'] }, changedGroups, true, now, ['past', 'session-1']).session_ids).toBeTruthy();
    expect(visitOfferErrors(draft, changedGroups, true, now).session_ids).toBeTruthy();
  });
  it.each([['type', 0], ['fee_type', 3], ['booking_deadline_hours', 2], ['waitlist_enabled', 2], ['price_option_id', 3], ['currency', 3], ['activity_id', 1]])('routes protected %s errors to the actual field in step %i', (key, step) => {
    expect(visitOfferErrorTarget(String(key))).toEqual({ step, controlId: `visit-field-${key}` });
  });
  it('routes retired or unknown fields to the review step', () => {
    for (const key of ['cancellation_outcome', 'terms_version', 'version']) expect(visitOfferErrorTarget(key)).toEqual({ step: 4, controlId: 'visit-heading-4' });
  });
  it('rejects detach or move from a cancelled campaign as locked membership rather than a version conflict', () => {
    const campaigns = [{ id: 'source', status: 'cancelled', version: 4, visit_offers: [{ id: 'offer-1' }] }, { id: 'target', status: 'published', version: 8, visit_offers: [] }] as OpenDayCampaign[];
    const offer = { id: 'offer-1', version: 7, campaign_id: 'source' };
    expect(() => campaignMembershipChange(offer, '', campaigns)).toThrow('Кампанията е отменена');
    expect(() => campaignMembershipChange(offer, 'target', campaigns)).toThrow('Кампанията е отменена');
    expect(campaignMembershipChange(offer, 'source', campaigns)).toBeNull();
  });
  it('submits a trial or single visit without a fee or terms of its own', () => {
    for (const type of ['trial', 'single'] as const) {
      const payload = visitOfferPayload({ ...valid(), type, fee_type: 'paid', amount: '12,50', fee_reason: 'Материали' });
      expect(payload).toMatchObject({ type, booking_deadline_hours: 12, waitlist_enabled: true, visit_limit: 3 });
      for (const field of ['fee_type', 'amount_cents', 'currency', 'fee_reason', 'cancellation_deadline_hours', 'cancellation_outcome', 'cancellation_policy', 'terms_version', 'trial_limit_per_academic_year', 'single_visit_limit', 'unlimited_single_visits', 'no_show_trial_limit']) expect(payload).not.toHaveProperty(field);
    }
    expect(visitOfferPayload({ ...valid(), type: 'open_day' })).toMatchObject({ fee_type: 'free', amount_cents: 0, currency: 'EUR' });
  });
  it('blocks publishing a trial or single visit without a step-6 price, but not an open day or a draft save', () => {
    expect(visitOfferErrors(valid(), groups, true, now, [], true).price_option_id).toBe('Добавете цена „Пробно занимание“ в стъпка 6 „Цени и записване“.');
    expect(visitOfferErrors({ ...valid(), type: 'single' }, groups, true, now, [], true).price_option_id).toContain('„Еднократно посещение“');
    expect(visitOfferErrors({ ...valid(), type: 'open_day' }, groups, true, now, [], true)).toEqual({});
    expect(visitOfferErrors(valid(), groups, false, now, [], true)).toEqual({});
    expect(visitOfferErrors(valid(), groups, true, now)).toEqual({});
  });
  it('takes the group price of the mode first, then the activity price, and never for an open day', () => {
    const price = (enrollment_mode: string, amount_cents: number, is_available = true): PriceOption => ({ enrollment_mode, unit: 'session', amount_cents, is_available });
    const activity = { offering: { price_options: [price('trial', 0), price('single', 1500), price('monthly', 6000)] } } as unknown as Pick<ActivityDraft, 'offering'>;
    const group = { price_options: [price('single', 1200), price('trial', 500, false)] };
    expect(stepSixPrice(activity, group, 'single')?.amount_cents).toBe(1200);
    expect(stepSixPrice(activity, group, 'trial')?.amount_cents).toBe(0);
    expect(stepSixPrice(activity, undefined, 'single')?.amount_cents).toBe(1500);
    expect(stepSixPrice(activity, group, 'open_day')).toBeUndefined();
    expect(stepSixPrice({ offering: null }, { price_options: [] }, 'trial')).toBeUndefined();
    expect(stepSixPriceText({ amount_cents: 0, unit: 'session' })).toBe('Безплатно');
    expect(stepSixPriceText({ amount_cents: 1500, unit: 'session' })).toMatch(/^15,00\s€ за занимание$/);
  });
  it('requires a public fee reason for an open day and converts decimals without rounding', () => {
    const draft = { ...valid(), type: 'open_day' as const, fee_type: 'paid' as const, amount: '19,99' };
    expect(visitOfferErrors(draft, groups, true, now).fee_reason).toBeTruthy();
    expect(visitOfferPayload({ ...draft, fee_reason: 'Материали' }).amount_cents).toBe(1999);
    expect(visitOfferPayload({ ...draft, amount: '0.29', fee_reason: 'Материали' }).amount_cents).toBe(29);
    for (const amount of ['1.005', '1e2', '-1', '0', '']) expect(visitOfferErrors({ ...draft, amount }, groups, false, now).amount_cents).toBeTruthy();
  });
  it('blocks publication for missing, foreign, cancelled, past sessions and schedule conflicts', () => {
    for (const session_ids of [[], ['foreign'], ['cancelled']]) expect(visitOfferErrors({ ...valid(), session_ids }, groups, true, now).session_ids).toBeTruthy();
    expect(visitOfferErrors(valid(), groups, true, Date.parse('2031-01-01')).session_ids).toBeTruthy();
    expect(visitOfferErrors(valid(), [{ ...groups[0], conflicts: [{ severity: 'blocking', kind: 'room', message: 'Заета зала' }] }], true, now).session_ids).toContain('Заета зала');
    expect(visitOfferErrors({ ...valid(), session_ids: [] }, groups, false, now)).toEqual({});
  });
  it('preserves content, fee and places while clearing sessions when changing group', () => {
    const draft = { ...valid(), description: 'Работа с глина', amount: '12,50', fee_reason: 'Материали', visit_limit: 4 };
    expect(changeVisitOfferGroup(draft, 'group-2')).toMatchObject({ group_id: 'group-2', session_ids: [], description: 'Работа с глина', amount: '12,50', fee_reason: 'Материали', visit_limit: 4 });
    expect(changeVisitOfferGroup(draft, 'group-1').session_ids).toEqual(['session-1']);
  });
  it('round-trips saved sections, prices, sessions and optimistic version after reload', () => {
    const record = { ...visitOfferPayload({ ...valid(), type: 'open_day', description: 'Запазено описание', fee_type: 'paid', amount: '12,50', fee_reason: 'Материали' }), id: 'offer-1', status: 'draft', version: 7, campaign_id: 'campaign-1', sessions: [groups[0].sessions[0]], published_at: null, closed_at: null, cancelled_at: null } as VisitOffer;
    const draft = visitOfferDraftFromRecord(record);
    expect(draft).toMatchObject({ description: 'Запазено описание', amount: '12.50', session_ids: ['session-1'], campaign_id: 'campaign-1' });
    expect(visitOfferPayload(draft, record.version)).toMatchObject({ version: 7, description: 'Запазено описание', amount_cents: 1250, session_ids: ['session-1'] });
    expect(visitOfferPayload(draft, record.version)).not.toHaveProperty('campaign_id');
  });
  it('keeps unrelated campaign members and supplies offer and source campaign versions', () => {
    const campaigns = [{ id: 'source', version: 4, visit_offers: [{ id: 'offer-1' }] }, { id: 'target', version: 8, visit_offers: [{ id: 'other' }] }] as OpenDayCampaign[];
    expect(campaignMembershipChange({ id: 'offer-1', version: 7, campaign_id: 'source' }, 'target', campaigns)).toEqual({ id: 'target', body: { version: 8, visit_offer_ids: ['other', 'offer-1'], visit_offer_versions: { 'offer-1': 7 }, source_campaign_versions: { source: 4 } } });
    expect(campaignMembershipChange({ id: 'offer-1', version: 7, campaign_id: 'source' }, '', campaigns)).toEqual({ id: 'source', body: { version: 4, visit_offer_ids: [], visit_offer_versions: { 'offer-1': 7 }, source_campaign_versions: {} } });
    expect(campaignMembershipChange({ id: 'offer-1', version: 7, campaign_id: 'source' }, 'source', campaigns)).toBeNull();
  });
});
