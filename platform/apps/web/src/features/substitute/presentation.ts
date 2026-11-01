import type { DashboardOrganization, TeachingGroup } from '../dashboard/api';
import { sofiaDay } from '../schedule/presentation';
import type { OptionSession, RequestSession } from './api';

export function isOffice(org: DashboardOrganization): boolean {
  const role = org.access?.role ?? org.role;
  const team = ['organization_owner', 'organization_admin', 'coordinator', 'instructor', 'finance', 'viewer'].includes(role);
  const scope = org.access?.scope ?? (role === 'instructor' ? 'own' : 'all');
  const abilities = org.access?.abilities ?? org.permissions;
  return team && scope === 'all' && abilities.includes('schedule.manage') && abilities.includes('team.assign');
}
export const canRequest = (org: DashboardOrganization, groups: TeachingGroup[]) => groups.length > 0 && !isOffice(org) && ['organization_owner', 'organization_admin', 'coordinator', 'instructor', 'finance', 'viewer'].includes(org.access?.role ?? org.role);
export function substituteGroupHref(group: TeachingGroup): string {
  return `/dashboard/schedule?view=week${group.next_session_starts_at ? `&date=${sofiaDay(group.next_session_starts_at)}` : group.starts_on ? `&date=${group.starts_on}` : ''}`;
}
export const dateLabel = (iso: string) => new Intl.DateTimeFormat('bg-BG', { weekday: 'long', day: 'numeric', month: 'long', timeZone: 'Europe/Sofia' }).format(new Date(iso));
export const clock = (iso: string) => new Intl.DateTimeFormat('bg-BG', { hour: '2-digit', minute: '2-digit', timeZone: 'Europe/Sofia' }).format(new Date(iso));
export const when = (s: { starts_at: string; ends_at: string }) => `${dateLabel(s.starts_at)} · ${clock(s.starts_at)}–${clock(s.ends_at)}`;
export function selection(sessions: OptionSession[], previous?: string[], only?: string): string[] {
  return sessions.filter(s => !s.requested && (previous ? previous.includes(s.id) : !only || s.id === only)).map(s => s.id);
}
export function requestStatus(s: RequestSession): { label: string; text: string; tone: 'limited' | 'available' | 'quiet'; decision?: string } {
  if (s.state === 'open') return { label: 'Чака решение', text: 'Докато няма заместник, заниманието е ваше.', tone: 'limited' };
  if (s.state === 'assigned') {
    if (s.now.teaching) return { label: '✓ Решено', text: 'Заниманието отново е ваше.', tone: 'quiet' };
    return { label: s.now.substitute ? '✓ Има заместник' : '✓ Решено', text: s.now.substitute ? `${s.now.substitute.name} ще ви замести` : 'Не е нужно да идвате', tone: 'available', decision: s.decided_by ? `${s.now.substitute ? 'Назначи' : 'Реши'} ${s.decided_by.name}` : undefined };
  }
  if (s.state === 'declined') return { label: 'Не е одобрена', text: 'Заниманието остава ваше.', tone: 'quiet', decision: s.decided_by ? `Отказа ${s.decided_by.name}` : undefined };
  const expired: Record<string, string> = { cancelled: 'Заниманието е отменено.', moved: 'Заниманието е преместено — ако и тогава не можете, поискайте заместник отново.', started: 'Заниманието мина без заместник.', not_teaching: 'Вече не водите това занимание.', archived: 'Групата е в архива.' };
  return { label: 'Приключена', text: expired[s.closed_reason ?? ''] ?? 'Заявката е приключена.', tone: 'quiet' };
}
