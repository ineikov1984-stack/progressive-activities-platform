import { describe, expect, it } from 'vitest';
import { newVisitOfferDraft } from '../src/features/catalog/admin/presentation';
import { restoreVisitOfferSetupDraft, saveVisitOfferSetupDraft, visitOfferSetupKey } from '../src/features/catalog/admin/visit-offer-draft';

function storage() {
  const values = new Map<string, string>();
  return { getItem: (key: string) => values.get(key) ?? null, setItem: (key: string, value: string) => { values.set(key, value); }, removeItem: (key: string) => { values.delete(key); } };
}
describe('unfinished visit offer setup draft', () => {
  it('restores all entered sections and the step after a setup detour, scoped to user, tenant and activity', () => {
    const store = storage(); const key = visitOfferSetupKey('user-1', 'org-1', 'activity-1');
    const draft = { ...newVisitOfferDraft('activity-1'), title: 'Незавършено', description: 'Въведено описание', amount: '19,99', fee_reason: 'Материали', session_ids: ['session-1'], visit_limit: NaN };
    saveVisitOfferSetupDraft(store, key, { draft, step: 1 });
    expect(restoreVisitOfferSetupDraft(store, visitOfferSetupKey('user-2', 'org-1', 'activity-1'), 'activity-1')).toBeNull();
    expect(restoreVisitOfferSetupDraft(store, visitOfferSetupKey('user-1', 'org-2', 'activity-1'), 'activity-1')).toBeNull();
    const restored = restoreVisitOfferSetupDraft(store, key, 'activity-1');
    expect(restored).toMatchObject({ step: 1, draft: { title: 'Незавършено', description: 'Въведено описание', amount: '19,99', fee_reason: 'Материали', session_ids: ['session-1'] } });
    expect(restored?.draft.visit_limit).toBeNaN();
    expect(store.getItem(key)).toBeNull();
  });
  it('preserves an existing offer version instead of silently rebasing stored edits', () => {
    const store = storage(); const key = visitOfferSetupKey('u', 'o', 'a');
    saveVisitOfferSetupDraft(store, key, { draft: newVisitOfferDraft('a'), step: 1, offerId: 'offer-1', offerVersion: 7 });
    expect(restoreVisitOfferSetupDraft(store, key, 'a')).toMatchObject({ offerId: 'offer-1', offerVersion: 7 });
  });
  it('ignores corrupt data and drafts for another activity', () => {
    const store = storage(); store.setItem('key', '{invalid');
    expect(restoreVisitOfferSetupDraft(store, 'key', 'a')).toBeNull();
    saveVisitOfferSetupDraft(store, 'key', { draft: newVisitOfferDraft('other'), step: 1 });
    expect(restoreVisitOfferSetupDraft(store, 'key', 'a')).toBeNull();
  });
});
