import { afterEach, describe, expect, it, vi } from 'vitest';
import { loadDashboard, loadWorkspaceOverview } from '../src/features/dashboard/api';

const jsonResponse = (body: unknown, status = 200) => new Response(JSON.stringify(body), {
  status,
  headers: { 'Content-Type': 'application/json' },
});

describe('dashboard API', () => {
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('loads the user, selects an organization and returns invitations and audit events', async () => {
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
      .mockResolvedValueOnce(jsonResponse({ invitations: [] }))
      .mockResolvedValueOnce(jsonResponse({ events: [{
        id: 'audit-1',
        action: 'organization.invitation_created',
        subject_type: 'invitation',
        subject_id: 'invite-1',
        created_at: '2026-10-27T18:00:00+00:00',
      }] }));
    vi.stubGlobal('document', { cookie: 'XSRF-TOKEN=test-token' });
    vi.stubGlobal('fetch', fetchMock);

    const dashboard = await loadDashboard();

    expect(dashboard.user.name).toBe('Демо собственик');
    expect(dashboard.organization.id).toBe('org-1');
    expect(dashboard.invitations).toEqual([]);
    expect(dashboard.auditEvents).toHaveLength(1);
    expect(fetchMock).toHaveBeenNthCalledWith(3, '/backend/api/v1/invitations', expect.objectContaining({
      headers: expect.objectContaining({ 'X-Organization-Id': 'org-1' }),
    }));
    expect(fetchMock).toHaveBeenNthCalledWith(4, '/backend/api/v1/audit-events', expect.objectContaining({
      headers: expect.objectContaining({ 'X-Organization-Id': 'org-1' }),
    }));
  });

  it('returns a useful Bulgarian message when dashboard loading fails', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('network unavailable')));

    await expect(loadDashboard()).rejects.toThrow('Не успяхме да заредим административното табло.');
  });

  it('asks for invitations only when the role invites, so the other roles see the home screen without an error', async () => {
    const fetchMock = vi.fn().mockResolvedValueOnce(jsonResponse({ events: [] }));
    vi.stubGlobal('fetch', fetchMock);

    const overview = await loadWorkspaceOverview('org-1', false);

    expect(overview).toEqual({ invitations: [], auditEvents: [] });
    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(fetchMock).toHaveBeenCalledWith('/backend/api/v1/audit-events', expect.anything());
  });
});
