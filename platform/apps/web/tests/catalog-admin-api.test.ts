import { afterEach, describe, expect, it, vi } from 'vitest';
import { CatalogApiError, createActivity, loadCatalogWorkspace, publishActivity, uploadCatalogMedia } from '../src/features/catalog/admin/api';

const jsonResponse = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });

describe('catalog admin API', () => {
  afterEach(() => vi.unstubAllGlobals());
  it('loads all reusable catalog resources in the selected tenant', async () => {
    const fetchMock = vi.fn().mockResolvedValueOnce(jsonResponse({ activities: [] })).mockResolvedValueOnce(jsonResponse({ profile: null })).mockResolvedValueOnce(jsonResponse({ locations: [{ id: 'location-1', name: 'Зала 1' }] })).mockResolvedValueOnce(jsonResponse({ instructors: [{ id: 'instructor-1', public_name: 'Иван Иванов' }] }));
    vi.stubGlobal('fetch', fetchMock);
    const workspace = await loadCatalogWorkspace('org-1');
    expect(workspace.locations[0]?.name).toBe('Зала 1');
    expect(fetchMock).toHaveBeenNthCalledWith(1, '/backend/api/v1/catalog/activities', expect.objectContaining({ credentials: 'include', headers: expect.objectContaining({ 'X-Organization-Id': 'org-1' }) }));
  });
  it('maps Laravel validation details to a useful catalog error', async () => {
    vi.stubGlobal('document', { cookie: 'XSRF-TOKEN=csrf-token' });
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse({ message: 'Въведените данни са невалидни.', errors: { title: ['Заглавието е задължително.'] } }, 422)));
    await expect(createActivity('org-1', { title: '', category: 'Спорт', summary: 'Описание', age_label: '1.–4. клас' })).rejects.toMatchObject({ message: 'Въведените данни са невалидни.', status: 422, fieldErrors: { title: ['Заглавието е задължително.'] } } satisfies Partial<CatalogApiError>);
  });
  it('uses CSRF and the selected organization for publication writes', async () => {
    const fetchMock = vi.fn().mockResolvedValue(jsonResponse({ activity: { id: 'activity-1', status: 'published', version: 3 } }));
    vi.stubGlobal('document', { cookie: 'XSRF-TOKEN=csrf-token' }); vi.stubGlobal('fetch', fetchMock);
    await publishActivity('org-1', 'activity-1', 2);
    expect(fetchMock).toHaveBeenCalledWith('/backend/api/v1/catalog/activities/activity-1/publish', expect.objectContaining({ method: 'POST', credentials: 'include', headers: expect.objectContaining({ 'X-Organization-Id': 'org-1', 'X-XSRF-TOKEN': 'csrf-token' }), body: JSON.stringify({ version: 2 }) }));
  });
  it('uploads real form data without forcing a multipart content type', async () => {
    const fetchMock = vi.fn().mockResolvedValue(jsonResponse({ media: { path: 'catalog/photo.webp' } }, 201));
    vi.stubGlobal('document', { cookie: 'XSRF-TOKEN=csrf-token' }); vi.stubGlobal('fetch', fetchMock);
    await uploadCatalogMedia('org-1', new File(['image'], 'photo.webp', { type: 'image/webp' }), 'activity-1');
    const [, options] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(options.body).toBeInstanceOf(FormData); expect(new Headers(options.headers).has('Content-Type')).toBe(false);
  });
});
