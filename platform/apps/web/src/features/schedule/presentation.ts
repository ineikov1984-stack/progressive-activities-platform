import type { ChildCard, ChildCardGroup, ScheduleActivity, ScheduleBoard, ScheduleGroup, ScheduleInstructor, ScheduleSession, SessionChild } from './api';

/*
 * "График" (docs/product/schedule.md, т. 3–4): what the page shows, without React — the days and hours by Sofia, the
 * month, week and day layouts, the address of the page, the words for the states and the people.
 */

export const SCHEDULE_TIMEZONE = 'Europe/Sofia';

/** The four views (т. 3). */
export type ScheduleView = 'month' | 'week' | 'day' | 'cards';
export const scheduleViews: { id: ScheduleView; label: string }[] = [
  { id: 'month', label: 'Месец' },
  { id: 'week', label: 'Седмица' },
  // "По зали · По треньори" is the day's own switch (UX review of "График").
  { id: 'day', label: 'Ден' },
  { id: 'cards', label: 'Карти' },
];

/** "Моят график" has the week and the cards only (т. 2, решение от 31.10.2026). */
export function viewsFor(own: boolean): { id: ScheduleView; label: string }[] {
  return own ? scheduleViews.filter((item) => item.id === 'week' || item.id === 'cards') : scheduleViews;
}

/** The columns of "Ден": the places of the day, or the people who lead (т. 3.3). */
export type DayColumns = 'rooms' | 'teachers';
export const dayColumnChoices: { id: DayColumns; label: string }[] = [
  { id: 'rooms', label: 'По зали' },
  { id: 'teachers', label: 'По треньори' },
];

const months = ['януари', 'февруари', 'март', 'април', 'май', 'юни', 'юли', 'август', 'септември', 'октомври', 'ноември', 'декември'];
const weekdays = ['Понеделник', 'Вторник', 'Сряда', 'Четвъртък', 'Петък', 'Събота', 'Неделя'];
const weekdaysShort = ['пн', 'вт', 'ср', 'чт', 'пт', 'сб', 'нд'];

// ---- Days and hours, always by Sofia ------------------------------------------------------------------------------

const sofiaFormat = new Intl.DateTimeFormat('en-CA', {
  timeZone: SCHEDULE_TIMEZONE, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
});

function sofiaParts(moment: Date | string): { day: string; minutes: number } {
  const parts = sofiaFormat.formatToParts(typeof moment === 'string' ? new Date(moment) : moment);
  const part = (type: string) => parts.find((item) => item.type === type)?.value ?? '';
  return { day: `${part('year')}-${part('month')}-${part('day')}`, minutes: Number(part('hour')) * 60 + Number(part('minute')) };
}

/** The day in Sofia, "YYYY-MM-DD". */
export function sofiaDay(moment: Date | string): string {
  return sofiaParts(moment).day;
}

/** Minutes since midnight in Sofia. */
export function sofiaMinutes(moment: Date | string): number {
  return sofiaParts(moment).minutes;
}

export function clock(minutes: number): string {
  const hours = Math.floor(minutes / 60) % 24;
  return `${String(hours).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`;
}

/** "16:00" — the start of a session in Sofia. */
export function startClock(session: Pick<ScheduleSession, 'starts_at'>): string {
  return clock(sofiaMinutes(session.starts_at));
}

/** "17:30–18:30". */
export function timeRange(session: Pick<ScheduleSession, 'starts_at' | 'ends_at'>): string {
  return `${clock(sofiaMinutes(session.starts_at))}–${clock(sofiaMinutes(session.ends_at))}`;
}

const dayPattern = /^(\d{4})-(\d{2})-(\d{2})$/;

/** A real calendar day "YYYY-MM-DD" — the address may hold anything. */
export function isDay(value: string | null | undefined): value is string {
  const match = value ? dayPattern.exec(value) : null;
  if (!match) return false;
  const date = new Date(Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3])));
  return date.toISOString().slice(0, 10) === value;
}

/** Calendar arithmetic on the day itself, so the computer's time zone never moves it. */
export function addDays(day: string, days: number): string {
  const date = new Date(`${day}T12:00:00Z`);
  date.setUTCDate(date.getUTCDate() + days);
  return date.toISOString().slice(0, 10);
}

/** 1 — Monday … 7 — Sunday. */
export function weekdayOf(day: string): number {
  return ((new Date(`${day}T12:00:00Z`).getUTCDay() + 6) % 7) + 1;
}

export type Week = { from: string; to: string; days: string[] };

/** The week from Monday to Sunday that holds the day. */
export function weekOf(day: string): Week {
  const from = addDays(day, 1 - weekdayOf(day));
  const days = Array.from({ length: 7 }, (_, index) => addDays(from, index));
  return { from, to: days[6], days };
}

/** "16–22 ноември 2026", "30 ноември – 6 декември 2026", "28 декември 2026 – 3 януари 2027". */
export function periodLabel(from: string, to: string): string {
  const [fy, fm, fd] = from.split('-').map(Number);
  const [ty, tm, td] = to.split('-').map(Number);
  if (fy === ty && fm === tm) return `${fd}–${td} ${months[fm - 1]} ${fy}`;
  if (fy === ty) return `${fd} ${months[fm - 1]} – ${td} ${months[tm - 1]} ${fy}`;
  return `${fd} ${months[fm - 1]} ${fy} – ${td} ${months[tm - 1]} ${ty}`;
}

export type DayName = { weekday: string; short: string; date: string; number: number };

/** "Понеделник", "пн", "16 ноември", 16. */
export function dayName(day: string): DayName {
  const [, month, date] = day.split('-').map(Number);
  const index = weekdayOf(day) - 1;
  return { weekday: weekdays[index], short: weekdaysShort[index], date: `${date} ${months[month - 1]}`, number: date };
}

