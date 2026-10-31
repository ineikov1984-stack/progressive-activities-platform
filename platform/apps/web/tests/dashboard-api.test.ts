import { afterEach, describe, expect, it, vi } from 'vitest';
import { loadDashboard, loadOrganizationAccess, loadTeaching, loadWorkspaceOverview, withAccess } from '../src/features/dashboard/api';

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
        permissions: ['create', 'update', 'invite', 'finance', 'view', 'team.view', 'team.manage'],
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

  it('asks for the last actions only when the role sees the team, so a teacher gets an empty list without a request', async () => {
    const fetchMock = vi.fn();
    vi.stubGlobal('fetch', fetchMock);

    const overview = await loadWorkspaceOverview('org-1', false, false);

    expect(overview).toEqual({ invitations: [], auditEvents: [] });
    expect(fetchMock).not.toHaveBeenCalled();
  });
  it('adds the added rights to the menu and the buttons, under the names the screens use, and never takes any away', async () => {
    const organization = { id: 'org-1', name: 'Школа', slug: 'school', status: 'active', role: 'viewer', permissions: ['view', 'enrollment.view', 'team.view'] };
    const access = { role: 'viewer', scope: 'groups', abilities: ['enrollment.enroll', 'enrollment.view', 'contacts.view'], groups: ['g-1'], activities: [] };
    const merged = withAccess(organization, access);
    expect(merged.permissions).toEqual(expect.arrayContaining(['view', 'team.view', 'enrollment.enroll', 'enrollment.manage', 'contacts.view', 'enrollment.contacts']));
    expect(merged.access).toEqual(access);
    expect(withAccess(organization, { ...access, abilities: [] }).permissions).toEqual(organization.permissions);
    expect(withAccess(organization, null)).toBe(organization);

    const fetchMock = vi.fn().mockResolvedValueOnce(jsonResponse({ organization: {}, access })).mockResolvedValueOnce(jsonResponse({ message: 'Server Error' }, 500));
    vi.stubGlobal('fetch', fetchMock);
    expect(await loadOrganizationAccess('org-1')).toEqual(access);
    expect(fetchMock).toHaveBeenCalledWith('/backend/api/v1/context/organization', expect.objectContaining({ headers: expect.objectContaining({ 'X-Organization-Id': 'org-1' }) }));
    // When it cannot be read, the role's rights stay.
    expect(await loadOrganizationAccess('org-1')).toBeNull();
  });

  it('loads the groups the user teaches in the selected organization', async () => {
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(jsonResponse({ groups: [{ group_id: 'g-1', group_name: 'Вторник', activity_title: 'Шах' }] }))
      .mockResolvedValueOnce(jsonResponse({}));
    vi.stubGlobal('fetch', fetchMock);

    expect((await loadTeaching('org-1')).map((group) => group.group_id)).toEqual(['g-1']);
    expect(fetchMock).toHaveBeenCalledWith('/backend/api/v1/me/teaching', expect.objectContaining({ headers: expect.objectContaining({ 'X-Organization-Id': 'org-1' }) }));
    expect(await loadTeaching('org-1')).toEqual([]);
  });
});
