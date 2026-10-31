import { describe, expect, it } from 'vitest';
import type { TeamMember } from '../src/features/team/api';
import {
  assignmentPhase, assignmentText, changeableRoles, daysText, expiresText, firstErrorField, guestModeFor, historySentence, invitableRoles, invitationLine,
  matchesFilters, memberRole, memberStatus, parseStateFilter, periodText, roleAccess, roleLabel, teamErrorMessage, tokenFromHash, waitingAction, welcomeText,
} from '../src/features/team/presentation';

// 10:00 in Sofia on 1 November 2026 (EET, UTC+2).
const now = new Date('2026-11-01T08:00:00Z');

const member = (extra: Partial<TeamMember> = {}): TeamMember => ({
  id: 'member-1', full_name: 'Деси Маринова', state: 'profile_only', role: null, instructor_profile_id: 'profile-1',
  groups: [], archived_at: null, invitation: null, version: 1, ...extra,
});

describe('the state of a person', () => {
  it('says in words whether the person logs in, is invited, has only a profile or is archived', () => {
    expect(memberStatus(member({ state: 'login', role: 'coordinator' }), now)).toMatchObject({ key: 'login', label: 'Влиза в системата' });
    expect(memberStatus(member(), now)).toMatchObject({ key: 'profile_only', label: 'Само профил, без вход' });
    expect(memberStatus(member({ state: 'archived', archived_at: '2026-10-30T10:00:00Z' }), now)).toMatchObject({ key: 'archived', label: 'Архивиран' });
    expect(memberStatus(member({ invitation: { status: 'pending', expires_at: '2026-11-06T09:00:00Z' } }), now))
      .toMatchObject({ key: 'invited', label: 'Поканен · изтича след 5 дни' });
    expect(memberStatus(member({ invitation: { status: 'expired', expires_at: '2026-10-31T09:00:00Z' } }), now))
      .toMatchObject({ key: 'invitation_expired', label: 'Поканата изтече' });
  });

  it('treats an invitation whose end has passed as expired, even before the server says so', () => {
    expect(memberStatus(member({ invitation: { status: 'pending', expires_at: '2026-11-01T07:59:00Z' } }), now).key).toBe('invitation_expired');
  });

  it('counts the days to the end of an invitation in Sofia, with the right plural', () => {
    expect(expiresText('2026-11-01T21:00:00Z', now)).toBe('изтича днес');
    // 23:30 UTC on 1 November is already 2 November in Sofia.
    expect(expiresText('2026-11-01T23:30:00Z', now)).toBe('изтича утре');
    expect(expiresText('2026-11-03T09:00:00Z', now)).toBe('изтича след 2 дни');
    expect(expiresText('2026-11-08T09:00:00Z', now)).toBe('изтича след 7 дни');
    expect(expiresText('2026-11-22T09:00:00Z', now)).toBe('изтича след 21 дни');
    expect(daysText(1)).toBe('1 ден');
    expect(daysText(2)).toBe('2 дни');
    expect(daysText(21)).toBe('21 дни');
  });
});

describe('what waits for a person', () => {
  it('asks to invite a profile without a login, or to send the invitation again', () => {
    expect(waitingAction(member(), true)).toEqual({ label: 'Покани да влиза', href: '/dashboard/team/member-1?invite=1' });
    expect(waitingAction(member({ invitation: { status: 'pending', expires_at: '2026-11-06T09:00:00Z' } }), true)?.label).toBe('Изпрати поканата пак');
    expect(waitingAction(member({ invitation: { status: 'expired', expires_at: '2026-10-31T09:00:00Z' } }), true)?.label).toBe('Изпрати поканата пак');
  });

  it('asks nothing of those who do not manage the team, nor for someone who logs in or is archived', () => {
    expect(waitingAction(member(), false)).toBeNull();
    expect(waitingAction(member({ state: 'login', role: 'instructor' }), true)).toBeNull();
    expect(waitingAction(member({ state: 'archived' }), true)).toBeNull();
  });
});

describe('the folded invitation in the profile', () => {
  it('says on one line what the state is and what to do', () => {
    expect(invitationLine(member(), now)).toEqual({ state: 'Не влиза в системата', action: 'Покани да влиза' });
    expect(invitationLine(member({ invitation: { status: 'pending', expires_at: '2026-11-06T09:00:00Z' } }), now))
      .toEqual({ state: 'Поканен · изтича след 5 дни', action: 'Изпрати поканата пак' });
    expect(invitationLine(member({ invitation: { status: 'expired', expires_at: '2026-10-31T09:00:00Z' } }), now))
      .toEqual({ state: 'Поканата изтече', action: 'Изпрати поканата пак' });
  });
});