/** "Сряда, 18 ноември · 17:30–18:30". */
export function sessionWhen(session: Pick<ScheduleSession, 'starts_at' | 'ends_at'>): string {
  const name = dayName(sofiaDay(session.starts_at));
  return `${name.weekday}, ${name.date} · ${timeRange(session)}`;
}

/** Original Sofia day and start time remain stable across repeated moves. */
export function movedFromLabel(session: Pick<ScheduleSession, 'moved_from'>): string | null {
  if (!session.moved_from) return null;
  const name = dayName(sofiaDay(session.moved_from));
  return `Преместено от ${name.weekday.toLocaleLowerCase('bg-BG')}, ${name.date} · ${startClock({ starts_at: session.moved_from })}`;
}

/** Six weeks from the Monday of the week that holds the month's first day (т. 3.2): always 42 days. */
export function monthGrid(day: string): Week & { month: string } {
  const month = day.slice(0, 7);
  const from = weekOf(`${month}-01`).from;
  const days = Array.from({ length: 42 }, (_, index) => addDays(from, index));
  return { month, from, to: days[41], days };
}

export type Period = Week & { label: string };

/**
 * What a view shows for a day and asks the API for: the month's six weeks ("Ноември 2026"), the week of the day
 * ("16–22 ноември 2026", also for the cards) or the day itself ("Сряда, 18 ноември 2026").
 */
export function periodOf(view: ScheduleView, day: string): Period {
  if (view === 'month') {
    const grid = monthGrid(day);
    const [year, month] = grid.month.split('-').map(Number);
    const name = months[month - 1];
    return { from: grid.from, to: grid.to, days: grid.days, label: `${name[0].toUpperCase()}${name.slice(1)} ${year}` };
  }
  if (view === 'day') {
    const name = dayName(day);
    return { from: day, to: day, days: [day], label: `${name.weekday}, ${name.date} ${day.slice(0, 4)}` };
  }
  const week = weekOf(day);
  return { ...week, label: periodLabel(week.from, week.to) };
}

/** ‹ › — a month, a week or a day back or forward. A month goes to its first day. */
export function stepDay(view: ScheduleView, day: string, step: 1 | -1): string {
  if (view === 'day') return addDays(day, step);
  if (view === 'month') {
    const [year, month] = day.split('-').map(Number);
    const first = new Date(Date.UTC(year, month - 1 + step, 1, 12));
    return first.toISOString().slice(0, 10);
  }
  return addDays(weekOf(day).from, 7 * step);
}

export const stepLabels: Record<'month' | 'week' | 'day', { previous: string; next: string }> = {
  month: { previous: 'Предишен месец', next: 'Следващ месец' },
  week: { previous: 'Предишна седмица', next: 'Следваща седмица' },
  day: { previous: 'Предишен ден', next: 'Следващ ден' },
};

export function stepsOf(view: ScheduleView): { previous: string; next: string } {
  return stepLabels[view === 'cards' ? 'week' : view];
}

/** The empty view says what is empty: the chosen filters, or the period. */
export function emptyText(view: ScheduleView, filtered = false): string {
  if (filtered) return 'Няма занимания по избраните филтри.';
  if (view === 'month') return 'Няма занимания в този месец.';
  if (view === 'day') return 'Няма занимания в този ден.';
  return 'Няма занимания в тази седмица.';
}

// ---- The address of the page --------------------------------------------------------------------------------------

/** The filters with several choices each (т. 3.5). */
export type FilterKey = 'activity' | 'group' | 'instructor' | 'location';
export const filterKeys: FilterKey[] = ['activity', 'group', 'instructor', 'location'];
/**
 * The chosen filters. `child` is one child at a time, found by name (т. 3.5, 6.5): only its identifier — the name of a
 * child is never put in an address.
 */
export type ScheduleFilters = Record<FilterKey, string[]> & { child: string | null };
export const noFilters: ScheduleFilters = { activity: [], group: [], instructor: [], location: [], child: null };

/** What the address holds: `?view=day&date=2026-11-18&by=teachers&activity=…&group=…&child=…` (т. 3). */
export type ScheduleAddress = { view: ScheduleView | null; date: string | null; by: DayColumns; filters: ScheduleFilters };

/** As many as the API takes in one filter. */
const MAX_CHOICES = 50;
const uuid = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

/**
 * Reads the address. Anything that is not a known view, a real day or an identifier is left out, so that a broken
 * link opens the schedule instead of an error. "Моят график" has no filter by teacher or by child and only the week
 * and the cards: those from a colleague's link are dropped, so that no filter works unseen. `children: false` — the
 * person may not filter by a child (`childFilterAllowed`), so a `child=` from a link is dropped too.
 */
export function parseScheduleAddress(search: string | URLSearchParams, options: { own?: boolean; children?: boolean } = {}): ScheduleAddress {
  const params = typeof search === 'string' ? new URLSearchParams(search) : search;
  const view = params.get('view');
  const date = params.get('date');
  const filters = { ...noFilters };
  for (const key of filterKeys) {
    if (key === 'instructor' && options.own) continue;
    filters[key] = [...new Set(params.getAll(key).filter((value) => uuid.test(value)).map((value) => value.toLowerCase()))].slice(0, MAX_CHOICES);
  }
  const child = params.get('child');
  filters.child = !options.own && options.children !== false && child && uuid.test(child) ? child.toLowerCase() : null;
  return {
    view: viewsFor(Boolean(options.own)).find((item) => item.id === view)?.id ?? null,
    date: isDay(date) ? date : null,
    by: params.get('by') === 'teachers' ? 'teachers' : 'rooms',
    filters,
  };
}

