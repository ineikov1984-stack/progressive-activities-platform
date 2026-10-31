import { describe, expect, it } from 'vitest';
import type { GroupAssignment, TeamMember } from '../src/features/team/api';
import {
  accessAction, activityLine, agoText, assignmentPhase, changeableRoles, daysText, expiresText, firstErrorField, groupLink, groupsByActivity, groupsMeta, groupSummary,
  groupTags, guestModeFor, historyItem, historyText, invitableRoles, invitationFold, matchesFilters, memberRole, memberStatus, noGroupsText,
  foundText, lastLoginText, parseStateFilter, periodText, roleLabel, rolesFilterText, stateFilterLabels, teamErrorMessage, tokenFromHash, welcomeText,
} from '../src/features/team/presentation';

// 10:00 in Sofia on 1 November 2026 (EET, UTC+2).
const now = new Date('2026-11-01T08:00:00Z');
const today = '2026-11-01';

const member = (extra: Partial<TeamMember> = {}): TeamMember => ({
  id: 'member-1', full_name: 'Деси Маринова', state: 'profile_only', role: null, instructor_profile_id: 'profile-1',
  groups: [], archived_at: null, invitation: null, version: 1, ...extra,
});
const group = (activity: string, name: string, extra: Partial<GroupAssignment> = {}): GroupAssignment => ({
  group_id: `${activity}-${name}`, label: `${activity} · ${name}`, activity, group: name, role: 'lead', starts_on: null, ends_on: null, version: 1, ...extra,
});
const waiting = { status: 'pending', expires_at: '2026-11-06T09:00:00Z', role: 'finance' };
const expired = { status: 'expired', expires_at: '2026-10-31T09:00:00Z', role: 'finance' };

describe('access', () => {
  it('says in words whether the person has access, has an invitation, has none or is in the archive', () => {
    expect(memberStatus(member({ state: 'login', role: 'coordinator' }), now)).toMatchObject({ key: 'login', label: 'Има достъп' });
    expect(memberStatus(member(), now)).toMatchObject({ key: 'profile_only', label: 'Няма достъп' });
    expect(memberStatus(member({ state: 'archived', archived_at: '2026-10-30T10:00:00Z' }), now)).toMatchObject({ key: 'archived', label: 'В архива' });
    expect(memberStatus(member({ invitation: waiting }), now)).toEqual({ key: 'invited', label: 'Изпратена покана', detail: 'изтича след 5 дни', tone: 'info' });
    expect(memberStatus(member({ invitation: expired }), now)).toMatchObject({ key: 'invitation_expired', label: 'Поканата изтече' });
  });

  it('treats an invitation whose end has passed as expired, even before the server says so', () => {
    expect(memberStatus(member({ invitation: { ...waiting, expires_at: '2026-11-01T07:59:00Z' } }), now).key).toBe('invitation_expired');
  });

  it('counts the days to the end of an invitation in Sofia, with the right plural', () => {
    expect(expiresText('2026-11-01T21:00:00Z', now)).toBe('изтича днес');
    // 23:30 UTC on 1 November is already 2 November in Sofia.
    expect(expiresText('2026-11-01T23:30:00Z', now)).toBe('изтича утре');
    expect(expiresText('2026-11-03T09:00:00Z', now)).toBe('изтича след 2 дни');
    expect(expiresText('2026-11-22T09:00:00Z', now)).toBe('изтича след 21 дни');
    expect([daysText(1), daysText(2), daysText(21)]).toEqual(['1 ден', '2 дни', '21 дни']);
  });

  it('puts the button "Изпрати покана" next to "Няма достъп" and a link to send again, only for those who manage the team', () => {
    expect(accessAction(member(), true, now)).toEqual({ label: 'Изпрати покана', href: '/dashboard/team/member-1?invite=1', kind: 'button' });
    expect(accessAction(member({ invitation: waiting }), true, now)).toMatchObject({ label: 'Изпрати отново', kind: 'link' });
    expect(accessAction(member({ invitation: expired }), true, now)).toMatchObject({ label: 'Изпрати нова покана', kind: 'link' });
    expect(accessAction(member(), false, now)).toBeNull();
    expect(accessAction(member({ state: 'login', role: 'instructor' }), true, now)).toBeNull();
    expect(accessAction(member({ state: 'archived' }), true, now)).toBeNull();
  });

  it('folds the invitation in the profile into a sentence and a button', () => {
    expect(invitationFold(member(), now)).toEqual({ sentence: 'Няма достъп до системата. Присъствията в групите, които води, отбелязвате вие.', action: 'Изпрати покана' });
    expect(invitationFold(member({ instructor_profile_id: null }), now).sentence).toBe('Няма достъп до системата.');
    expect(invitationFold(member({ invitation: waiting }), now)).toEqual({ sentence: 'Изпратена покана · изтича след 5 дни.', action: 'Изпрати поканата отново' });
    expect(invitationFold(member({ invitation: expired }), now)).toEqual({ sentence: 'Поканата изтече.', action: 'Изпрати нова покана' });
  });

  it('names the access filter as the column', () => {
    expect(Object.values(stateFilterLabels)).toEqual(['Има достъп', 'Изпратена покана', 'Няма достъп', 'В архива']);
  });
});

