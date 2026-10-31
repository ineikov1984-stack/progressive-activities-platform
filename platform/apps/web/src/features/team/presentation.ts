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

/**
 * What the person does: the role of the login; for someone invited, the role of the invitation; for a teacher
 * without access, "Преподавател".
 */
export function memberRole(member: Pick<TeamMember, 'role' | 'instructor_profile_id'> & Partial<Pick<TeamMember, 'state' | 'invitation'>>): TeamRole | null {
  if (isTeamRole(member.role)) return member.role;
  const invited = member.state === 'profile_only' ? member.invitation?.role : undefined;
  if (isTeamRole(invited)) return invited;
  return member.instructor_profile_id ? 'instructor' : null;
}

/** The short line under each choice of "Какво ще прави". */
export const roleHints: Record<TeamRole, string> = {
  organization_owner: 'Всичко, включително правата на екипа',
  organization_admin: 'Всичко освен финансите',
  coordinator: 'Записвания и групи',
  instructor: 'Само своите групи',
  finance: 'Записвания и финанси',
  viewer: 'Само гледа, без да променя',
};

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
/** The access in words ("Има достъп"); `detail` is the end of a waiting invitation; the tone only colours it. */
export type MemberStatus = { key: StatusKey; label: string; detail?: string; tone: 'ok' | 'info' | 'warn' | 'muted' };

function invitationOpen(invitation: MemberInvitation | null | undefined, now: Date): boolean {
  return invitation?.status === 'pending' && Date.parse(invitation.expires_at) > now.getTime();
}

export function memberStatus(member: Pick<TeamMember, 'state' | 'invitation'>, now: Date): MemberStatus {
  if (member.state === 'archived') return { key: 'archived', label: 'В архива', tone: 'muted' };
  if (member.state === 'login') return { key: 'login', label: 'Има достъп', tone: 'ok' };
  if (member.invitation && invitationOpen(member.invitation, now)) {
    return { key: 'invited', label: 'Изпратена покана', detail: expiresText(member.invitation.expires_at, now), tone: 'info' };
  }
  if (member.invitation) return { key: 'invitation_expired', label: 'Поканата изтече', tone: 'warn' };
  return { key: 'profile_only', label: 'Няма достъп', tone: 'muted' };
}

/** "Изпрати покана" folded in the profile: a sentence and the button that opens the form. */
export function invitationFold(member: Pick<TeamMember, 'invitation' | 'instructor_profile_id'>, now: Date): { sentence: string; action: string } {
  if (!member.invitation) {
    return {
      sentence: member.instructor_profile_id ? 'Няма достъп до системата. Присъствията в групите, които води, отбелязвате вие.' : 'Няма достъп до системата.',
      action: 'Изпрати покана',
    };
  }
  if (invitationOpen(member.invitation, now)) return { sentence: `Изпратена покана · ${expiresText(member.invitation.expires_at, now)}.`, action: 'Изпрати поканата отново' };
  return { sentence: 'Поканата изтече.', action: 'Изпрати нова покана' };
}

/**
 * The action next to the access in the list, only for those who manage the team and only without access: a button
 * "Изпрати покана", or a link to send a waiting or expired invitation again.
 */