/** The address for a state: "?view=day&date=2026-11-18&by=teachers&activity=…", in a stable order; by places is the default. */
export function scheduleAddress(address: ScheduleAddress): string {
  const params = new URLSearchParams();
  if (address.view) params.set('view', address.view);
  if (address.date) params.set('date', address.date);
  if (address.by === 'teachers') params.set('by', 'teachers');
  for (const key of filterKeys) for (const id of address.filters[key]) params.append(key, id);
  if (address.filters.child) params.set('child', address.filters.child);
  const query = params.toString();
  return query ? `?${query}` : '';
}

/** The request for the period: `from`, `to`, `activity[]`, … and `child` as the API reads them (т. 6.1). */
export function scheduleQuery(period: Pick<Week, 'from' | 'to'>, filters: ScheduleFilters): string {
  const params = new URLSearchParams({ from: period.from, to: period.to });
  for (const key of filterKeys) for (const id of filters[key]) params.append(`${key}[]`, id);
  if (filters.child) params.set('child', filters.child);
  return params.toString();
}

export function hasFilters(filters: ScheduleFilters): boolean {
  return filterKeys.some((key) => filters[key].length > 0) || filters.child !== null;
}

/** One child at a time: a new choice takes the place of the old one; null takes it away. */
export function chooseChild(filters: ScheduleFilters, childId: string | null): ScheduleFilters {
  return { ...filters, child: childId };
}

export function toggleFilter(filters: ScheduleFilters, key: FilterKey, id: string): ScheduleFilters {
  const chosen = filters[key];
  return { ...filters, [key]: chosen.includes(id) ? chosen.filter((item) => item !== id) : [...chosen, id] };
}

// ---- The chosen view, remembered in the browser -------------------------------------------------------------------

export function scheduleViewStorageKey(userId: string): string {
  return `progressive:schedule-view:${userId}`;
}

export function parseStoredView(value: string | null | undefined, own = false): ScheduleView | undefined {
  return viewsFor(own).find((item) => item.id === value)?.id;
}

/** Without a choice: the week on a tablet and a computer (768 px and wider), the cards on a phone (т. 3). */
export function defaultView(viewportWidth: number): ScheduleView {
  return viewportWidth >= 768 ? 'week' : 'cards';
}

// ---- Names: activities, groups, places, people --------------------------------------------------------------------

/** The five colours of the activities (т. 3.6), by the order of the activities, again from the sixth. */
export const activityColors = ['art', 'science', 'sport', 'music', 'language'] as const;
export type ActivityColor = (typeof activityColors)[number];

export function activityColor(index: number | null | undefined): ActivityColor {
  const value = typeof index === 'number' && Number.isInteger(index) && index >= 0 ? index : 0;
  return activityColors[value % activityColors.length];
}

/** The design token of the colour. */
export function activityColorToken(index: number | null | undefined): string {
  return `--cat-${activityColor(index)}`;
}

export type ScheduleNames = {
  activity: (id: string | null) => ScheduleActivity | undefined;
  group: (id: string) => ScheduleGroup | undefined;
  location: (id: string | null) => string | undefined;
  instructor: (id: string) => string | undefined;
};

export function scheduleNames(board: Pick<ScheduleBoard, 'activities' | 'groups' | 'locations' | 'instructors'>): ScheduleNames {
  const activities = new Map(board.activities.map((item) => [item.id, item]));
  const groups = new Map(board.groups.map((item) => [item.id, item]));
  const locations = new Map(board.locations.map((item) => [item.id, item.name]));
  const people = new Map(board.instructors.map((item) => [item.id, item.name]));
  return {
    activity: (id) => (id ? activities.get(id) : undefined),
    group: (id) => groups.get(id),
    location: (id) => (id ? locations.get(id) : undefined),
    instructor: (id) => people.get(id),
  };
}

/** "Група 2 · 3.–4. клас". */
export function groupTitle(group: Pick<ScheduleGroup, 'name' | 'age_label'> | undefined): string {
  if (!group) return 'Група';
  return group.age_label ? `${group.name} · ${group.age_label}` : group.name;
}

/** "Мария Колева", "Иван Петров (помощник)", "Деси Маринова · замества Ани Петрова". */
export function instructorLabel(instructor: ScheduleInstructor): string {
  if (instructor.role === 'assistant') return `${instructor.name} (помощник)`;
  const replaces = instructor.role === 'substitute' ? instructor.replaces ?? [] : [];
  return replaces.length > 0 ? `${instructor.name} · замества ${replaces.map((person) => person.name).join(', ')}` : instructor.name;
}

/** The people of a session, in the API's order: those who lead, those who stand in, the assistants. */
export function instructorsText(instructors: ScheduleInstructor[]): string {
  return instructors.map(instructorLabel).join(', ');
}

function places(count: number): string {
  return count === 1 ? 'място' : 'места';
}

/** "10/12 места" on a card, "8 от 10 места" in the panel; a group without a limit says only how many come. */
export function placesText(session: Pick<ScheduleSession, 'capacity' | 'taken'>, style: 'short' | 'long'): string {
  if (session.capacity === null || session.capacity === undefined) return session.taken === 1 ? '1 дете' : `${session.taken} деца`;
  return style === 'short'
    ? `${session.taken}/${session.capacity} ${places(session.capacity)}`
    : `${session.taken} от ${session.capacity} ${places(session.capacity)}`;
}

/**
 * How full the group is at a glance (решение на собственика, 31.10.2026): the share for a thin bar under the block —
 * "low" up to 80 %, "almost" from 80 %, "full" when no place is left — and always the words "Пълна" and "Няма
 * записани", never the colour alone. A group without a limit has no bar.
 */
