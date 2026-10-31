import type { OrganizationAccess, TeachingGroup } from '../dashboard/api';
import { shortDay } from '../enrollment/presentation';
import type { AccessScope } from '../team/api';
import { areaRights, defaultScope, rightsOfRole, scopeLabels, TEACHING } from '../team/rights';

/** "+359888000111" → "0888 000 111"; any other number as it is. */
export function phoneText(phone: string | null | undefined): string {
  if (!phone) return '';
  const local = /^\+359(\d{9})$/.exec(phone);
  if (!local) return phone;
  const digits = `0${local[1]}`;
  return `${digits.slice(0, 4)} ${digits.slice(4, 7)} ${digits.slice(7)}`;
}

/** The scope the person has: from the rights with the added ones, otherwise the role's. */
export function ownScope(role: string, access: OrganizationAccess | undefined): AccessScope {
  const scope = access?.scope;
  return scope === 'own' || scope === 'groups' || scope === 'all' ? scope : defaultScope(role);
}

const cardRights = Object.values(areaRights).flat();

/**
 * The person's own added rights with their last day. Without `grants` from the API, the rights beyond the role's —
 * then without an end.
 */
export function ownGrants(role: string, access: OrganizationAccess | undefined): Record<string, string | null> {
  if (!access) return {};
  if (access.grants) return Object.fromEntries(access.grants.map((grant) => [grant.ability, grant.expires_on]));
  const fromRole = rightsOfRole(role);
  return Object.fromEntries(access.abilities
    .filter((key) => cardRights.includes(key) && !fromRole.includes(key) && !TEACHING.includes(key))
    .map((key) => [key, null]));
}

/** "Роля: Преподавател · своите групи". */
export function scopeText(scope: AccessScope): string {
  return scopeLabels[scope].toLocaleLowerCase('bg-BG');
}

const sessionFormat = new Intl.DateTimeFormat('bg-BG', { weekday: 'long', day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit', timeZone: 'Europe/Sofia' });

export type MyGroup = { id: string; name: string; part: string; tone: 'lead' | 'assistant' | 'substitute'; detail: string; href: string };
export type MyActivity = { activity: string; groups: MyGroup[] };

/**
 * "Моите групи" by activity: the person's part in each group, and the next session — or, for a substitute, the days.
 * The group opens its list of children.
 */
export function myGroups(groups: TeachingGroup[]): MyActivity[] {
  const byActivity = new Map<string, MyGroup[]>();
  for (const group of groups) {
    const substitute = group.basis === 'substitute';
    const tone = substitute ? 'substitute' : group.role === 'assistant' ? 'assistant' : 'lead';
    const days = group.starts_on && group.ends_on && group.starts_on !== group.ends_on
      ? `${shortDay(group.starts_on)} – ${shortDay(group.ends_on)}` : group.starts_on ? `на ${shortDay(group.starts_on)}` : '';
    const detail = substitute
      ? `Замествате${days ? ` ${days}` : ''}`
      : group.next_session_starts_at ? `Следващо: ${sessionFormat.format(new Date(group.next_session_starts_at))}` : 'Няма предстоящи занимания';
    const item: MyGroup = {
      id: group.group_id,
      name: group.group_name,
      part: substitute ? 'Заместник' : group.role === 'assistant' ? 'Помощник' : 'Водещ',
      tone,
      detail,
      href: `/dashboard/enrollments?view=groups&group=${encodeURIComponent(group.group_id)}`,
    };
    byActivity.set(group.activity_title, [...(byActivity.get(group.activity_title) ?? []), item]);
  }
  return [...byActivity].map(([activity, items]) => ({ activity, groups: items })).sort((a, b) => a.activity.localeCompare(b.activity, 'bg'));
}

export const groupCountText = (count: number) => `${count} ${count === 1 ? 'група' : 'групи'}`;

/** Leading a group lets the person open its list; so do the rights to see the enrollments. */
export function canOpenGroups(permissions: string[]): boolean {
  return permissions.includes('enrollment.view') || permissions.includes('enrollment.view_own');
}

export type ContactErrors = { name?: string; phone?: string };

/** The form's own checks, before anything is sent. */
export function contactErrors(values: { name: string; phone: string }): ContactErrors {
  const errors: ContactErrors = {};
  if (values.name.trim() === '') errors.name = 'Напишете името си.';
  if (values.phone.trim() === '') errors.phone = 'Въведете телефон — на него ви търси организацията.';
  return errors;
}
