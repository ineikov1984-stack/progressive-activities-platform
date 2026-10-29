import { formatEur } from '../catalog/admin/presentation';
import type { GroupRoster, RosterEnrollment, StaffApplication } from './api';

export const scopeLabels: Record<string, string> = {
  full_period: 'Записване за периода',
  single: 'Еднократно посещение',
  trial: 'Пробно занимание',
};

const unitLabels: Record<string, string> = { month: 'на месец', session: 'за занимание', period: 'за периода', package: 'за пакета' };

export function priceText(price: StaffApplication['price']): string {
  if (price.amount_cents === 0) return 'Безплатно';
  return `${formatEur(price.amount_cents)} ${unitLabels[price.unit] ?? ''}`.trim();
}

const dateFormat = new Intl.DateTimeFormat('bg-BG', { day: 'numeric', month: 'long', timeZone: 'Europe/Sofia' });
const dateTimeFormat = new Intl.DateTimeFormat('bg-BG', { day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit', timeZone: 'Europe/Sofia' });

export function dateText(value: string | null | undefined): string {
  return value ? dateFormat.format(new Date(value)) : '';
}

export function dateTimeText(value: string | null | undefined): string {
  return value ? dateTimeFormat.format(new Date(value)) : '';
}

/** "1 свободно място", "Няма свободни места", "Без ограничение на местата". */
export function placesText(remaining: number | null): string {
  if (remaining === null) return 'Без ограничение на местата';
  if (remaining <= 0) return 'Няма свободни места';
  return remaining === 1 ? '1 свободно място' : `${remaining} свободни места`;
}

const sessionFormat = new Intl.DateTimeFormat('bg-BG', { weekday: 'long', day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit', timeZone: 'Europe/Sofia' });

/** "вторник, 10 ноември, 16:00" for a session start. */
export function sessionText(startsAt: string): string {
  return sessionFormat.format(new Date(startsAt));
}

/** "12 ноември" for a calendar day "2026-11-12" (full month: clearer than "12.11"). */
export function shortDay(day: string | null | undefined): string {
  return day ? dateFormat.format(new Date(`${day}T12:00:00Z`)) : '';
}

/** Calendar day in Sofia for a moment (default: now), as "YYYY-MM-DD". */
export function sofiaDay(moment: Date | string = new Date()): string {
  const parts = new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Sofia', year: 'numeric', month: '2-digit', day: '2-digit' })
    .formatToParts(typeof moment === 'string' ? new Date(moment) : moment);
  const part = (type: string) => parts.find((item) => item.type === type)?.value ?? '';
  return `${part('year')}-${part('month')}-${part('day')}`;
}

export function addDays(day: string, days: number): string {
  const date = new Date(`${day}T12:00:00Z`);
  date.setUTCDate(date.getUTCDate() + days);
  return date.toISOString().slice(0, 10);
}

export function lastDayOfMonth(day: string): string {
  const date = new Date(`${day}T12:00:00Z`);
  return new Date(Date.UTC(date.getUTCFullYear(), date.getUTCMonth() + 1, 0, 12)).toISOString().slice(0, 10);
}

export const pauseReasonLabels: Record<string, string> = {
  health: 'Здравословни причини',
  holiday: 'Ваканция или пътуване',
  family: 'Семейни причини',
  other: 'Друго',
};

export type RosterNote =
  | { kind: 'starts'; text: string }
  | { kind: 'moves'; text: string }
  | { kind: 'ends'; text: string }
  | { kind: 'paused'; text: string; pauseId: string; startsOn: string; endsOn: string }
  | { kind: 'pause'; text: string; pauseId: string; startsOn: string; endsOn: string }
  | { kind: 'skips'; text: string; sessionId: string };

/** What the team should notice on a roster row, always in words (never only a colour). */
export function rosterNotes(enrollment: RosterEnrollment, today: string): RosterNote[] {
  const notes: RosterNote[] = [];
  if (enrollment.starts_on > today) notes.push({ kind: 'starts', text: `Започва на ${shortDay(enrollment.starts_on)}` });
  for (const pause of enrollment.pauses ?? []) {
    const current = pause.starts_on <= today && pause.ends_on >= today;
    notes.push(current
      ? { kind: 'paused', text: `В пауза до ${shortDay(pause.ends_on)}`, pauseId: pause.id, startsOn: pause.starts_on, endsOn: pause.ends_on }
      : { kind: 'pause', text: `Пауза ${shortDay(pause.starts_on)} – ${shortDay(pause.ends_on)}`, pauseId: pause.id, startsOn: pause.starts_on, endsOn: pause.ends_on });
  }
  for (const skipped of enrollment.skipped_sessions ?? []) {
    if (skipped.starts_at) notes.push({ kind: 'skips', text: `Пропуска ${shortDay(sofiaDay(skipped.starts_at))}`, sessionId: skipped.session_id });
  }
  if (enrollment.status === 'ended' && enrollment.ends_on) {
    notes.push(enrollment.moves_to
      ? { kind: 'moves', text: `Премества се в „${enrollment.moves_to.group_name ?? 'друга група'}“ от ${shortDay(enrollment.moves_to.starts_on)}` }
      : { kind: 'ends', text: `Последен ден ${shortDay(enrollment.ends_on)}` });
  }
  return notes;
}

/** Upcoming sessions the child attends and that can still be skipped. */
export function skippableSessions(roster: GroupRoster, enrollment: RosterEnrollment): { id: string; starts_at: string }[] {
  const skipped = new Set((enrollment.skipped_sessions ?? []).map((item) => item.session_id));
  return (roster.upcoming_sessions ?? []).filter((session) => {
    const day = sofiaDay(session.starts_at);
    if (skipped.has(session.id) || day < enrollment.starts_on || (enrollment.ends_on && day > enrollment.ends_on)) return false;
    return !(enrollment.pauses ?? []).some((pause) => pause.starts_on <= day && pause.ends_on >= day);
  });
}