export type FillLevel = 'none' | 'empty' | 'low' | 'almost' | 'full';
export type Fill = { level: FillLevel; percent: number; word: string | null };

export function fillOf(session: Pick<ScheduleSession, 'capacity' | 'taken'>): Fill {
  const { capacity, taken } = session;
  if (capacity === null || capacity === undefined || capacity <= 0) return { level: 'none', percent: 0, word: taken > 0 ? null : 'Няма записани' };
  if (taken <= 0) return { level: 'empty', percent: 0, word: 'Няма записани' };
  if (taken >= capacity) return { level: 'full', percent: 100, word: 'Пълна' };
  const share = taken / capacity;
  return { level: share >= 0.8 ? 'almost' : 'low', percent: Math.round(share * 100), word: null };
}

/**
 * The places with the words: "8/10" in a block ("Пълна" and "Няма записани" alone, where room is short), "8/10 места"
 * on a card, "8 от 10 места" in the panel and the preview; "8 деца" without a limit.
 */
export function fillText(session: Pick<ScheduleSession, 'capacity' | 'taken'>, style: 'block' | 'short' | 'long'): string {
  const fill = fillOf(session);
  if (fill.level === 'none') return fill.word ?? placesText(session, style === 'long' ? 'long' : 'short');
  if (style === 'block') return fill.word ?? `${session.taken}/${session.capacity}`;
  const places = placesText(session, style);
  return fill.word ? `${places} · ${fill.word}` : places;
}

/** The same for every session of the group (т. 5), so it says "в групата". */
export function waitingText(waiting: number): string | null {
  if (!waiting || waiting < 1) return null;
  return waiting === 1 ? '1 чака за място в групата' : `${waiting} чакат за място в групата`;
}

// ---- The state of a session, always in words ----------------------------------------------------------------------

export type SessionMark = 'cancelled' | 'now' | 'later' | 'moved';
export const markLabels: Record<SessionMark, string> = { cancelled: 'Отменено', now: 'Сега', later: 'По-късно', moved: 'Преместено' };

/**
 * "Отменено"; "Сега" between the start and the end; "Преместено" (PR 4); "По-късно" — later today. A past session
 * and one on another day have no mark until the attendance comes (етап 3).
 */
export function sessionMark(session: Pick<ScheduleSession, 'status' | 'starts_at' | 'ends_at' | 'moved'>, now: Date): SessionMark | null {
  if (session.status === 'cancelled') return 'cancelled';
  const start = Date.parse(session.starts_at);
  const end = Date.parse(session.ends_at);
  const time = now.getTime();
  if (time >= start && time < end) return 'now';
  if (session.moved) return 'moved';
  if (start > time && sofiaDay(session.starts_at) === sofiaDay(now)) return 'later';
  return null;
}

/** "Пробно", "еднократно", "пауза", "пропуснато" next to a child's name. */
export function childMarks(child: Pick<SessionChild, 'scope' | 'state'>): string[] {
  const marks: string[] = [];
  if (child.scope === 'trial') marks.push('пробно');
  if (child.scope === 'single') marks.push('еднократно');
  if (child.state === 'paused') marks.push('пауза');
  if (child.state === 'skipped') marks.push('пропуснато');
  return marks;
}

/**
 * Above the children: those who come (the places the session takes) and those away — so that "8 от 10 места" next to
 * 11 names does not look like an overfull group (UX review). A cancelled session lists who would have come.
 */
export function childrenHeading(children: Pick<SessionChild, 'state'>[], cancelled: boolean): string {
  if (cancelled) return `Децата, които щяха да дойдат: ${children.length}`;
  const away = children.filter((child) => child.state === 'paused' || child.state === 'skipped').length;
  const coming = children.length - away;
  const comes = coming === 1 ? '1 идва' : `${coming} идват`;
  if (away === 0) return `Децата: ${comes}`;
  return `Децата: ${comes} · ${away} ${away === 1 ? 'е в пауза или пропуска' : 'в пауза или пропускат'}`;
}

/** The panel shows this many children, then "Още N деца". */
export const CHILDREN_SHOWN = 8;

export function moreChildrenText(count: number): string {
  return count === 1 ? 'Още 1 дете' : `Още ${count} деца`;
}

/**
 * The eye next to a child: their enrollment in "Записвания" — the group's list, and "Подробности" for those who see
 * the enrollments (until the child's profile, етап 4).
 */
export function childLink(groupId: string, enrollmentId: string, withDetails: boolean): string {
  const params = new URLSearchParams({ view: 'groups', group: groupId });
  if (withDetails) params.set('enrollment', enrollmentId);
  return `/dashboard/enrollments?${params.toString()}`;
}

/** What a block says to a screen reader: "Сряда, 18 ноември · 17:30–18:30, Шахмат, Група 2, Зала „Слънце“, Отменено". */
export function sessionLabel(session: ScheduleSession, names: ScheduleNames, now: Date): string {
  const mark = sessionMark(session, now);
  return [
    sessionWhen(session),
    names.activity(session.activity_id)?.title,
    names.group(session.group_id)?.name,
    names.location(session.location_id),
    mark ? markLabels[mark] : undefined,
  ].filter(Boolean).join(', ');
}

// ---- The week and the day: a time grid ---------------------------------------------------------------------------

/** The shortest a block is drawn, so that its time and name fit. */
const MIN_MINUTES = 30;
/**
 * At most two side by side (UX review of "График"): with more at the same time, the first one and "+N още" — in the
 * week it opens the day, in the day the panel with the rest.
 */
export const MAX_LANES = 2;

export type WeekBlock =
  | { kind: 'session'; session: ScheduleSession; start: number; end: number; lane: number; lanes: number }
  | { kind: 'more'; sessions: ScheduleSession[]; start: number; end: number; lane: number; lanes: number };
