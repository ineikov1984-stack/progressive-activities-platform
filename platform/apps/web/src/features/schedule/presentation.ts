import type { ScheduleActivity, ScheduleBoard, ScheduleGroup, ScheduleInstructor, ScheduleSession, SessionChild } from './api';

/*
 * "График" (docs/product/schedule.md, т. 3–4): what the page shows, without React — the days and hours by Sofia, the
 * week's layout, the address of the page, the words for the states and the people.
 */

export const SCHEDULE_TIMEZONE = 'Europe/Sofia';

/** The views of PR 2; "Месец" and "Ден по зали" join them in PR 3 (т. 8), in the order "Месец · Седмица · Ден · Карти". */
export type ScheduleView = 'week' | 'cards';
export const scheduleViews: { id: ScheduleView; label: string }[] = [
  { id: 'week', label: 'Седмица' },
  { id: 'cards', label: 'Карти' },
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

// ---- The address of the page --------------------------------------------------------------------------------------

export type FilterKey = 'activity' | 'group' | 'instructor' | 'location';
export const filterKeys: FilterKey[] = ['activity', 'group', 'instructor', 'location'];
export type ScheduleFilters = Record<FilterKey, string[]>;
export const noFilters: ScheduleFilters = { activity: [], group: [], instructor: [], location: [] };

/** What the address holds: `?view=week&date=2026-11-18&activity=…&group=…` (т. 3). */
export type ScheduleAddress = { view: ScheduleView | null; date: string | null; filters: ScheduleFilters };

/** As many as the API takes in one filter. */
const MAX_CHOICES = 50;
const uuid = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

/**
 * Reads the address. Anything that is not a known view, a real day or an identifier is left out, so that a broken
 * link opens the schedule instead of an error. "Моят график" has no filter by teacher: one from a colleague's link is
 * dropped, so that no filter works unseen.
 */
export function parseScheduleAddress(search: string | URLSearchParams, options: { own?: boolean } = {}): ScheduleAddress {
  const params = typeof search === 'string' ? new URLSearchParams(search) : search;
  const view = params.get('view');
  const date = params.get('date');
  const filters = { ...noFilters };
  for (const key of filterKeys) {
    if (key === 'instructor' && options.own) continue;
    filters[key] = [...new Set(params.getAll(key).filter((value) => uuid.test(value)).map((value) => value.toLowerCase()))].slice(0, MAX_CHOICES);
  }
  return {
    view: scheduleViews.some((item) => item.id === view) ? view as ScheduleView : null,
    date: isDay(date) ? date : null,
    filters,
  };
}

/** The address for a state: "?view=week&date=2026-11-18&activity=…", in a stable order. */
export function scheduleAddress(address: ScheduleAddress): string {
  const params = new URLSearchParams();
  if (address.view) params.set('view', address.view);
  if (address.date) params.set('date', address.date);
  for (const key of filterKeys) for (const id of address.filters[key]) params.append(key, id);
  const query = params.toString();
  return query ? `?${query}` : '';
}

/** The request for the week: `from`, `to` and `activity[]`, … as the API reads them (т. 6.1). */
export function scheduleQuery(week: Pick<Week, 'from' | 'to'>, filters: ScheduleFilters): string {
  const params = new URLSearchParams({ from: week.from, to: week.to });
  for (const key of filterKeys) for (const id of filters[key]) params.append(`${key}[]`, id);
  return params.toString();
}

export function hasFilters(filters: ScheduleFilters): boolean {
  return filterKeys.some((key) => filters[key].length > 0);
}

export function toggleFilter(filters: ScheduleFilters, key: FilterKey, id: string): ScheduleFilters {
  const chosen = filters[key];
  return { ...filters, [key]: chosen.includes(id) ? chosen.filter((item) => item !== id) : [...chosen, id] };
}

// ---- The chosen view, remembered in the browser -------------------------------------------------------------------

export function scheduleViewStorageKey(userId: string): string {
  return `progressive:schedule-view:${userId}`;
}

export function parseStoredView(value: string | null | undefined): ScheduleView | undefined {
  return scheduleViews.find((item) => item.id === value)?.id;
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

// ---- The week ------------------------------------------------------------------------------------------------------

/** The shortest a block is drawn, so that its time and name fit. */
const MIN_MINUTES = 30;
/** More sessions at the same time than this give "+N още" (т. 3.1). */
export const MAX_LANES = 4;

export type WeekBlock =
  | { kind: 'session'; session: ScheduleSession; start: number; end: number; lane: number; lanes: number }
  | { kind: 'more'; sessions: ScheduleSession[]; start: number; end: number; lane: number; lanes: number };
export type WeekLayout = { startHour: number; endHour: number; days: { day: string; blocks: WeekBlock[] }[] };

type Placed = { session: ScheduleSession; start: number; end: number; lane: number };

function span(session: ScheduleSession, day: string): { start: number; end: number } {
  const start = sofiaMinutes(session.starts_at);
  // A session past midnight is drawn to the end of its day.
  const end = sofiaDay(session.ends_at) === day ? sofiaMinutes(session.ends_at) : 24 * 60;
  return { start, end: Math.min(24 * 60, Math.max(end, start + MIN_MINUTES)) };
}

/**
 * The week as a calendar (т. 3.1): each session in its day by Sofia, the hours from the earliest start to the latest
 * end with an hour above and below. Sessions at the same time stand side by side; up to four at once all show, with
 * more the first three show and the fourth place is "+N още" with the rest.
 */
export function weekLayout(sessions: ScheduleSession[], days: string[], maxLanes = MAX_LANES): WeekLayout {
  const byDay = new Map(days.map((day) => [day, [] as { session: ScheduleSession; start: number; end: number }[]]));
  for (const session of sessions) {
    const day = sofiaDay(session.starts_at);
    byDay.get(day)?.push({ session, ...span(session, day) });
  }
  const all = [...byDay.values()].flat();
  const startHour = all.length ? Math.max(0, Math.floor(Math.min(...all.map((item) => item.start)) / 60) - 1) : 8;
  const endHour = all.length ? Math.min(24, Math.ceil(Math.max(...all.map((item) => item.end)) / 60) + 1) : 20;

  return {
    startHour,
    endHour,
    days: days.map((day) => {
      // Stable: the API's order (start, then activity) stays for the same start.
      const items = (byDay.get(day) ?? []).map((item, index) => ({ ...item, index })).sort((a, b) => a.start - b.start || a.index - b.index);
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
        let lane = laneEnds.findIndex((end) => end <= item.start);
        if (lane === -1) { lane = laneEnds.length; laneEnds.push(item.end); } else laneEnds[lane] = item.end;
        cluster.push({ session: item.session, start: item.start, end: item.end, lane });
        clusterEnd = Math.max(clusterEnd, item.end);
      }
      flush();
      return { day, blocks };
    }),
  };
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

export type FilterChip = { key: FilterKey; id: string; label: string };

/**
 * The chosen filters as labels with ✕: "Място: Двор", "Група: Шахмат · Група 1". A choice that is not among the
 * person's groups still narrows the list, so it is shown too.
 */
export function filterChips(filters: ScheduleFilters, board: Pick<ScheduleBoard, 'activities' | 'groups' | 'locations' | 'instructors'>): FilterChip[] {
  const options = filterOptions(board);
  return filterKeys.flatMap((key) => filters[key].map((id) => {
    const option = options[key].find((item) => item.id === id);
    const text = option ? (key === 'group' && option.section ? `${option.section} · ${option.label}` : option.label) : 'не е във вашия график';
    return { key, id, label: `${filterLabels[key]}: ${text}` };
  }));
}
