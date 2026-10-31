import { shortDay, sofiaDay } from '../enrollment/presentation';
import type { GroupAssignment, HistoryEntry, MemberInvitation, TeamMember } from './api';

/** The roles of a team (OrganizationRole); the platform operator's role is never shown or given here. */
export type TeamRole = 'organization_owner' | 'organization_admin' | 'coordinator' | 'instructor' | 'finance' | 'viewer';

export const roleLabels: Record<TeamRole, string> = {
  organization_owner: 'Собственик',
  organization_admin: 'Администратор',
  coordinator: 'Координатор',
  instructor: 'Преподавател',
  finance: 'Счетоводител',
  viewer: 'Само преглед',
};

const isTeamRole = (role: string | null | undefined): role is TeamRole => typeof role === 'string' && role in roleLabels;

export function roleLabel(role: string | null | undefined): string {
  if (role === 'platform_admin') return 'Оператор на платформата';
  return isTeamRole(role) ? roleLabels[role] : 'Член на екипа';
}

/** What the person does: the role of the login, or "Преподавател" for a teacher without a login. */
export function memberRole(member: Pick<TeamMember, 'role' | 'instructor_profile_id'>): TeamRole | null {
  if (isTeamRole(member.role)) return member.role;
  return member.instructor_profile_id ? 'instructor' : null;
}

/** The short line under each choice of "Какво ще прави". */
export const roleHints: Record<TeamRole, string> = {
  organization_owner: 'Всичко, включително правата на екипа',
  organization_admin: 'Всичко без финансите',
  coordinator: 'Записвания и график',
  instructor: 'Своите групи',
  finance: 'Записвания и финанси',
  viewer: 'Само чете, без промени',
};

/** "Ще вижда / няма да вижда" — two sentences for a role, from what it allows (OrganizationRole::abilities). */
export function roleAccess(role: string): { sees: string; hidden: string } {
  switch (role) {
    case 'organization_owner':
      return { sees: 'всичко в организацията — дейности, записвания, контакти на родителите, финанси и правата на екипа.', hidden: 'няма ограничения в организацията.' };
    case 'organization_admin':
      return { sees: 'дейностите, записванията, контактите на родителите, условията за такси и екипа, който добавя и кани.', hidden: 'финансите. Не дава роля „Собственик“ или „Администратор“.' };
    case 'coordinator':
      return { sees: 'дейностите и групите, записванията и контактите на родителите, кой е в екипа.', hidden: 'телефоните и имейлите на екипа. Не кани хора, не сменя роли и не публикува дейности.' };
    case 'instructor':
      return { sees: 'своите групи и децата в тях (име и клас), дейностите на организацията.', hidden: 'телефоните, имейлите и плащанията на родителите, другите групи и екипа.' };
    case 'finance':
      return { sees: 'записванията по групи, финансите и кой е в екипа.', hidden: 'контактите на родителите и на екипа. Не променя дейности и записвания.' };
    case 'viewer':
      return { sees: 'дейностите, записванията и кой е в екипа — само за четене.', hidden: 'контактите на родителите и на екипа. Не променя нищо.' };
    default:
      return { sees: 'това, което позволява ролята му.', hidden: 'нищо извън ролята му.' };
  }
}

/** Who may be invited with which role (TeamRoles::assertMayInvite): never the owner; an administrator only by the owner. */
export function invitableRoles(actorRole: string): TeamRole[] {
  return actorRole === 'organization_owner'
    ? ['instructor', 'coordinator', 'organization_admin', 'finance', 'viewer']
    : ['instructor', 'coordinator', 'finance', 'viewer'];
}

const administratorGives: TeamRole[] = ['coordinator', 'instructor', 'finance', 'viewer'];

/**
 * The roles a change may give (TeamRoles::assertMayChange): the owner changes any role; the administrator changes
 * only among coordinator, teacher, accountant and viewer. Null when the person's role cannot be changed by this actor.
 */
export function changeableRoles(actorRole: string, from: string | null): TeamRole[] | null {
  if (!isTeamRole(from)) return null;
  if (actorRole === 'organization_owner') return ['organization_owner', 'organization_admin', 'coordinator', 'instructor', 'finance', 'viewer'];
  if (actorRole === 'organization_admin' && administratorGives.includes(from)) return administratorGives;
  return null;
}

/** "Вече сте в екипа като преподавател." after an invitation is accepted. */
export function welcomeText(role: string): string {
  if (role === 'viewer') return 'Вече сте в екипа с право само на преглед.';
  if (role === 'instructor') return 'Вече сте в екипа като преподавател. Ще видите своите групи и децата в тях.';
  return isTeamRole(role) ? `Вече сте в екипа като ${roleLabels[role].toLowerCase()}.` : 'Вече сте в екипа.';
}