export type WeekLayout = { startHour: number; endHour: number; days: { day: string; blocks: WeekBlock[] }[] };

type Spanned = { session: ScheduleSession; start: number; end: number };
type Placed = Spanned & { lane: number };

function span(session: ScheduleSession, day: string): Spanned {
  const start = sofiaMinutes(session.starts_at);
  // A session past midnight is drawn to the end of its day.
  const end = sofiaDay(session.ends_at) === day ? sofiaMinutes(session.ends_at) : 24 * 60;
  return { session, start, end: Math.min(24 * 60, Math.max(end, start + MIN_MINUTES)) };
}

/** From an hour before the earliest start to an hour after the latest end; a working day's hours when there is none. */
function hours(items: Spanned[]): { startHour: number; endHour: number } {
  if (items.length === 0) return { startHour: 8, endHour: 20 };
  return {
    startHour: Math.max(0, Math.floor(Math.min(...items.map((item) => item.start)) / 60) - 1),
    endHour: Math.min(24, Math.ceil(Math.max(...items.map((item) => item.end)) / 60) + 1),
  };
}

/**
 * Sessions at the same time stand side by side; up to `maxLanes` at once all show, with more the first ones fill all
 * but the last place, which is "+N още" with the rest.
 */
function placeBlocks(spanned: Spanned[], maxLanes: number): WeekBlock[] {
  // Stable: the API's order (start, then activity) stays for the same start.
  const items = spanned.map((item, index) => ({ ...item, index })).sort((a, b) => a.start - b.start || a.index - b.index);
  const blocks: WeekBlock[] = [];
  let cluster: Placed[] = [];
  let clusterEnd = -1;
  let laneEnds: number[] = [];
  const flush = () => {
    if (cluster.length === 0) return;
    const lanes = laneEnds.length;
    if (lanes <= maxLanes) {
      for (const item of cluster) blocks.push({ kind: 'session', ...item, lanes });
    } else {
      const hidden = cluster.filter((item) => item.lane >= maxLanes - 1);
      for (const item of cluster) if (item.lane < maxLanes - 1) blocks.push({ kind: 'session', ...item, lanes: maxLanes });
      blocks.push({
        kind: 'more', sessions: hidden.map((item) => item.session), lane: maxLanes - 1, lanes: maxLanes,
        start: Math.min(...hidden.map((item) => item.start)), end: Math.max(...hidden.map((item) => item.end)),
      });
    }
    cluster = [];
    laneEnds = [];
    clusterEnd = -1;
  };
  for (const item of items) {
    if (item.start >= clusterEnd) flush();
    let lane = laneEnds.findIndex((laneEnd) => laneEnd <= item.start);
    if (lane === -1) { lane = laneEnds.length; laneEnds.push(item.end); } else laneEnds[lane] = item.end;
    cluster.push({ session: item.session, start: item.start, end: item.end, lane });
    clusterEnd = Math.max(clusterEnd, item.end);
  }
  flush();
  return blocks;
}

/**
 * The week as a calendar (т. 3.1): each session in its day by Sofia, the hours from the earliest start to the latest
 * end with an hour above and below. Up to four at once all show; with more, three and "+N още".
 */
export function weekLayout(sessions: ScheduleSession[], days: string[], maxLanes = MAX_LANES): WeekLayout {
  const byDay = new Map(days.map((day) => [day, [] as Spanned[]]));
  for (const session of sessions) {
    const day = sofiaDay(session.starts_at);
    byDay.get(day)?.push(span(session, day));
  }
  return {
    ...hours([...byDay.values()].flat()),
    days: days.map((day) => ({ day, blocks: placeBlocks(byDay.get(day) ?? [], maxLanes) })),
  };
}

export type DayColumn = { id: string; title: string; blocks: WeekBlock[] };
export type DayLayout = { startHour: number; endHour: number; columns: DayColumn[] };
/** The column of the sessions without a place, or without anyone who leads. */
export const NO_COLUMN = 'none';

/** Who a column by people holds: those who lead and those who stand in; an assistant is in the block, not a column. */
function leaders(session: ScheduleSession): ScheduleInstructor[] {
  return session.instructors.filter((person) => person.role !== 'assistant');
}

/**
 * "Ден" (т. 3.3): by places — the places of the day's sessions in the order of the dictionary, and "Без зала" last
 * when a session has no place; by people — those who lead or stand in, in the order of the dictionary, and "Без
 * преподавател" last. A session led by two is in both columns. The hours are the day's.
 */
export function dayLayout(
  sessions: ScheduleSession[], day: string, by: DayColumns,
  dictionary: Pick<ScheduleBoard, 'locations' | 'instructors'>, maxLanes = MAX_LANES,
): DayLayout {
  const items = sessions.filter((session) => sofiaDay(session.starts_at) === day).map((session) => span(session, day));
  const columns = new Map<string, { title: string; items: Spanned[] }>();
  const add = (id: string, title: string, item: Spanned) => {
    const column = columns.get(id) ?? { title, items: [] };
    column.items.push(item);
    columns.set(id, column);
  };
  for (const item of items) {
    if (by === 'rooms') {
      const place = item.session.location_id;
      const name = place ? dictionary.locations.find((location) => location.id === place)?.name : undefined;
      if (place && name) add(place, name, item); else add(NO_COLUMN, 'Без зала', item);
    } else {
      const people = leaders(item.session);
      if (people.length === 0) add(NO_COLUMN, 'Без преподавател', item);
      for (const person of people) add(person.profile_id, person.name, item);
    }
  }
  const order = (by === 'rooms' ? dictionary.locations : dictionary.instructors).map((entry) => entry.id);
  const rank = (id: string) => (id === NO_COLUMN ? Number.MAX_SAFE_INTEGER : order.indexOf(id) === -1 ? order.length : order.indexOf(id));
  return {
    ...hours(items),
    columns: [...columns.entries()]
      .sort(([a, first], [b, second]) => rank(a) - rank(b) || first.title.localeCompare(second.title, 'bg'))
      .map(([id, column]) => ({ id, title: column.title, blocks: placeBlocks(column.items, maxLanes) })),
  };
}