describe('roles', () => {
  it('names the roles in Bulgarian; someone invited has the role of the invitation, not "no role"', () => {
    expect(['organization_owner', 'organization_admin', 'coordinator', 'instructor', 'finance', 'viewer'].map(roleLabel))
      .toEqual(['Собственик', 'Администратор', 'Координатор', 'Преподавател', 'Счетоводител', 'Само преглед']);
    expect(roleLabel('something_new')).toBe('Член на екипа');
    expect(memberRole(member({ instructor_profile_id: null, invitation: waiting }))).toBe('finance');
    expect(memberRole(member())).toBe('instructor');
    expect(memberRole(member({ instructor_profile_id: null }))).toBeNull();
    expect(memberRole(member({ state: 'login', role: 'coordinator', invitation: waiting }))).toBe('coordinator');
  });

  it('never invites an owner, and only the owner invites or makes an administrator', () => {
    expect(invitableRoles('organization_owner')).toEqual(['instructor', 'coordinator', 'organization_admin', 'finance', 'viewer']);
    expect(invitableRoles('organization_admin')).toEqual(['instructor', 'coordinator', 'finance', 'viewer']);
    expect(changeableRoles('organization_admin', 'instructor')).toEqual(['coordinator', 'instructor', 'finance', 'viewer']);
    expect(changeableRoles('organization_admin', 'organization_owner')).toBeNull();
    expect(changeableRoles('coordinator', 'viewer')).toBeNull();
  });

  it('welcomes a person with the role of the accepted invitation', () => {
    expect(welcomeText('coordinator')).toBe('Вече сте в екипа като координатор.');
    expect(welcomeText('viewer')).toBe('Вече сте в екипа с право само на преглед.');
  });
});

describe('groups', () => {
  const ivan = [
    group('Шахмат', 'Група 1 · 1.–2. клас'), group('Шахмат', 'Група 2 · 3.–4. клас'),
    group('Баскетбол', 'Група 1 · Начинаещи'), group('Баскетбол', 'Група 2 · Напреднали'),
    group('Вокална поп-група', 'Напреднали изпълнители', { role: 'assistant' }),
  ];

  it('gathers the groups by activity, the activities in alphabetical order', () => {
    expect(groupsByActivity(ivan).map((item) => [item.activity, item.groups.length])).toEqual([['Баскетбол', 2], ['Вокална поп-група', 1], ['Шахмат', 2]]);
  });

  it('variant Б: the count and the activities as chips', () => {
    expect(groupSummary(ivan)).toEqual({ count: '5 групи', chips: ['Баскетбол · 2', 'Вокална поп-група', 'Шахмат · 2'] });
    expect(groupSummary([group('Рисуване', 'Група 1')]).count).toBe('1 група');
  });

  it('marks only an assistant and a start still ahead', () => {
    expect(groupTags(group('Шахмат', 'Група 1'), today)).toEqual([]);
    expect(groupTags(group('Шахмат', 'Група 1', { role: 'assistant' }), today)).toEqual(['помощник']);
    expect(groupTags(group('Рисуване', 'Група 3', { starts_on: '2026-12-01' }), today)).toEqual(['от 1 декември']);
    expect(groupTags(group('Рисуване', 'Група 3', { starts_on: '2026-11-01' }), today)).toEqual([]);
  });

  it('says how many groups in how many activities', () => {
    expect(groupsMeta(ivan)).toBe('5 групи в 3 дейности');
    expect(groupsMeta([group('Рисуване', 'Група 1'), group('Рисуване', 'Група 3')])).toBe('2 групи');
    expect(groupsMeta([group('Рисуване', 'Група 1'), group('Шахмат', 'Група 1')])).toBe('2 групи в 2 дейности');
  });

  it('opens the schedule of a group, and the link says so with the visible name in it', () => {
    const link = groupLink(group('Шахмат', 'Група 2 · 3.–4. клас', { group_id: 'a1b2c3d4-0000-4000-8000-000000000001' }));
    expect(link).toEqual({
      href: '/dashboard/schedule?group=a1b2c3d4-0000-4000-8000-000000000001',
      label: 'Графикът на Шахмат · Група 2 · 3.–4. клас',
    });
    expect(link.label.endsWith('Група 2 · 3.–4. клас')).toBe(true);
    // An id never breaks out of its parameter.
    expect(groupLink(group('Шахмат', 'Група 1', { group_id: 'x&view=month' })).href).toBe('/dashboard/schedule?group=x%26view%3Dmonth');
  });

  it('tells a teacher without groups from someone who does not lead groups', () => {
    expect(noGroupsText(member())).toBe('Още не води група');
    expect(noGroupsText(member({ instructor_profile_id: null }))).toBe('Не води групи');
  });

  it('tells a running, a coming and an ended group apart and writes the period', () => {
    expect(assignmentPhase({ starts_on: null, ends_on: null }, today)).toBe('current');
    expect(assignmentPhase({ starts_on: '2026-12-01', ends_on: null }, today)).toBe('coming');
    expect(assignmentPhase({ starts_on: null, ends_on: '2026-10-31' }, today)).toBe('ended');
    expect(periodText({ starts_on: '2026-09-01', ends_on: '2026-11-30' })).toBe('от 1 септември до 30 ноември');
  });
});