/** "1 ден", "2 дни", "21 дни". */
export function daysText(days: number): string {
  return `${days} ${days === 1 ? 'ден' : 'дни'}`;
}

/** Calendar days in Sofia from today to the day of a moment. */
export function daysUntil(moment: string, now: Date): number {
  const day = (value: string) => Date.parse(`${value}T00:00:00Z`);
  return Math.round((day(sofiaDay(moment)) - day(sofiaDay(now))) / 86_400_000);
}

/** "изтича днес", "изтича утре", "изтича след 5 дни". */
export function expiresText(expiresAt: string, now: Date): string {
  const days = daysUntil(expiresAt, now);
  if (days <= 0) return 'изтича днес';
  if (days === 1) return 'изтича утре';
  return `изтича след ${daysText(days)}`;
}

export type StatusKey = 'login' | 'invited' | 'invitation_expired' | 'profile_only' | 'archived';
/** The state in words; the tone only colours it. */
export type MemberStatus = { key: StatusKey; label: string; tone: 'ok' | 'info' | 'warn' | 'muted' };

function invitationOpen(invitation: MemberInvitation | null | undefined, now: Date): boolean {
  return invitation?.status === 'pending' && Date.parse(invitation.expires_at) > now.getTime();
}

export function memberStatus(member: Pick<TeamMember, 'state' | 'invitation'>, now: Date): MemberStatus {
  if (member.state === 'archived') return { key: 'archived', label: 'Архивиран', tone: 'muted' };
  if (member.state === 'login') return { key: 'login', label: 'Влиза в системата', tone: 'ok' };
  if (member.invitation && invitationOpen(member.invitation, now)) {
    return { key: 'invited', label: `Поканен · ${expiresText(member.invitation.expires_at, now)}`, tone: 'info' };
  }
  if (member.invitation) return { key: 'invitation_expired', label: 'Поканата изтече', tone: 'warn' };
  return { key: 'profile_only', label: 'Само профил, без вход', tone: 'muted' };
}

/** The folded "Покана за вход" in the profile, on one line: the state, then what to do ("Не влиза в системата · Покани да влиза"). */
export function invitationLine(member: Pick<TeamMember, 'invitation'>, now: Date): { state: string; action: string } {
  if (!member.invitation) return { state: 'Не влиза в системата', action: 'Покани да влиза' };
  if (invitationOpen(member.invitation, now)) return { state: `Поканен · ${expiresText(member.invitation.expires_at, now)}`, action: 'Изпрати поканата пак' };
  return { state: 'Поканата изтече', action: 'Изпрати поканата пак' };
}

/** What waits for the person in "Чака": only for those who manage the team, and only for a profile without a login. */
export function waitingAction(member: Pick<TeamMember, 'id' | 'state' | 'invitation'>, canManage: boolean): { label: string; href: string } | null {
  if (!canManage || member.state !== 'profile_only') return null;
  return {
    label: member.invitation ? 'Изпрати поканата пак' : 'Покани да влиза',
    href: `/dashboard/team/${encodeURIComponent(member.id)}?invite=1`,
  };
}

export const assignmentRoles: Record<'lead' | 'assistant', string> = { lead: 'водещ', assistant: 'помощник' };

export function assignmentRole(role: string): string {
  return role === 'assistant' ? assignmentRoles.assistant : assignmentRoles.lead;
}

/** Whether an assignment is running today, starts later or has ended (days are "YYYY-MM-DD" in Sofia). */
export function assignmentPhase(assignment: Pick<GroupAssignment, 'starts_on' | 'ends_on'>, today: string): 'current' | 'coming' | 'ended' {
  if (assignment.ends_on !== null && assignment.ends_on < today) return 'ended';
  if (assignment.starts_on !== null && assignment.starts_on > today) return 'coming';
  return 'current';
}

/** "от 1 декември", "до 30 ноември", "от 1 септември до 30 ноември"; nothing without dates. */
export function periodText(assignment: Pick<GroupAssignment, 'starts_on' | 'ends_on'>): string {
  const from = assignment.starts_on ? `от ${shortDay(assignment.starts_on)}` : '';
  const to = assignment.ends_on ? `до ${shortDay(assignment.ends_on)}` : '';
  return [from, to].filter(Boolean).join(' ');
}

/** "Шахмат · Група 1 — водещ · от 1 декември". */
export function assignmentText(assignment: GroupAssignment): string {
  const period = periodText(assignment);
  return `${assignment.label} — ${assignmentRole(assignment.role)}${period ? ` · ${period}` : ''}`;
}