/** The third line of a block in "Ден": who leads by places; the place, and whom one stands in for, by people. */
export function dayBlockDetail(session: ScheduleSession, by: DayColumns, names: Pick<ScheduleNames, 'location'>): string {
  if (by === 'rooms') return instructorsText(session.instructors) || 'Без преподавател';
  const replaces = session.instructors.flatMap((person) => (person.role === 'substitute' ? person.replaces ?? [] : []));
  return [names.location(session.location_id) ?? 'Без зала', replaces.length ? `замества ${replaces.map((person) => person.name).join(', ')}` : '']
    .filter(Boolean).join(' · ');
}

// ---- The month ----------------------------------------------------------------------------------------------------

/** In a day of the month: this many lines, then "+N още" (т. 3.2). */
export const MONTH_ROWS = 3;

/** `held` — the sessions that are not cancelled, the number a day shows on a phone. */
export type MonthDay = { day: string; number: number; inMonth: boolean; sessions: ScheduleSession[]; shown: ScheduleSession[]; more: number; held: number; cancelled: number };
export type MonthLayout = { month: string; weeks: MonthDay[][] };

/** The month's six weeks; each day with its sessions by Sofia, the first three of them and how many more. */
export function monthLayout(sessions: ScheduleSession[], day: string, rows = MONTH_ROWS): MonthLayout {
  const grid = monthGrid(day);
  const byDay = new Map(grid.days.map((item) => [item, [] as ScheduleSession[]]));
  for (const session of sessions) byDay.get(sofiaDay(session.starts_at))?.push(session);
  const days = grid.days.map((item) => {
    // The API's order: by start, then by activity.
    const list = [...(byDay.get(item) ?? [])].sort((a, b) => Date.parse(a.starts_at) - Date.parse(b.starts_at));
    const cancelled = list.filter((session) => session.status === 'cancelled').length;
    return {
      day: item, number: Number(item.slice(8)), inMonth: item.startsWith(grid.month), sessions: list, shown: list.slice(0, rows),
      more: Math.max(0, list.length - rows), held: list.length - cancelled, cancelled,
    };
  });
  return { month: grid.month, weeks: Array.from({ length: 6 }, (_, week) => days.slice(week * 7, week * 7 + 7)) };
}

/** "Група 1" → "Гр. 1"; other names stay. */
export function shortGroupName(name: string): string {
  return name.replace(/^Група\s+/u, 'Гр. ');
}

/** A line in a day of the month: "16:00 Шахмат · Гр. 1". */
export function monthLine(session: ScheduleSession, names: Pick<ScheduleNames, 'activity' | 'group'>): string {
  const group = names.group(session.group_id)?.name;
  return `${startClock(session)} ${names.activity(session.activity_id)?.title ?? 'Занимание'}${group ? ` · ${shortGroupName(group)}` : ''}`;
}

/** "3 занимания", "1 занимание", "няма занимания"; with the cancelled ones apart: "3 занимания, 1 отменено". */
export function sessionsCount(count: number, cancelled = 0): string {
  const held = count === 0 ? 'няма занимания' : count === 1 ? '1 занимание' : `${count} занимания`;
  if (cancelled === 0) return held;
  return `${held}, ${cancelled === 1 ? '1 отменено' : `${cancelled} отменени`}`;
}

/**
 * The days of the cards when there is room (UX review): Monday to Friday always, each in its column, Saturday and
 * Sunday after them when they have sessions. On a narrow screen only the days with sessions are shown.
 */
export function cardDays(sessions: ScheduleSession[], days: string[]): { day: string; sessions: ScheduleSession[]; weekday: boolean }[] {
  return days
    .map((day) => ({ day, sessions: sessions.filter((session) => sofiaDay(session.starts_at) === day), weekday: weekdayOf(day) <= 5 }))
    .filter((item) => item.weekday || item.sessions.length > 0);
}

/**
 * What a block in the week or the day says, line by line, and to a screen reader: the first line is the time with the
 * state next to it ("Сега", "Отменено"); the lines that do not fit in a short block are left out whole.
 */
export function blockLines(session: ScheduleSession, kind: 'week' | 'day', by: DayColumns, names: ScheduleNames, now: Date): { time: string; mark: SessionMark | null; lines: string[]; label: string } {
  const mark = sessionMark(session, now);
  const activity = names.activity(session.activity_id)?.title ?? 'Занимание';
  const group = names.group(session.group_id)?.name;
  const place = names.location(session.location_id);
  const fill = mark === 'cancelled' ? null : fillText(session, 'block');
  const lines = kind === 'week'
    ? [activity, fill, group, place]
    : [group ? `${activity} · ${group}` : activity, fill, dayBlockDetail(session, by, names)];
  const label = [
    timeRange(session), activity, group, kind === 'week' ? place : dayBlockDetail(session, by, names),
    mark === 'cancelled' ? null : fillText(session, 'long'), mark && mark !== 'later' ? markLabels[mark] : null,
  ].filter(Boolean).join(', ');
  return { time: kind === 'week' ? startClock(session) : timeRange(session), mark: mark === 'later' ? null : mark, lines: lines.filter((line): line is string => Boolean(line)), label };
}