export function accessAction(member: Pick<TeamMember, 'id' | 'state' | 'invitation'>, canManage: boolean, now: Date): { label: string; href: string; kind: 'button' | 'link' } | null {
  if (!canManage || member.state !== 'profile_only') return null;
  const href = `/dashboard/team/${encodeURIComponent(member.id)}?invite=1`;
  if (!member.invitation) return { label: 'Изпрати покана', href, kind: 'button' };
  return { label: invitationOpen(member.invitation, now) ? 'Изпрати отново' : 'Изпрати нова покана', href, kind: 'link' };
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

/** Only what differs from the usual is marked in the list: "помощник" and a start still ahead ("от 1 декември"). */
export function groupTags(assignment: Pick<GroupAssignment, 'role' | 'starts_on'>, today: string): string[] {
  return [
    ...(assignment.role === 'assistant' ? ['помощник'] : []),
    ...(assignment.starts_on !== null && assignment.starts_on > today ? [`от ${shortDay(assignment.starts_on)}`] : []),
  ];
}

export type ActivityGroups = { activity: string; groups: GroupAssignment[] };

/** The groups by activity, the activities in alphabetical order, the groups as they came. */
export function groupsByActivity(groups: GroupAssignment[]): ActivityGroups[] {
  const byActivity = new Map<string, GroupAssignment[]>();
  for (const group of groups) byActivity.set(group.activity, [...(byActivity.get(group.activity) ?? []), group]);
  return [...byActivity].map(([activity, items]) => ({ activity, groups: items })).sort((a, b) => a.activity.localeCompare(b.activity, 'bg'));
}

/**
 * A group in "Екип" opens the group's schedule (DECISIONS 31.10.2026 „Права — роля плюс допълнителни права“: first the
 * children in "Записвания", then the schedule; docs/product/schedule.md, т. 1). Everyone sees the schedule of their
 * scope, so the link is there for all who see the team. The visible text is the group's name; the link's name says
 * what opens, and holds that visible text.
 */
export function groupLink(assignment: Pick<GroupAssignment, 'group_id' | 'activity' | 'group'>): { href: string; label: string } {
  return {
    href: `/dashboard/schedule?group=${encodeURIComponent(assignment.group_id)}`,
    label: `Графикът на ${assignment.activity} · ${assignment.group}`,
  };
}

export const groupCount = (count: number) => `${count} ${count === 1 ? 'група' : 'групи'}`;
export const activityCount = (count: number) => `${count} ${count === 1 ? 'дейност' : 'дейности'}`;

/** Up to this many groups the list shows them; from one more, a button with the count opens them (variant Б). */
export const GROUPS_SHOWN = 2;

/** The button of variant Б: "5 групи" and the activities as chips ("Шахмат · 2"). */
export function groupSummary(groups: GroupAssignment[]): { count: string; chips: string[] } {
  return {
    count: groupCount(groups.length),
    chips: groupsByActivity(groups).map((item) => (item.groups.length > 1 ? `${item.activity} · ${item.groups.length}` : item.activity)),
  };
}

/** "5 групи в 3 дейности"; with one activity, only "2 групи". */
export function groupsMeta(groups: GroupAssignment[]): string {
  const activities = groupsByActivity(groups).length;
  return activities > 1 ? `${groupCount(groups.length)} в ${activityCount(activities)}` : groupCount(groups.length);
}

/** The empty "Групи": a teacher without groups yet, or someone who does not lead groups. */
export function noGroupsText(member: Pick<TeamMember, 'instructor_profile_id'>): string {
  return member.instructor_profile_id ? 'Още не води група' : 'Не води групи';
}

const historyWhat: Record<string, string> = {
  'team.member_added': 'Добавяне в екипа',
  'team.member_updated': 'Промяна на данните',
  'team.member_archived': 'Преместване в архива',
  'team.role_changed': 'Смяна на ролята',
  'team.invitation_sent': 'Изпращане на покана',
  'team.invitation_accepted': 'Приемане на поканата',
  'team.profile_linked': 'Разрешение да води групи',
  'catalog.assignment_added': 'Добавяне в група',
  'catalog.assignment_ended': 'Край на работата в група',
  'catalog.assignment_removed': 'Премахване от група',
};

/** A history entry as "what — who", with nouns, so no grammatical gender: "Смяна на ролята — Мария Колева". */
export function historyItem(entry: Pick<HistoryEntry, 'action' | 'by'>): { what: string; who: string | null } {
  return { what: historyWhat[entry.action] ?? 'Промяна', who: entry.by };
}

export function historyText(entry: Pick<HistoryEntry, 'action' | 'by'>): string {
  const { what, who } = historyItem(entry);
  return who ? `${what} — ${who}` : what;
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
  if (status === 403) return 'Вашата роля не позволява това. Обърнете се към собственика на организацията.';
  if (status === 404) return 'Не намерихме този запис. Може да е в архива или в друга организация.';
  if (status === 409) return 'Някой току-що промени тези данни. Заредете новите данни и опитайте отново.';
  if (status === 422) return 'Проверете отбелязаните полета.';
  if (status === 429) return 'Твърде много опити. Изчакайте една минута и опитайте отново.';
  return 'Действието не беше изпълнено. Проверете интернет връзката и опитайте отново.';
}

/** The filters of the list ("Достъп"); "В архива" loads another list from the server. */
export type StateFilter = '' | 'login' | 'invited' | 'profile_only' | 'archived';
/** `roles`: none — all roles; several — anyone with one of them. */
export type TeamFilters = { search: string; roles: TeamRole[]; state: StateFilter };

export const stateFilterLabels: Record<Exclude<StateFilter, ''>, string> = {
  login: 'Има достъп',
  invited: 'Изпратена покана',
  profile_only: 'Няма достъп',
  archived: 'В архива',
};

export function parseStateFilter(value: string | null): StateFilter {
  return value === 'login' || value === 'invited' || value === 'profile_only' || value === 'archived' ? value : '';
}

export function matchesFilters(member: TeamMember, filters: TeamFilters, now: Date): boolean {
  const search = filters.search.trim().toLocaleLowerCase('bg-BG');
  if (search && !member.full_name.toLocaleLowerCase('bg-BG').includes(search)) return false;
  if (filters.roles.length > 0) {
    const role = memberRole(member);
    if (role === null || !filters.roles.includes(role)) return false;
  }
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

/** The first of these that is on screen takes the focus (a button may be hidden on this width). */
export function focusVisible(ids: string[]): void {
  requestAnimationFrame(() => ids.map((id) => document.getElementById(id)).find((element) => element !== null && element.offsetParent !== null)?.focus());
}

/** "Иван Петров" → "ИП". */
export function initials(name: string): string {
  return name.split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0]).join('').toLocaleUpperCase('bg-BG') || '?';
}

