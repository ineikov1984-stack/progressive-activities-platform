import { afterEach, describe, expect, it, vi } from 'vitest';
import { rememberOrganizationInSession } from '../src/features/dashboard/api';

const ok = () => new Response(JSON.stringify({ organization: {} }), { status: 200, headers: { 'Content-Type': 'application/json' } });

describe('rememberOrganizationInSession', () => {
  afterEach(() => vi.unstubAllGlobals());

  it('selects the organization once per page load with CSRF protection', async () => {
    const fetchMock = vi.fn().mockImplementation(() => Promise.resolve(ok()));
    vi.stubGlobal('document', { cookie: 'XSRF-TOKEN=test-token' });
    vi.stubGlobal('fetch', fetchMock);

    await rememberOrganizationInSession('org-once');
    await rememberOrganizationInSession('org-once');

    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(fetchMock).toHaveBeenCalledWith('/backend/api/v1/context/organization', expect.objectContaining({
      method: 'POST',
      body: JSON.stringify({ organization_id: 'org-once' }),
      headers: expect.objectContaining({ 'X-XSRF-TOKEN': 'test-token' }),
    }));
  });

  it('does not fail the frame when selection fails and retries next time', async () => {
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(new Response(JSON.stringify({ message: 'x' }), { status: 404 }))
      .mockResolvedValueOnce(ok());
    vi.stubGlobal('document', { cookie: 'XSRF-TOKEN=test-token' });
    vi.stubGlobal('fetch', fetchMock);

    await expect(rememberOrganizationInSession('org-retry')).resolves.toBeUndefined();
    await rememberOrganizationInSession('org-retry');

    expect(fetchMock).toHaveBeenCalledTimes(2);
  });
});