describe('history', () => {
  it('writes "what — who" with nouns, so there is no grammatical gender', () => {
    expect(historyText({ action: 'team.member_added', by: 'Мария Колева' })).toBe('Добавяне в екипа — Мария Колева');
    expect(historyText({ action: 'team.invitation_accepted', by: 'Иван Петров' })).toBe('Приемане на поканата — Иван Петров');
    expect(historyText({ action: 'team.role_changed', by: null })).toBe('Смяна на ролята');
    expect(historyItem({ action: 'team.member_archived', by: 'Мария Колева' })).toEqual({ what: 'Преместване в архива', who: 'Мария Колева' });
    expect(historyText({ action: 'team.profile_linked', by: null })).toBe('Разрешение да води групи');
    expect(historyText({ action: 'team.something_new', by: null })).toBe('Промяна');
  });

  it('never shows an action name to the person', () => {
    for (const action of ['team.member_updated', 'team.invitation_sent', 'catalog.assignment_added', 'unknown.action']) {
      expect(historyText({ action, by: 'Мария' })).not.toMatch(/[A-Za-z_]/);
    }
  });
});

describe('errors', () => {
  it('keeps the server\'s Bulgarian reason and replaces technical English', () => {
    expect(teamErrorMessage(422, { message: 'Първо приключете назначението в „Шахмат · Вторник“.', errors: { member: ['Първо приключете назначението в „Шахмат · Вторник“.'] } }))
      .toBe('Първо приключете назначението в „Шахмат · Вторник“.');
    expect(teamErrorMessage(409, { message: 'Staff member version conflict.' })).toBe('Някой току-що промени тези данни. Заредете новите данни и опитайте отново.');
    expect(teamErrorMessage(403, { message: 'This action is unauthorized.' })).toBe('Вашата роля не позволява това. Обърнете се към собственика на организацията.');
    expect(teamErrorMessage(500, {})).toBe('Действието не беше изпълнено. Проверете интернет връзката и опитайте отново.');
  });
});

