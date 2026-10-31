import type { ScheduleSession } from './api';

export type ChangeAction = 'move' | 'cancel' | 'restore' | 'change_location' | 'holiday' | 'add';
export type WarningKey = { type: string; session_id: string; other_session_id: string | null };
export type ChangeWarning = WarningKey & { needs_confirmation: boolean; message: string };
export type ChangeBody = { action: ChangeAction; sessions?: { id: string; version: number }[]; reason?: string; reason_to_parents?: boolean;
  notify_parents?: boolean; date?: string; from?: string; to?: string; location_id?: string | null; group_id?: string;
  days?: string[]; locations?: string[]; activities?: string[]; confirmed_warnings?: WarningKey[] };
export type ChangePreview = { sessions: { id: string; version: number; starts_at: string; ends_at: string; group_id: string; activity_id: string | null; location_id: string | null; children: number }[];
  totals: { sessions: number; groups: number; children: number; families: number; letters: number; teachers: number; without_verified_email: number;
    visits: number; visit_applications: number; skips_dropped: number; not_attending_after: number; starts_changed: number };
  skipped: { started: number; cancelled: number; same_location: number }; warnings: ChangeWarning[] };
export type ChangeResult = { change: { id: string; action: ChangeAction; sessions: { id: string; version: number }[] }; letters: { families: number; teachers: number; without_verified_email: number } };
export type Selection = Record<string, ScheduleSession>;
export function selectable(session: ScheduleSession, now: Date): boolean {
  return session.status === 'scheduled' && new Date(session.starts_at).getTime() > now.getTime() && Number.isInteger(session.version);
}
export function selectSessions(selected: Selection, sessions: ScheduleSession[], now: Date, remove = false): { selected: Selection; limit: boolean } {
  const next = { ...selected };
  for (const session of sessions) {
    if (remove) delete next[session.id];
    else if (selectable(session, now)) next[session.id] = session;
  }
  return Object.keys(next).length > 300 ? { selected, limit: true } : { selected: next, limit: false };
}
export function warningKeys(warnings: ChangeWarning[]): WarningKey[] {
  return warnings.filter((w) => w.needs_confirmation).map(({ type, session_id, other_session_id }) => ({ type, session_id, other_session_id }));
}
export function canApply(preview: ChangePreview | undefined, confirmed: boolean): boolean {
  return !!preview && preview.totals.sessions > 0 && (confirmed || warningKeys(preview.warnings).length === 0);
}
export const changeTitles: Record<ChangeAction, string> = { move: 'Премести заниманието', cancel: 'Отмени заниманието', restore: 'Върни заниманието',
  change_location: 'Смени залата', holiday: 'Почивен ден', add: 'Допълнително занимание' };
export function changeSuccess(result: ChangeResult): string {
  const text: Record<ChangeAction, string> = { move: 'Заниманието е преместено.', cancel: 'Заниманията са отменени.', restore: 'Заниманието е върнато.',
    change_location: 'Мястото е сменено.', holiday: 'Почивният ден е приложен.', add: 'Допълнителното занимание е добавено.' };
  return `${text[result.change.action]} Писмата до ${result.letters.families} семейства тръгват до минута.`;
}
