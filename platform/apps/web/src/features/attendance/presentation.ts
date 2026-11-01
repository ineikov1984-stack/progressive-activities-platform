import type { Applied, AttendanceChild, History, Mark, Sheet } from './api';
export const format = (value: string, options: Intl.DateTimeFormatOptions) => new Intl.DateTimeFormat('bg-BG', { timeZone: 'Europe/Sofia', ...options }).format(new Date(value));
export const clock = (value: string) => format(value, { hour: '2-digit', minute: '2-digit' });
export const date = (value: string) => format(value, { day: 'numeric', month: 'long' });
export const when = (start: string, end: string) => `${format(start, { weekday: 'long', day: 'numeric', month: 'long' })} · ${clock(start)}–${clock(end)}`;
export const deadlineText = (deadline: string) => `Можете да поправяте до ${format(new Date(Date.parse(deadline) - 1).toISOString(), { weekday: 'long', day: 'numeric', month: 'long' })}, 23:59. След това — само собственикът или администраторът, с причина.`;
export const markLabel = (status: Mark | undefined) => status === 'present' ? 'Присъства' : status === 'absent' ? 'Отсъства' : 'Неотбелязано';
export const cellLabels: Record<string, string> = { present: 'Присъства', absent: 'Отсъства', warned: 'Предупредено отсъствие', paused: 'В пауза', paused_present: 'Присъства · дошло по време на пауза', unmarked: 'Неотбелязано', future: 'Предстоящо', cancelled: 'Отменено', not_held: 'Не се проведе' };
export const cellSigns: Record<string, string> = { present: '✓', absent: '✕', warned: 'пр.', paused: 'пауза', paused_present: '✓ п.', unmarked: '?', future: '·', cancelled: '—', not_held: 'н.пр.' };
export function childLabel(child: AttendanceChild): string { return child.came_while_paused ? cellLabels.paused_present : !child.expected ? 'В пауза' : child.warned ? cellLabels.warned : markLabel(child.status); }
export const initialView = (sheet: Sheet) => sheet.abilities.mark && !sheet.abilities.reason_required && sheet.counts.unmarked > 0 ? 'mark' : 'view';
export function historyLines(history: History[], children: AttendanceChild[]): { id: string; at: string; text: string }[] {
  const names = new Map(children.map((child) => [child.enrollment_id, child.name]));
  const sorted = [...history].sort((a, b) => Date.parse(a.at) - Date.parse(b.at) || a.id.localeCompare(b.id));
  const removed = new Set<string>();
  for (let i = 0; i < sorted.length; i++) { const a = sorted[i]; if (!a.enrollment_id || removed.has(a.id)) continue;
    const b = sorted.slice(i + 1).find((row) => row.enrollment_id === a.enrollment_id);
    if (b && a.by === b.by && b.to === a.from && b.from === a.to && Date.parse(b.at) - Date.parse(a.at) <= 60000) { removed.add(a.id); removed.add(b.id); }
  }
  const lines: { id: string; at: string; text: string; by?: string | null; first?: boolean; present?: number; absent?: number }[] = [];
  for (const row of sorted.filter((r) => !removed.has(r.id))) {
    const by = row.by ?? 'Член на екипа'; const last = lines.at(-1);
    if (row.enrollment_id && row.from === null && row.to !== null && last?.first && last.by === row.by && !row.reason) {
      last.at = row.at; last.present! += row.to === 'present' ? 1 : 0; last.absent! += row.to === 'absent' ? 1 : 0;
      last.text = `${by} отбеляза присъствията · ${last.present} присъстват, ${last.absent} отсъстват`; continue;
    }
    const first = !!row.enrollment_id && row.from === null && row.to !== null && !row.reason;
    lines.push({ id: row.id, at: row.at, by: row.by, first, present: row.to === 'present' ? 1 : 0, absent: row.to === 'absent' ? 1 : 0,
      text: row.kind === 'not_held' || row.kind === 'held' ? `${by} отбеляза „${row.kind === 'not_held' ? 'Не се проведе' : 'Проведе се'}“${row.reason ? ` · Причина: ${row.reason}` : ''}`
        : first ? `${by} отбеляза присъствията · ${row.to === 'present' ? '1 присъства' : '1 отсъства'}`
          : `${by} поправи: ${names.get(row.enrollment_id ?? '') ?? 'Дете'} — ${markLabel(row.from)} → ${markLabel(row.to)}${row.reason ? ` · Причина: ${row.reason}` : ''}` });
  }
  return lines.reverse().map(({ id, at, text }) => ({ id, at, text }));
}

/** Clearing a mark after the deadline is forbidden by the API. */
export const undoAllowed = (applied: Applied[], late: boolean): boolean => !late || applied.every((change) => change.from !== null);