describe('the list filters', () => {
  const people = [
    member({ id: 'a', full_name: 'Иван Петров', state: 'login', role: 'instructor' }),
    member({ id: 'b', full_name: 'Георги Иванов', instructor_profile_id: null, invitation: waiting }),
    member({ id: 'c', full_name: 'Деси Маринова' }),
  ];
  const ids = (filters: Parameters<typeof matchesFilters>[1]) => people.filter((person) => matchesFilters(person, filters, now)).map((person) => person.id);

  it('searches by name in any case and filters by roles (the invitation\'s too) and access', () => {
    expect(ids({ search: 'иван', roles: [], state: '' })).toEqual(['a', 'b']);
    expect(ids({ search: '', roles: ['instructor'], state: '' })).toEqual(['a', 'c']);
    expect(ids({ search: '', roles: ['finance'], state: '' })).toEqual(['b']);
    // Several roles: anyone with one of them.
    expect(ids({ search: '', roles: ['finance', 'instructor'], state: '' })).toEqual(['a', 'b', 'c']);
    expect(ids({ search: 'иван', roles: ['finance', 'coordinator'], state: '' })).toEqual(['b']);
    expect(ids({ search: '', roles: [], state: 'invited' })).toEqual(['b']);
    expect(ids({ search: '', roles: [], state: 'profile_only' })).toEqual(['c']);
    expect(ids({ search: '', roles: [], state: 'login' })).toEqual(['a']);
  });

  it('names the role filter and the count', () => {
    expect(rolesFilterText([])).toBe('Всички роли');
    expect(rolesFilterText(['instructor'])).toBe('Преподавател');
    expect(rolesFilterText(['instructor', 'viewer'])).toBe('2 роли');
    expect(foundText(0)).toBe('Намерени: 0');
    expect(foundText(12)).toBe('Намерени: 12');
  });

  it('says in Bulgarian how long ago, with the right plural', () => {
    const at = new Date('2026-10-31T12:09:00Z'); // 14:09 in Sofia
    const ago = (minutes: number) => new Date(at.getTime() - minutes * 60_000).toISOString();
    expect(agoText(ago(0), at)).toBe('току-що');
    expect(agoText(ago(1), at)).toBe('преди 1 минута');
    expect(agoText(ago(5), at)).toBe('преди 5 минути');
    expect(agoText(ago(60), at)).toBe('преди 1 час');
    expect(agoText(ago(3 * 60), at)).toBe('преди 3 часа');
    expect(agoText('2026-10-30T08:00:00Z', at)).toBe('вчера');
    expect(agoText('2026-10-29T08:00:00Z', at)).toBe('преди 2 дни');
    expect(agoText('2026-10-24T08:00:00Z', at)).toBe('преди 1 седмица');
    expect(agoText('2026-10-10T08:00:00Z', at)).toBe('преди 3 седмици');
    expect(agoText('2026-09-30T08:00:00Z', at)).toBe('преди 1 месец');
    expect(agoText('2026-07-01T08:00:00Z', at)).toBe('преди 4 месеца');
    expect(agoText('2025-10-30T08:00:00Z', at)).toBe('преди 1 година');
    expect(agoText('2023-10-01T08:00:00Z', at)).toBe('преди 3 години');
    // A clock a little ahead is still "току-що".
    expect(agoText('2026-10-31T12:10:00Z', at)).toBe('току-що');
  });

  it('says when a person last signed in by calendar days in Sofia, without grammatical gender', () => {
    const at = new Date('2026-10-31T21:30:00Z'); // 23:30 in Sofia
    expect(lastLoginText(null, at)).toBe('още няма записан вход');
    expect(lastLoginText('2026-10-31T05:00:00Z', at)).toBe('последен вход днес');
    // 23:00 UTC on 30 October is already 31 October in Sofia.
    expect(lastLoginText('2026-10-30T23:00:00Z', at)).toBe('последен вход днес');
    expect(lastLoginText('2026-10-30T10:00:00Z', at)).toBe('последен вход вчера');
    expect(lastLoginText('2026-10-29T10:00:00Z', at)).toBe('последен вход преди 2 дни');
  });

  it('writes the last sign-in and activity in the profile, only when the list carries them', () => {
    const at = new Date('2026-10-31T12:14:00Z');
    expect(activityLine({ last_login_at: '2026-10-31T12:09:00Z', last_seen_at: '2026-10-31T12:09:00Z' }, at))
      .toBe('Последен вход: 31 октомври 2026 г. в 14:09 · Последна активност: преди 5 минути');
    expect(activityLine({ last_login_at: null, last_seen_at: null }, at)).toBe('Още няма записан вход.');
    // Signed in before the sign-ins were recorded: the activity is still said.
    expect(activityLine({ last_login_at: null, last_seen_at: '2026-10-31T12:09:00Z' }, at)).toBe('Още няма записан вход · Последна активност: преди 5 минути');
    expect(activityLine({ last_login_at: '2026-10-31T12:09:00Z', last_seen_at: null }, at)).toBe('Последен вход: 31 октомври 2026 г. в 14:09.');
    expect(activityLine({}, at)).toBeNull();
  });

  it('reads the access filter from a link and ignores anything else', () => {
    expect(parseStateFilter('invited')).toBe('invited');
    expect(parseStateFilter('everything')).toBe('');
    expect(parseStateFilter(null)).toBe('');
  });
});

describe('the invitation page', () => {
  it('opens the inviter\'s link at the login and only the e-mail\'s link at a new profile', () => {
    expect(guestModeFor('email')).toBe('register');
    expect(guestModeFor('copy')).toBe('login');
  });

  it('finds the first field with an error in the order of the form', () => {
    expect(firstErrorField({ password_confirmation: 'Паролите не съвпадат.', name: 'Въведете името си.' }, ['name', 'password', 'password_confirmation'])).toBe('name');
    expect(firstErrorField({ name: '' }, ['name'])).toBeUndefined();
  });

  it('takes only a whole token from the fragment', () => {
    const token = 'A1'.repeat(32);
    expect(tokenFromHash(`#${token}`)).toBe(token);
    expect(tokenFromHash('#short')).toBeNull();
    expect(tokenFromHash(`#${token}&utm=x`)).toBeNull();
  });
});