describe('roles', () => {
  it('names the roles in Bulgarian and a teacher without a login as a teacher', () => {
    expect(['organization_owner', 'organization_admin', 'coordinator', 'instructor', 'finance', 'viewer'].map(roleLabel))
      .toEqual(['Собственик', 'Администратор', 'Координатор', 'Преподавател', 'Счетоводител', 'Само преглед']);
    expect(roleLabel('something_new')).toBe('Член на екипа');
    expect(memberRole(member())).toBe('instructor');
    expect(memberRole(member({ instructor_profile_id: null }))).toBeNull();
    expect(memberRole(member({ state: 'login', role: 'finance' }))).toBe('finance');
  });

  it('describes what each role sees and does not see in two sentences', () => {
    for (const role of ['organization_owner', 'organization_admin', 'coordinator', 'instructor', 'finance', 'viewer']) {
      const access = roleAccess(role);
      expect(access.sees.length > 10).toBe(true);
      expect(access.hidden.length > 5).toBe(true);
    }
    expect(roleAccess('instructor').sees).toContain('своите групи');
    expect(roleAccess('instructor').hidden).toContain('телефоните');
    expect(roleAccess('coordinator').hidden).toContain('телефоните и имейлите на екипа');
    expect(roleAccess('organization_admin').hidden).toContain('финансите');
  });

  it('never invites an owner, and only the owner invites an administrator', () => {
    expect(invitableRoles('organization_owner')).toEqual(['instructor', 'coordinator', 'organization_admin', 'finance', 'viewer']);
    expect(invitableRoles('organization_admin')).toEqual(['instructor', 'coordinator', 'finance', 'viewer']);
    expect(invitableRoles('organization_owner')).not.toContain('organization_owner');
  });

  it('lets the administrator change only the roles below administrator', () => {
    expect(changeableRoles('organization_owner', 'organization_admin')).toContain('organization_owner');
    expect(changeableRoles('organization_admin', 'instructor')).toEqual(['coordinator', 'instructor', 'finance', 'viewer']);
    expect(changeableRoles('organization_admin', 'organization_owner')).toBeNull();
    expect(changeableRoles('coordinator', 'viewer')).toBeNull();
    expect(changeableRoles('organization_owner', null)).toBeNull();
  });

  it('welcomes a person with the role of the accepted invitation', () => {
    expect(welcomeText('coordinator')).toBe('Вече сте в екипа като координатор.');
    expect(welcomeText('instructor')).toContain('като преподавател');
    expect(welcomeText('viewer')).toBe('Вече сте в екипа с право само на преглед.');
  });
});

describe('groups', () => {
  const today = '2026-11-01';

  it('writes an assignment with its role and period', () => {
    expect(assignmentText({ group_id: 'g1', label: 'Шахмат · Група 1', role: 'lead', starts_on: null, ends_on: null, version: 1 })).toBe('Шахмат · Група 1 — водещ');
    expect(assignmentText({ group_id: 'g2', label: 'Шахмат · Група 2', role: 'assistant', starts_on: '2026-12-01', ends_on: null, version: 2 }))
      .toBe('Шахмат · Група 2 — помощник · от 1 декември');
    expect(periodText({ starts_on: '2026-09-01', ends_on: '2026-11-30' })).toBe('от 1 септември до 30 ноември');
  });

  it('tells a running, a coming and an ended assignment apart', () => {
    expect(assignmentPhase({ starts_on: null, ends_on: null }, today)).toBe('current');
    expect(assignmentPhase({ starts_on: '2026-11-01', ends_on: '2026-11-01' }, today)).toBe('current');
    expect(assignmentPhase({ starts_on: '2026-12-01', ends_on: null }, today)).toBe('coming');
    expect(assignmentPhase({ starts_on: null, ends_on: '2026-10-31' }, today)).toBe('ended');
  });
});

