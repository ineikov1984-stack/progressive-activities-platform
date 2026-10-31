import { afterEach, describe, expect, it, vi } from 'vitest';
import { addMember, loadAccess, loadTeam, lookupInvitation, saveAccess, signedInUser, TeamApiError, updateMember } from '../src/features/team/api';

const jsonResponse = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });

describe('team API', () => {
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('adds a person once per key, in the selected organization, with the CSRF token', async () => {
    const fetchMock = vi.fn().mockResolvedValueOnce(jsonResponse({ member: { id: 'member-1', full_name: 'Деси Маринова' } }, 201));
    vi.stubGlobal('document', { cookie: 'XSRF-TOKEN=test-token' });
    vi.stubGlobal('fetch', fetchMock);

    const added = await addMember('org-1', { full_name: 'Деси Маринова', phone: '0888 000 333', email: null, teaches: true }, 'key-1');

    expect(added.member.id).toBe('member-1');
    expect(added.sent).toBeNull();
    expect(fetchMock).toHaveBeenCalledWith('/backend/api/v1/team/members', expect.objectContaining({
      method: 'POST',
      credentials: 'include',
      headers: expect.objectContaining({ 'Idempotency-Key': 'key-1', 'X-Organization-Id': 'org-1', 'X-XSRF-TOKEN': 'test-token' }),
      body: JSON.stringify({ full_name: 'Деси Маринова', phone: '0888 000 333', email: null, teaches: true }),
    }));
  });

  it('adds, invites and gives the rights in one request, and hands back the invitation with its link', async () => {
    const invitation = { id: 'inv-1', staff_member_id: 'member-2', email: 'partner@example.test', role: 'viewer', status: 'pending', expires_at: '2026-11-07T10:00:00Z' };
    const fetchMock = vi.fn().mockResolvedValueOnce(jsonResponse({ member: { id: 'member-2', full_name: 'Галина Тодорова' }, invitation, link: 'https://example.test/invite#x' }, 201));
    vi.stubGlobal('document', { cookie: 'XSRF-TOKEN=test-token' });
    vi.stubGlobal('fetch', fetchMock);
    const access = { scope: 'groups' as const, groups: [{ group_id: 'g-1' }], grants: [{ ability: 'enrollment.enroll', expires_on: null }], template: null };

    const added = await addMember('org-1', {
      full_name: 'Галина Тодорова', phone: '0888 000 444', email: 'partner@example.test', teaches: false,
      invitation: { role: 'viewer', email: 'partner@example.test' }, access,
    }, 'key-2');

    expect(added.sent).toEqual({ invitation, link: 'https://example.test/invite#x' });
    expect(fetchMock).toHaveBeenCalledWith('/backend/api/v1/team/members', expect.objectContaining({
      body: JSON.stringify({
        full_name: 'Галина Тодорова', phone: '0888 000 444', email: 'partner@example.test', teaches: false,
        invitation: { role: 'viewer', email: 'partner@example.test' }, access,
      }),
    }));
  });

  it('reads the card "Права", with what a new role takes away, and saves it whole under its version', async () => {
    const card = { member_id: 'member-2', state: 'access', role: 'viewer', version: 3 };
    const fetchMock = vi.fn(async () => jsonResponse({ access: card }));
    vi.stubGlobal('document', { cookie: 'XSRF-TOKEN=test-token' });
    vi.stubGlobal('fetch', fetchMock);

    expect(await loadAccess('org-1', 'member-2', 'coordinator')).toEqual(card);
    expect(fetchMock).toHaveBeenLastCalledWith('/backend/api/v1/team/members/member-2/access?role=coordinator', expect.objectContaining({
      headers: expect.objectContaining({ 'X-Organization-Id': 'org-1' }),
    }));
    await saveAccess('org-1', 'member-2', { scope: 'all', groups: [], grants: [], template: null }, 3);
    expect(fetchMock).toHaveBeenLastCalledWith('/backend/api/v1/team/members/member-2/access', expect.objectContaining({
      method: 'PUT', body: JSON.stringify({ scope: 'all', groups: [], grants: [], template: null, version: 3 }),
    }));
  });

  it('loads the archived people with ?archived=1', async () => {
    const fetchMock = vi.fn().mockResolvedValueOnce(jsonResponse({ members: [], can_manage: true }));
    vi.stubGlobal('fetch', fetchMock);

    await loadTeam('org-1', true);

    expect(fetchMock).toHaveBeenCalledWith('/backend/api/v1/team?archived=1', expect.objectContaining({ method: 'GET' }));
  });

  it('sends the invitation token in the body, never in the address', async () => {
    const token = 'A1'.repeat(32);
    const fetchMock = vi.fn().mockResolvedValueOnce(jsonResponse({ invitation: {
      organization_name: 'Школа', role: 'instructor', role_label: 'Преподавател', expires_at: '2026-11-07T10:00:00+00:00', status: 'pending', link: 'copy',
    } }));
    vi.stubGlobal('fetch', fetchMock);

    const invitation = await lookupInvitation(token);

    expect(invitation).toMatchObject({ role_label: 'Преподавател', link: 'copy' });
    expect(invitation.email).toBeUndefined();
    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(url).toBe('/backend/api/v1/invitations/lookup');
    expect(url).not.toContain(token);
    expect(init.body).toBe(JSON.stringify({ token }));
  });

  it('keeps the Bulgarian reasons of a refusal and says a stale version in words', async () => {
    vi.stubGlobal('document', { cookie: 'XSRF-TOKEN=test-token' });
    vi.stubGlobal('fetch', vi.fn()
      .mockResolvedValueOnce(jsonResponse({ message: 'Въведете телефон.', errors: { phone: ['Въведете телефон.'] } }, 422))
      .mockResolvedValueOnce(jsonResponse({ message: 'Staff member version conflict.' }, 409)));

    const refused = await updateMember('org-1', 'member-1', { version: 1, phone: '' }).catch((reason: unknown) => reason);
    expect(refused).toBeInstanceOf(TeamApiError);
    expect((refused as TeamApiError).message).toBe('Въведете телефон.');
    expect((refused as TeamApiError).fieldErrors).toEqual({ phone: ['Въведете телефон.'] });

    const stale = await updateMember('org-1', 'member-1', { version: 1, full_name: 'Х' }).catch((reason: unknown) => reason);
    expect((stale as TeamApiError).status).toBe(409);
    expect((stale as TeamApiError).message).toBe('Някой току-що промени тези данни. Заредете новите данни и опитайте отново.');
  });

  it('knows a guest from a signed-in person', async () => {
    vi.stubGlobal('fetch', vi.fn()
      .mockResolvedValueOnce(jsonResponse({ message: 'Unauthenticated.' }, 401))
      .mockResolvedValueOnce(jsonResponse({ user: { id: 'user-1', name: 'Иван Петров', email: 'ivan@example.test' } })));

    expect(await signedInUser()).toBeNull();
    expect(await signedInUser()).toMatchObject({ name: 'Иван Петров' });
  });
});