/**
 * Where the week and the day open (UX review): today's column — in the week, also when it is empty — at the session
 * going on now, else at the first session of that day; another week at its first session. `minute` is of the day.
 */
export function openingPoint(kind: 'week' | 'day', columns: { blocks: WeekBlock[]; today?: boolean }[], now: Date): { column: number; minute: number } | null {
  const nowMinute = sofiaMinutes(now);
  const items = columns.flatMap((column, index) => column.blocks.map((block) => ({ index, today: Boolean(column.today), start: block.start, end: block.end })));
  const running = items.find((item) => item.today && item.start <= nowMinute && nowMinute < item.end);
  if (running) return { column: running.index, minute: running.start };
  const todayColumn = kind === 'week' ? columns.findIndex((column) => column.today) : -1;
  const pool = todayColumn === -1 ? items : items.filter((item) => item.index === todayColumn);
  const first = [...pool].sort((a, b) => a.start - b.start || a.index - b.index)[0];
  if (todayColumn !== -1) return { column: todayColumn, minute: first?.start ?? Math.min(...items.map((item) => item.start), nowMinute) };
  return first ? { column: first.index, minute: first.start } : null;
}

/** The days of the cards (т. 3.4): only those with sessions, in order. */
export function sessionsByDay(sessions: ScheduleSession[], days: string[]): { day: string; sessions: ScheduleSession[] }[] {
  return days
    .map((day) => ({ day, sessions: sessions.filter((session) => sofiaDay(session.starts_at) === day) }))
    .filter((item) => item.sessions.length > 0);
}

/** The activities of the sessions shown, for the legend of the colours. */
export function legend(sessions: ScheduleSession[], activities: ScheduleActivity[]): ScheduleActivity[] {
  const used = new Set(sessions.map((session) => session.activity_id));
  return activities.filter((activity) => used.has(activity.id));
}

// ---- Filters -------------------------------------------------------------------------------------------------------

export const filterLabels: Record<FilterKey, string> = { activity: 'Дейност', group: 'Група', instructor: 'Треньор', location: 'Място' };

export type FilterOption = { id: string; label: string; section?: string };

/** The choices of each filter, from the dictionaries; the groups under their activity. */
export function filterOptions(board: Pick<ScheduleBoard, 'activities' | 'groups' | 'locations' | 'instructors'>): Record<FilterKey, FilterOption[]> {
  const activity = new Map(board.activities.map((item) => [item.id, item.title]));
  return {
    activity: board.activities.map((item) => ({ id: item.id, label: item.title })),
    group: board.groups.map((item) => ({ id: item.id, label: groupTitle(item), section: activity.get(item.activity_id) })),
    instructor: board.instructors.map((item) => ({ id: item.id, label: item.name })),
    location: board.locations.map((item) => ({ id: item.id, label: item.name })),
  };
}

export type FilterChip = { key: FilterKey | 'child'; id: string; label: string };

/**
 * The chosen filters as labels with ✕: "Място: Двор", "Група: Шахмат · Група 1", "Дете: Ана Иванова". A choice that is
 * not among the person's groups still narrows the list, so it is shown too. The child's name only from this page's
 * memory (`childName`) — the address has only the identifier, so after a reload the label says "Избрано дете".
 */
export function filterChips(filters: ScheduleFilters, board: Pick<ScheduleBoard, 'activities' | 'groups' | 'locations' | 'instructors'>, childName?: string): FilterChip[] {
  const options = filterOptions(board);
  const chips: FilterChip[] = filterKeys.flatMap((key) => filters[key].map((id) => {
    const option = options[key].find((item) => item.id === id);
    const text = option ? (key === 'group' && option.section ? `${option.section} · ${option.label}` : option.label) : 'не е във вашия график';
    return { key, id, label: `${filterLabels[key]}: ${text}` };
  }));
  if (filters.child) chips.push({ key: 'child', id: filters.child, label: childChipLabel(childName) });
  return chips;
}

// ---- The filter "Дете" (т. 3.5, 6.5) -------------------------------------------------------------------------------

export const CHILD_FILTER_LABEL = 'Дете';
/** The search starts from two letters and waits this long after the last one (т. 6.5). */
export const CHILD_SEARCH_MIN = 2;
export const CHILD_SEARCH_MAX = 80;
export const CHILD_SEARCH_DELAY_MS = 250;
export const CHILD_SEARCH_HINT = 'Напишете поне 2 букви от името.';
export const NO_CHILDREN_FOUND = 'Няма намерени деца в групите, които виждате.';

/**
 * The filter "Дете" — only for who sees the enrollments (the API's `enrollment.view`, т. 3.5): the rights read from the
 * API (`access.abilities`), else the role's. Never in "Моят график" (т. 2).
 */
export function childFilterAllowed(own: boolean, organization: { permissions: string[]; access?: { abilities: string[] } }): boolean {
  if (own) return false;
  return (organization.access ? organization.access.abilities : organization.permissions).includes('enrollment.view');
}

/**
 * The text to search with: without the spaces around it, from two letters (counted as the API counts them, by
 * character); null — not yet.
 */
export function childSearchText(text: string): string | null {
  const letters = [...text.trim()];
  if (letters.length < CHILD_SEARCH_MIN) return null;
  return letters.slice(0, CHILD_SEARCH_MAX).join('');
}

/** "Ана Иванова · 3. клас"; the name alone without a class. */
export function childOptionLabel(child: { name: string; class: string | null }): string {
  return child.class ? `${child.name} · ${child.class}` : child.name;
}

/** "Дете: Ана Иванова"; "Избрано дете" when the name is not in this page's memory. */
export function childChipLabel(name?: string | null): string {
  return name ? `${CHILD_FILTER_LABEL}: ${name}` : 'Избрано дете';
}