/** The button of the role filter: "Всички роли", the one role, or "2 роли". */
export function rolesFilterText(roles: TeamRole[]): string {
  if (roles.length === 0) return 'Всички роли';
  if (roles.length === 1) return roleLabels[roles[0]];
  return `${roles.length} роли`;
}

/** "Намерени: 3" above the list; it follows the search and the filters. */
export const foundText = (count: number) => `Намерени: ${count}`;

/* When a person last signed in and was active (DECISIONS 31.10.2026 „Екип — последен вход и филтър по роли“). */

const counted = (count: number, one: string, many: string) => `${count} ${count === 1 ? one : many}`;

/** "вчера", "преди 2 дни", "преди 3 седмици", "преди 2 месеца", "преди 1 година" — from whole calendar days in Sofia. */
function daysAgoText(days: number): string {
  if (days <= 1) return 'вчера';
  if (days < 7) return `преди ${counted(days, 'ден', 'дни')}`;
  if (days < 30) return `преди ${counted(Math.floor(days / 7), 'седмица', 'седмици')}`;
  if (days < 365) return `преди ${counted(Math.floor(days / 30), 'месец', 'месеца')}`;
  return `преди ${counted(Math.floor(days / 365), 'година', 'години')}`;
}

/** "Последна активност": "току-що", "преди 5 минути", "преди 3 часа", then by days. */
export function agoText(moment: string, now: Date): string {
  const minutes = Math.floor((now.getTime() - Date.parse(moment)) / 60_000);
  if (minutes < 1) return 'току-що';
  if (minutes < 60) return `преди ${counted(minutes, 'минута', 'минути')}`;
  if (minutes < 24 * 60) return `преди ${counted(Math.floor(minutes / 60), 'час', 'часа')}`;
  return daysAgoText(-daysUntil(moment, now));
}

/**
 * Under "Има достъп" in the list: "последен вход днес", "последен вход преди 2 дни". With nouns, so there is no
 * grammatical gender. Someone with access has signed in before; "още няма записан вход" until the first sign-in that
 * is recorded.
 */
export function lastLoginText(lastLogin: string | null | undefined, now: Date): string {
  if (!lastLogin) return 'още няма записан вход';
  const days = -daysUntil(lastLogin, now);
  return `последен вход ${days <= 0 ? 'днес' : daysAgoText(days)}`;
}

const momentFormat = new Intl.DateTimeFormat('bg-BG', { day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit', timeZone: 'Europe/Sofia' });

/**
 * In the profile: "Последен вход: 31 октомври 2026 г. в 14:09 · Последна активност: преди 5 минути". The activity is
 * shown also before a sign-in is recorded.
 */
export function activityLine(member: Pick<TeamMember, 'last_login_at' | 'last_seen_at'>, now: Date): string | null {
  if (member.last_login_at === undefined) return null;
  const login = member.last_login_at ? `Последен вход: ${momentFormat.format(new Date(member.last_login_at))}` : 'Още няма записан вход';
  return member.last_seen_at ? `${login} · Последна активност: ${agoText(member.last_seen_at, now)}` : `${login}.`;
}