const historyWords: Record<string, { done: string; happened: string }> = {
  'team.member_added': { done: 'добави човека в екипа', happened: 'Човекът е добавен в екипа' },
  'team.member_updated': { done: 'промени данните', happened: 'Данните са променени' },
  'team.member_archived': { done: 'архивира записа', happened: 'Записът е архивиран' },
  'team.role_changed': { done: 'смени ролята', happened: 'Ролята е сменена' },
  'team.invitation_sent': { done: 'изпрати покана за вход', happened: 'Изпратена е покана за вход' },
  'team.invitation_accepted': { done: 'прие поканата и вече влиза в системата', happened: 'Поканата е приета' },
  'team.profile_linked': { done: 'свърза човека с профил на преподавател', happened: 'Свързан е профил на преподавател' },
  'catalog.assignment_added': { done: 'добави назначение в група', happened: 'Добавено е назначение в група' },
  'catalog.assignment_ended': { done: 'приключи назначение в група', happened: 'Приключено е назначение в група' },
  'catalog.assignment_removed': { done: 'махна назначение в група', happened: 'Махнато е назначение в група' },
};

/** A history entry as a sentence: "Мария Колева смени ролята." or "Ролята е сменена." */
export function historySentence(entry: Pick<HistoryEntry, 'action' | 'by'>): string {
  const words = historyWords[entry.action] ?? { done: 'направи промяна', happened: 'Направена е промяна' };
  return entry.by ? `${entry.by} ${words.done}.` : `${words.happened}.`;
}

export type ErrorBody = { message?: unknown; errors?: Record<string, string[]> };

const cyrillic = /[А-Яа-я]/;

/**
 * Bulgarian text for a failed request. The server's own Bulgarian message is kept as it is (the reason an archive
 * or an invitation is refused); a technical English one is replaced by words for the case.
 */
export function teamErrorMessage(status: number, body: ErrorBody): string {
  const first = body.errors ? Object.values(body.errors)[0]?.[0] : undefined;
  if (typeof first === 'string' && cyrillic.test(first)) return first;
  if (typeof body.message === 'string' && cyrillic.test(body.message)) return body.message;
  if (status === 401) return 'Влезте отново, за да продължите.';
  if (status === 403) return 'Нямате право за това действие. Обърнете се към собственика на организацията.';
  if (status === 404) return 'Не намерихме този запис. Може да е архивиран или да е в друга организация.';
  if (status === 409) return 'Някой току-що промени тези данни. Заредете ги отново и опитайте пак.';
  if (status === 422) return 'Проверете отбелязаните полета.';
  if (status === 429) return 'Твърде много опити. Изчакайте минута и опитайте отново.';
  return 'Заявката не беше изпълнена. Проверете връзката и опитайте отново.';
}

/** The filters of the list; "Архивирани" loads another list from the server. */
export type StateFilter = '' | 'login' | 'invited' | 'profile_only' | 'archived';
export type TeamFilters = { search: string; role: '' | TeamRole; state: StateFilter };

export const stateFilterLabels: Record<Exclude<StateFilter, ''>, string> = {
  login: 'Влизат в системата',
  invited: 'Поканени',
  profile_only: 'Само профил, без вход',
  archived: 'Архивирани',
};

export function parseStateFilter(value: string | null): StateFilter {
  return value === 'login' || value === 'invited' || value === 'profile_only' || value === 'archived' ? value : '';
}

export function matchesFilters(member: TeamMember, filters: TeamFilters, now: Date): boolean {
  const search = filters.search.trim().toLocaleLowerCase('bg-BG');
  if (search && !member.full_name.toLocaleLowerCase('bg-BG').includes(search)) return false;
  if (filters.role && memberRole(member) !== filters.role) return false;
  const status = memberStatus(member, now).key;
  if (filters.state === 'invited') return status === 'invited' || status === 'invitation_expired';
  if (filters.state === 'login' || filters.state === 'profile_only' || filters.state === 'archived') return status === filters.state;
  return true;
}

/** The inviter's link only joins an existing profile, so it opens at the login; the e-mail's link offers a new profile. */
export function guestModeFor(link: string): 'register' | 'login' {
  return link === 'email' ? 'register' : 'login';
}

/** The first field with an error, in the order the form shows them; it takes the focus. */
export function firstErrorField(errors: Record<string, string | undefined>, order: readonly string[]): string | undefined {
  return order.find((field) => Boolean(errors[field]));
}

/** The token of an invitation link: 64 letters and digits after "#", nothing else. */
export function tokenFromHash(hash: string): string | null {
  const value = hash.startsWith('#') ? hash.slice(1) : hash;
  return /^[A-Za-z0-9]{64}$/.test(value) ? value : null;
}

/** After a part of the page is replaced (a form opens or closes), the focus goes to the given field or button. */
export function focusLater(id: string): void {
  requestAnimationFrame(() => document.getElementById(id)?.focus());
}

/** "Иван Петров" → "ИП". */
export function initials(name: string): string {
  return name.split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0]).join('').toLocaleUpperCase('bg-BG') || '?';
}