/** What the search says to a screen reader: "Намерено 1 дете", "Намерени 5 деца", or that there is none. */
export function childResultsText(count: number): string {
  if (count === 0) return NO_CHILDREN_FOUND;
  return count === 1 ? 'Намерено 1 дете' : `Намерени ${count} деца`;
}

/**
 * The option the arrows move to in the list (ARIA APG combobox): down from none to the first, up from none to the last,
 * and round at either end; none without options.
 */
export function moveActive(active: number, count: number, key: 'ArrowDown' | 'ArrowUp'): number {
  if (count <= 0) return -1;
  // An option from an older list counts as none.
  const current = active >= count ? -1 : active;
  if (key === 'ArrowDown') return current < 0 || current === count - 1 ? 0 : current + 1;
  return current <= 0 ? count - 1 : current - 1;
}

// ---- The card of a child (т. 4.1) ----------------------------------------------------------------------------------

/** "1 година", "9 години"; nothing without an age. */
export function ageText(age: number | null | undefined): string | null {
  if (typeof age !== 'number' || !Number.isInteger(age) || age < 0) return null;
  return age === 1 ? '1 година' : `${age} години`;
}

/** Under the name: "3. клас · 9 години", or the one that is known. */
export function childCardMeta(card: Pick<ChildCard, 'class' | 'age'>): string {
  return [card.class, ageText(card.age)].filter(Boolean).join(' · ');
}

/** "АИ" — the first letters of the first two names, for the circle next to the name (hidden from screen readers). */
export function initials(name: string): string {
  return name.trim().split(/\s+/u).filter(Boolean).slice(0, 2).map((part) => ([...part][0] ?? '').toLocaleUpperCase('bg')).join('');
}

/** "Шах · Вторник". */
export function cardGroupTitle(group: Pick<ChildCardGroup, 'activity' | 'group'>): string {
  return [group.activity, group.group].filter(Boolean).join(' · ') || 'Група';
}

export type CardStateTone = 'active' | 'paused' | 'visit' | 'ended';

/**
 * The state of a group in words, never the colour alone: "Записано", "Пауза до 30 ноември", "Пробно", "Еднократно",
 * "Приключи на 15 октомври".
 */
export function cardGroupState(group: Pick<ChildCardGroup, 'state' | 'paused_until' | 'ended_on'>): { text: string; tone: CardStateTone } {
  if (group.state === 'paused') return { text: isDay(group.paused_until) ? `Пауза до ${dayName(group.paused_until).date}` : 'Пауза', tone: 'paused' };
  if (group.state === 'trial') return { text: 'Пробно', tone: 'visit' };
  if (group.state === 'single') return { text: 'Еднократно', tone: 'visit' };
  if (group.state === 'ended') return { text: isDay(group.ended_on) ? `Приключи на ${dayName(group.ended_on).date}` : 'Приключи', tone: 'ended' };
  return { text: 'Записано', tone: 'active' };
}

/** Where the card was opened: the session and the child's row in its panel. */
export type CardContext = {
  session: Pick<ScheduleSession, 'starts_at' | 'status' | 'group_id'>;
  child: Pick<SessionChild, 'enrollment_id' | 'state'>;
};

/** The group of the card for the session it was opened from: the same enrollment, else the same group. */
export function sessionGroupOf(card: Pick<ChildCard, 'groups'>, context: CardContext): ChildCardGroup | undefined {
  return card.groups.find((group) => group.enrollment_id === context.child.enrollment_id)
    ?? card.groups.find((group) => group.group_id === context.session.group_id);
}

/**
 * "Пропусни това занимание" (т. 4.1): with "Записва деца" in the session's group, for a session still ahead and not
 * cancelled that the child comes to — not one already skipped or in a pause, and not after the enrollment ended.
 */
export function canSkipSession(card: Pick<ChildCard, 'groups'>, context: CardContext, now: Date): boolean {
  const group = sessionGroupOf(card, context);
  if (!group || !group.abilities.skip || group.state === 'ended') return false;
  return context.child.state === 'active' && context.session.status !== 'cancelled' && Date.parse(context.session.starts_at) > now.getTime();
}

/** "Пауза" (т. 4.1): with "Записва деца", for an enrollment for the period that runs — the session's group first. */
export function pauseGroupOf(card: Pick<ChildCard, 'groups'>, context: CardContext): ChildCardGroup | undefined {
  const pausable = (group: ChildCardGroup) => group.abilities.pause && (group.state === 'active' || group.state === 'paused');
  const own = sessionGroupOf(card, context);
  return own && pausable(own) ? own : card.groups.find(pausable);
}

/** "Покажи телефона" (т. 4.1): through the session's group when the person sees contacts there, else another group. */
export function contactsGroupOf(card: Pick<ChildCard, 'groups'>, context: CardContext): ChildCardGroup | undefined {
  const own = sessionGroupOf(card, context);
  return own?.abilities.contacts ? own : card.groups.find((group) => group.abilities.contacts);
}

/**
 * "Профил на детето →" — until the stage "Профил на детето", the enrollment in "Записвания" (`?enrollment=`), for those
 * who see the enrollments; the session's group first.
 */
export function profileLink(card: Pick<ChildCard, 'groups'>, context: CardContext, withDetails: boolean): string | null {
  if (!withDetails) return null;
  const group = sessionGroupOf(card, context) ?? card.groups[0];
  return group ? childLink(group.group_id, group.enrollment_id, true) : null;
}

/** "Пауза" leads to the enrollment: the dialog for a pause is there, with the group's coming sessions. */
export function pauseLink(group: Pick<ChildCardGroup, 'group_id' | 'enrollment_id'>): string {
  return childLink(group.group_id, group.enrollment_id, true);
}
