import { formatEur } from '../catalog/admin/presentation';
import type { GroupRoster, MoveInDay, RosterEnrollment, StaffApplication, StaffGroup, WeeklySchedule } from './api';

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

const weekdayNames = ['', 'понеделник', 'вторник', 'сряда', 'четвъртък', 'петък', 'събота', 'неделя'];

function endTime(start: string, minutes: number): string {
  const [hours = 0, mins = 0] = start.split(':').map(Number);
  const total = hours * 60 + mins + minutes;
  return `${String(Math.floor(total / 60) % 24).padStart(2, '0')}:${String(total % 60).padStart(2, '0')}`;
}

/** "Вторник 16:00–17:00, четвъртък 16:00–17:00"; "През седмица, …" for every other week; '' without a schedule. */
export function scheduleText(schedule?: WeeklySchedule | null): string {
  if (!schedule?.slots?.length) return '';
  const items = schedule.slots.map((slot) => {
    const day = weekdayNames[slot.weekday] ?? 'избран ден';
    return slot.duration_minutes > 0 ? `${day} ${slot.starts_at}–${endTime(slot.starts_at, slot.duration_minutes)}` : `${day} ${slot.starts_at}`;
  }).join(', ');
  const text = schedule.frequency === 'biweekly' ? `през седмица, ${items}` : items;
  return text.charAt(0).toUpperCase() + text.slice(1);
}

export type GroupFill = { text: string; full: boolean; percent: number | null };

/** Places in a group in words ("2 от 12 места", "Пълна · 12 от 12") and how full it is, for a bar. */
export function groupFill(group: Pick<StaffGroup, 'enrolled_for_period' | 'capacity_max'>): GroupFill {
  const taken = group.enrolled_for_period;
  if (!group.capacity_max) return { text: taken === 1 ? '1 записано · без ограничение' : `${taken} записани · без ограничение`, full: false, percent: null };
  const full = taken >= group.capacity_max;
  const percent = Math.min(100, Math.round((taken / group.capacity_max) * 100));
  return { text: full ? `Пълна · ${taken} от ${group.capacity_max}` : `${taken} от ${group.capacity_max} места`, full, percent };
}

export type GroupFilter = 'all' | 'free' | 'full' | 'waiting';

export const groupFilterLabels: Record<GroupFilter, string> = { all: 'Всички', free: 'Със свободни места', full: 'Пълни', waiting: 'С чакащи' };

export function matchesGroupFilter(group: StaffGroup, filter: GroupFilter): boolean {
  if (filter === 'waiting') return group.waitlist_count > 0;
  if (filter === 'full') return groupFill(group).full;
  if (filter === 'free') return !groupFill(group).full;
  return true;
}

/** Search in the activity, group and place names, without regard to case. */
export function matchesGroupSearch(group: StaffGroup, search: string): boolean {
  const needle = search.trim().toLocaleLowerCase('bg');
  if (!needle) return true;
  return [group.activity_title, group.name, group.location_name].some((value) => value?.toLocaleLowerCase('bg').includes(needle));
}

/** "1 чака", "3 чакат". */
export function waitingText(count: number): string {
  return count === 1 ? '1 чака' : `${count} чакат`;
}

/** A group with a free place and children waiting: work for the team. */
export function hasPlaceForWaiting(group: StaffGroup): boolean {
  return group.waitlist_count > 0 && !groupFill(group).full;
}

export type ActivityGroups = {
  id: string; title: string; category: string | null; groups: StaffGroup[];
  taken: number; capacity: number | null; waiting: number; full: number; placeForWaiting: number;
};

/** Groups under their activity, in the order the API sorts them (activity, then group). */
export function groupsByActivity(groups: StaffGroup[]): ActivityGroups[] {
  const sections = new Map<string, ActivityGroups>();
  for (const group of groups) {
    const id = group.activity_id ?? `group-${group.id}`;
    const section = sections.get(id) ?? { id, title: group.activity_title ?? 'Дейност', category: group.activity_category ?? null, groups: [], taken: 0, capacity: 0, waiting: 0, full: 0, placeForWaiting: 0 };
    section.groups.push(group);
    section.taken += group.enrolled_for_period;
    section.waiting += group.waitlist_count;
    section.capacity = section.capacity === null || !group.capacity_max ? null : section.capacity + group.capacity_max;
    if (groupFill(group).full) section.full += 1;
    if (hasPlaceForWaiting(group)) section.placeForWaiting += 1;
    sections.set(id, section);
  }
  return [...sections.values()];
}

/** "3 групи · 26 от 32 места · 1 пълна · 3 чакат" for an activity heading; with a filter, how many groups are shown. */
export function activitySummary(section: ActivityGroups, shown = section.groups.length): string {
  const total = section.groups.length;
  const groups = shown < total ? `показани ${shown} от ${total} групи` : total === 1 ? '1 група' : `${total} групи`;
  const places = section.capacity ? `${section.taken} от ${section.capacity} места` : `${section.taken} записани`;
  const full = section.full === 0 ? '' : section.full === 1 ? ' · 1 пълна' : ` · ${section.full} пълни`;
  const waiting = section.waiting > 0 ? ` · ${waitingText(section.waiting)}` : '';
  return `${groups} · ${places}${full}${waiting}`;
}

/** How full the group of an application is today, in words; nothing when the team cannot know. */
export function applicationPlaces(group: StaffApplication['group']): { text: string; full: boolean } | undefined {
  if (group.places_taken === undefined) return undefined;
  if (!group.capacity_max) return { text: 'Без ограничение на местата', full: false };
  const full = group.places_taken >= group.capacity_max;
  return { text: full ? `Групата е пълна · ${group.places_taken} от ${group.capacity_max}` : placesText(group.capacity_max - group.places_taken), full };
}

const dayFormat = new Intl.DateTimeFormat('bg-BG', { weekday: 'short', day: 'numeric', month: 'long', timeZone: 'Europe/Sofia' });

/** "чт, 26 ноември" for a calendar day "2026-11-26". */
export function weekdayDay(day: string): string {
  return dayFormat.format(new Date(`${day}T12:00:00Z`));
}

/** A move from this day has a place (no limit counts as a place). */
export function hasPlace(day: MoveInDay): boolean {
  return day.remaining === null || day.remaining > 0;
}

/** One line about when a move into a group is possible, from its next session days. */
export function moveInSummary(starts: MoveInDay[] | undefined): { text: string; possible: boolean } {
  if (!starts?.length) return { text: 'Няма насрочени занимания', possible: false };
  const first = starts.find(hasPlace);
  if (!first) return { text: `Няма място в следващите ${starts.length} занимания`, possible: false };
  if (first === starts[0]) return { text: first.remaining === null ? 'Има място от следващото занимание' : `Има място от следващото занимание · ${placesText(first.remaining)}`, possible: true };
  return { text: `Свободно място от ${shortDay(first.date)}`, possible: true };
}