describe('history', () => {
  it('turns the audit actions into Bulgarian sentences, with or without who did it', () => {
    expect(historySentence({ action: 'team.member_added', by: 'Мария Колева' })).toBe('Мария Колева добави човека в екипа.');
    expect(historySentence({ action: 'team.role_changed', by: 'Мария Колева' })).toBe('Мария Колева смени ролята.');
    expect(historySentence({ action: 'team.role_changed', by: null })).toBe('Ролята е сменена.');
    expect(historySentence({ action: 'team.invitation_sent', by: 'Иван Петров' })).toBe('Иван Петров изпрати покана за вход.');
    expect(historySentence({ action: 'team.invitation_accepted', by: 'Деси Маринова' })).toBe('Деси Маринова прие поканата и вече влиза в системата.');
    expect(historySentence({ action: 'team.member_archived', by: null })).toBe('Записът е архивиран.');
    expect(historySentence({ action: 'team.profile_linked', by: null })).toBe('Свързан е профил на преподавател.');
    expect(historySentence({ action: 'team.something_new', by: null })).toBe('Направена е промяна.');
  });

  it('never shows an action name to the person', () => {
    for (const action of ['team.member_updated', 'catalog.assignment_added', 'catalog.assignment_ended', 'unknown.action']) {
      expect(historySentence({ action, by: 'Мария' })).not.toMatch(/[A-Za-z_]/);
    }
  });
});

describe('errors', () => {
  it('keeps the server\'s Bulgarian reason and replaces technical English', () => {
    expect(teamErrorMessage(422, { message: 'Първо приключете назначението в „Шахмат · Вторник“.', errors: { member: ['Първо приключете назначението в „Шахмат · Вторник“.'] } }))
      .toBe('Първо приключете назначението в „Шахмат · Вторник“.');
    expect(teamErrorMessage(409, { message: 'Staff member version conflict.' })).toBe('Някой току-що промени тези данни. Заредете ги отново и опитайте пак.');
    expect(teamErrorMessage(409, { message: 'Вече има активна покана за този имейл.' })).toBe('Вече има активна покана за този имейл.');
    expect(teamErrorMessage(403, { message: 'This action is unauthorized.' })).toBe('Нямате право за това действие. Обърнете се към собственика на организацията.');
    expect(teamErrorMessage(500, {})).toBe('Заявката не беше изпълнена. Проверете връзката и опитайте отново.');
  });
});

describe('the list filters', () => {
  const people = [
    member({ id: 'a', full_name: 'Иван Петров', state: 'login', role: 'instructor' }),
    member({ id: 'b', full_name: 'Георги Иванов', role: null, instructor_profile_id: null, invitation: { status: 'pending', expires_at: '2026-11-06T09:00:00Z' } }),
    member({ id: 'c', full_name: 'Деси Маринова' }),
  ];
  const ids = (filters: Parameters<typeof matchesFilters>[1]) => people.filter((person) => matchesFilters(person, filters, now)).map((person) => person.id);

  it('searches by name in any case and filters by role and state', () => {
    expect(ids({ search: 'иван', role: '', state: '' })).toEqual(['a', 'b']);
    expect(ids({ search: '', role: 'instructor', state: '' })).toEqual(['a', 'c']);
    expect(ids({ search: '', role: '', state: 'invited' })).toEqual(['b']);
    expect(ids({ search: '', role: '', state: 'profile_only' })).toEqual(['c']);
    expect(ids({ search: '', role: '', state: 'login' })).toEqual(['a']);
  });

  it('reads the state from a link and ignores anything else', () => {
    expect(parseStateFilter('invited')).toBe('invited');
    expect(parseStateFilter('archived')).toBe('archived');
    expect(parseStateFilter('everything')).toBe('');
    expect(parseStateFilter(null)).toBe('');
  });
});

describe('the invitation page', () => {
  it('opens the inviter\'s link at the login and only the e-mail\'s link at a new profile', () => {
    expect(guestModeFor('email')).toBe('register');
    expect(guestModeFor('copy')).toBe('login');
    expect(guestModeFor('anything else')).toBe('login');
  });

  it('finds the first field with an error in the order of the form', () => {
    const order = ['name', 'password', 'password_confirmation'];
    expect(firstErrorField({ password_confirmation: 'Паролите не съвпадат.', name: 'Напишете името си.' }, order)).toBe('name');
    expect(firstErrorField({ password: 'Паролата трябва да е поне 10 знака.' }, order)).toBe('password');
    expect(firstErrorField({ name: '' }, order)).toBeUndefined();
  });
});

describe('the invitation link', () => {
  it('takes only a whole token from the fragment', () => {
    const token = 'A1'.repeat(32);
    expect(tokenFromHash(`#${token}`)).toBe(token);
    expect(tokenFromHash(token)).toBe(token);
    expect(tokenFromHash('#short')).toBeNull();
    expect(tokenFromHash(`#${token}&utm=x`)).toBeNull();
    expect(tokenFromHash('')).toBeNull();
  });
});
