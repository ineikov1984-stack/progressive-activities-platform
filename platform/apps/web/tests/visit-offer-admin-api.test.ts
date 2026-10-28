import { afterEach, describe, expect, it, vi } from 'vitest';
import { CatalogApiError, loadOpenDayCampaigns, loadVisitOffers, saveVisitOffer, transitionVisitOffer, updateCampaignMembership } from '../src/features/catalog/admin/api';

const response = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
describe('visit offer admin API contract', () => {
  afterEach(() => vi.unstubAllGlobals());
  it('reads offers and campaigns using the active tenant', async () => {
    const fetch = vi.fn().mockResolvedValueOnce(response({ visit_offers: [] })).mockResolvedValueOnce(response({ campaigns: [] })); vi.stubGlobal('fetch', fetch);
    expect(await loadVisitOffers('org-1')).toEqual([]); expect(await loadOpenDayCampaigns('org-1')).toEqual([]);
    expect(fetch).toHaveBeenCalledWith('/backend/api/v1/catalog/visit-offers', expect.objectContaining({ credentials: 'include', headers: expect.objectContaining({ 'X-Organization-Id': 'org-1' }) }));
    expect(fetch).toHaveBeenCalledWith('/backend/api/v1/catalog/open-day-campaigns', expect.anything());
  });
  it('creates and updates offers without losing the submitted optimistic version', async () => {
    const fetch = vi.fn().mockImplementation(async () => response({ visit_offer: { id: 'offer-1', version: 4 } })); vi.stubGlobal('fetch', fetch); vi.stubGlobal('document', { cookie: 'XSRF-TOKEN=token' });
    const body = { activity_id: 'a', group_id: 'g', type: 'trial' as const, title: 'Пробно', visit_limit: 3 };
    await saveVisitOffer('org-1', body);
    expect(fetch).toHaveBeenLastCalledWith('/backend/api/v1/catalog/visit-offers', expect.objectContaining({ method: 'POST', body: JSON.stringify(body), headers: expect.objectContaining({ 'X-XSRF-TOKEN': 'token' }) }));
    expect(await saveVisitOffer('org-1', { ...body, version: 3 }, 'offer-1')).toMatchObject({ version: 4 });
    expect(fetch).toHaveBeenLastCalledWith('/backend/api/v1/catalog/visit-offers/offer-1', expect.objectContaining({ method: 'PATCH', body: JSON.stringify({ ...body, version: 3 }) }));
  });
  it('uses the current version for publication and preserves server field errors', async () => {
    const fetch = vi.fn().mockResolvedValue(response({ message: 'Невалидни данни', errors: { session_ids: ['Има конфликт в графика.'] } }, 422)); vi.stubGlobal('fetch', fetch); vi.stubGlobal('document', { cookie: 'XSRF-TOKEN=token' });
    await expect(transitionVisitOffer('org-1', 'offer-1', 4, 'publish')).rejects.toMatchObject({ status: 422, fieldErrors: { session_ids: ['Има конфликт в графика.'] } } satisfies Partial<CatalogApiError>);
    expect(fetch).toHaveBeenLastCalledWith('/backend/api/v1/catalog/visit-offers/offer-1/publish', expect.objectContaining({ body: JSON.stringify({ version: 4 }) }));
  });
  it('sends membership versions through the campaign endpoint', async () => {
    const fetch = vi.fn().mockResolvedValue(response({ campaign: { id: 'campaign-1', version: 6 } })); vi.stubGlobal('fetch', fetch); vi.stubGlobal('document', { cookie: 'XSRF-TOKEN=token' });
    const body = { version: 5, visit_offer_ids: ['offer-1'], visit_offer_versions: { 'offer-1': 4 }, source_campaign_versions: { source: 2 } };
    await updateCampaignMembership('org-1', 'campaign-1', body);
    expect(fetch).toHaveBeenLastCalledWith('/backend/api/v1/catalog/open-day-campaigns/campaign-1', expect.objectContaining({ method: 'PATCH', body: JSON.stringify(body) }));
  });
});
