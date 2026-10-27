import { afterEach, describe, expect, it, vi } from 'vitest';
import { createInvitation, loadDashboard } from '../src/features/dashboard/api';

const jsonResponse = (body: unknown, status = 200) => new Response(JSON.stringify(body), {
  status,
  headers: { 'Content-Type': 'application/json' },
});

describe('dashboard API', () => {
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('loads the user, selects an organization and returns its invitations', async () => {
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(jsonResponse({ user: { id: 'user-1', name: 'Демо собственик', email: 'owner@demo.local' } }))
      .mockResolvedValueOnce(jsonResponse({ organizations: [{
        id: 'org-1',
        name: 'Progressive Academy Demo',
        slug: 'progressive-academy-demo',
        status: 'active',
        role: 'organization_owner',
        permissions: ['create', 'update', 'invite', 'finance', 'view'],
      }] }))
      .mockResolvedValueOnce(jsonResponse({ invitations: [] }));
    vi.stubGlobal('document', { cookie: 'XSRF-TOKEN=test-token' });
    vi.stubGlobal('fetch', fetchMock);

    const dashboard = await loadDashboard();

    expect(dashboard.user.name).toBe('Демо собственик');
    expect(dashboard.organization.id).toBe('org-1');
    expect(dashboard.invitations).toEqual([]);
    expect(fetchMock).toHaveBeenNthCalledWith(3, '/backend/api/v1/invitations', expect.objectContaining({
      headers: expect.objectContaining({ 'X-Organization-Id': 'org-1' }),
    }));
  });

  it('returns a useful Bulgarian message when dashboard loading fails', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('network unavailable')));

    await expect(loadDashboard()).rejects.toThrow('Не успяхме да заредим административното табло.');
  });

  it('creates an invitation through the real invitation endpoint contract', async () => {
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(new Response(null, { status: 204 }))
      .mockResolvedValueOnce(jsonResponse({
        invitation: { id: 'invite-1', email: 'teacher@example.test', role: 'instructor', status: 'pending' },
        token: 'one-time-token',
      }, 201));
    vi.stubGlobal('fetch', fetchMock);

    const result = await createInvitation('org-1', 'teacher@example.test', 'instructor');

    expect(result.token).toBe('one-time-token');
    expect(fetchMock).toHaveBeenLastCalledWith('/backend/api/v1/invitations', expect.objectContaining({
      method: 'POST',
      headers: expect.objectContaining({ 'X-Organization-Id': 'org-1' }),
      body: JSON.stringify({ email: 'teacher@example.test', role: 'instructor' }),
    }));
  });
});
