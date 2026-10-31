import { afterEach, describe, expect, it, vi } from 'vitest';
import type { OrganizationAccess, TeachingGroup } from '../src/features/dashboard/api';
import { ProfileApiError, updateOwnContact } from '../src/features/me/api';
import { canOpenGroups, contactErrors, groupCountText, myGroups, ownGrants, ownScope, phoneText, scopeText } from '../src/features/me/presentation';
import { accessSummary } from '../src/features/team/rights';

const group = (extra: Partial<TeachingGroup>): TeachingGroup => ({
  group_id: 'g-1', group_name: 'Група 1 · 1.–2. клас', activity_id: 'a-1', activity_title: 'Шахмат', label: 'Шахмат · Група 1 · 1.–2. клас',
  basis: 'assignment', role: 'lead', starts_on: null, ends_on: null, next_session_starts_at: null, ...extra,
});
const access = (extra: Partial<OrganizationAccess> = {}): OrganizationAccess => ({
  role: 'instructor', scope: 'own', abilities: ['view', 'enrollment.view_own', 'contacts.view'], groups: [], activities: [], ...extra,
});
const jsonResponse = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });

describe('my profile', () => {
  afterEach(() => vi.unstubAllGlobals());

  it('writes a Bulgarian mobile number as people read it', () => {
    expect(phoneText('+359888000111')).toBe('0888 000 111');
    expect(phoneText('+441234567890')).toBe('+441234567890');
    expect(phoneText(null)).toBe('');
  });

  it('asks for a name and a phone before sending', () => {
    expect(contactErrors({ name: ' ', phone: '' })).toEqual({ name: 'Напишете името си.', phone: 'Въведете телефон — на него ви търси организацията.' });
    expect(contactErrors({ name: 'Иван Петров', phone: '0888 000 111' })).toEqual({});
  });

  it('takes the own added rights with their last day, or what is beyond the role without one', () => {
    expect(ownGrants('instructor', access({ grants: [{ ability: 'contacts.view', expires_on: '2027-06-30' }] }))).toEqual({ 'contacts.view': '2027-06-30' });
    expect(ownGrants('instructor', access())).toEqual({ 'contacts.view': null });
    expect(ownGrants('viewer', access({ role: 'viewer', abilities: ['view', 'enrollment.view', 'team.view', 'attendance.view_month'] }))).toEqual({});
    expect(ownGrants('instructor', undefined)).toEqual({});
    expect(ownScope('instructor', access({ scope: 'groups' }))).toBe('groups');
    expect(ownScope('instructor', undefined)).toBe('own');
    expect(scopeText('own')).toBe('своите групи');
  });

  it('says what a teacher can do with the same words as "Екип", the added right with its end', () => {
    const summary = accessSummary({ role: 'instructor', scope: 'own', grants: ownGrants('instructor', access({ grants: [{ ability: 'contacts.view', expires_on: '2027-06-30' }] })), teaches: true });
    expect(summary.can[0]).toBe('отбелязва присъствия в своите групи — в деня на заниманието');
    expect(summary.sees).toContain('телефона и имейла на родителите — след „Покажи телефона“ — до 30 юни 2027');
  });

  it('lists the groups by activity with the part and the next session; a substitute with the days', () => {
    const groups = myGroups([
      group({ group_id: 'g-2', group_name: 'Група 3', activity_title: 'Рисуване', basis: 'substitute', starts_on: '2026-11-04', ends_on: '2026-11-04' }),
      group({ next_session_starts_at: '2026-11-05T14:00:00Z' }),
      group({ group_id: 'g-3', group_name: 'Група 2 · 3.–4. клас', role: 'assistant' }),
    ]);
    expect(groups.map((item) => item.activity)).toEqual(['Рисуване', 'Шахмат']);
    expect(groups[0].groups[0]).toMatchObject({ part: 'Заместник', tone: 'substitute' });
    expect(groups[0].groups[0].detail).toMatch(/^Замествате на /);
    expect(groups[1].groups[0]).toMatchObject({ part: 'Водещ', href: '/dashboard/enrollments?view=groups&group=g-1' });
    expect(groups[1].groups[0].detail).toMatch(/^Следващо: четвъртък, 5 ноември/);
    expect(groups[1].groups[1]).toMatchObject({ part: 'Помощник', detail: 'Няма предстоящи занимания' });
    expect(groupCountText(1)).toBe('1 група');
    expect(groupCountText(3)).toBe('3 групи');
  });

  it('opens a group\'s list only for those who may see it', () => {
    expect(canOpenGroups(['view', 'enrollment.view_own'])).toBe(true);
    expect(canOpenGroups(['enrollment.view'])).toBe(true);
    expect(canOpenGroups(['view', 'finance.view'])).toBe(false);
  });

  it('saves the name and phone for the active organization and keeps the Bulgarian reason of a refusal', async () => {
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(jsonResponse({ user: { id: 'u-1', name: 'Иван Петров', email: 'ivan@example.test', phone: '+359887123456' } }))
      .mockResolvedValueOnce(jsonResponse({ message: 'Невалидни данни', errors: { phone: ['Проверете телефона — например 0888 123 456 или +359 888 123 456.'] } }, 422));
    vi.stubGlobal('document', { cookie: 'XSRF-TOKEN=test-token' });
    vi.stubGlobal('fetch', fetchMock);

    const user = await updateOwnContact('org-1', { name: 'Иван Петров', phone: '0887 123 456' });
    expect(user.phone).toBe('+359887123456');
    expect(fetchMock).toHaveBeenCalledWith('/backend/api/v1/me/contact', expect.objectContaining({
      method: 'PATCH', credentials: 'include', body: JSON.stringify({ name: 'Иван Петров', phone: '0887 123 456' }),
      headers: expect.objectContaining({ 'X-Organization-Id': 'org-1', 'X-XSRF-TOKEN': 'test-token' }),
    }));
    await updateOwnContact('org-1', { name: 'Иван Петров', phone: '0888' }).then(() => { throw new Error('should fail'); }, (reason: unknown) => {
      expect(reason instanceof ProfileApiError && reason.status).toBe(422);
      expect(reason instanceof ProfileApiError ? reason.fieldErrors.phone?.[0] : '').toMatch(/^Проверете телефона/);
    });
  });
});
