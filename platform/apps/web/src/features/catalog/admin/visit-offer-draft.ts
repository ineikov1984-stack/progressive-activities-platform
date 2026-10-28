import { newVisitOfferDraft, type VisitOfferDraft } from './presentation';

export type VisitOfferSetupDraft = { draft: VisitOfferDraft; step: number; offerId?: string; offerVersion?: number };
export const visitOfferSetupKey = (userId: string, organizationId: string, activityId: string) => `visit-offer-setup:${userId}:${organizationId}:${activityId}`;

export function saveVisitOfferSetupDraft(storage: Pick<Storage, 'setItem'>, key: string, snapshot: VisitOfferSetupDraft): void {
  storage.setItem(key, JSON.stringify({ schema: 1, ...snapshot }));
}

export function restoreVisitOfferSetupDraft(storage: Pick<Storage, 'getItem' | 'removeItem'>, key: string, activityId: string): VisitOfferSetupDraft | null {
  try {
    const raw = storage.getItem(key);
    if (!raw) return null;
    storage.removeItem(key);
    const snapshot = JSON.parse(raw);
    if (snapshot.schema !== 1 || snapshot.draft?.activity_id !== activityId || !Number.isInteger(snapshot.step) || snapshot.step < 0 || snapshot.step > 5) return null;
    if (snapshot.offerId !== undefined && (typeof snapshot.offerId !== 'string' || !Number.isSafeInteger(snapshot.offerVersion) || snapshot.offerVersion < 1)) return null;
    const draft = newVisitOfferDraft(activityId);
    for (const field of Object.keys(draft) as (keyof VisitOfferDraft)[]) {
      const value = snapshot.draft[field]; const fallback = draft[field];
      if (field === 'session_ids') {
        if (!Array.isArray(value) || value.some((id: unknown) => typeof id !== 'string')) return null;
      } else if (field === 'no_show_trial_limit') {
        if (value !== null && typeof value !== 'number') return null;
      } else if (typeof fallback === 'number') {
        if (value !== null && typeof value !== 'number') return null;
      } else if (value === null) {
        if (!['description', 'audience_age_label', 'audience_class_label', 'fee_reason', 'cancellation_policy', 'terms_version'].includes(field)) return null;
      } else if (typeof value !== typeof fallback) return null;
      Object.assign(draft, { [field]: typeof fallback === 'number' && value === null ? NaN : value });
    }
    if (!['trial', 'single', 'open_day'].includes(draft.type) || !['free', 'paid', 'from_price'].includes(draft.fee_type) || !['refund', 'credit', 'non_refundable'].includes(draft.cancellation_outcome) || draft.currency !== 'EUR') return null;
    return { draft, step: snapshot.step, ...(snapshot.offerId ? { offerId: snapshot.offerId, offerVersion: snapshot.offerVersion } : {}) };
  } catch { return null; }
}
