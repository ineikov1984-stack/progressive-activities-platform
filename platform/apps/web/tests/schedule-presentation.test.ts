import { describe, expect, it } from 'vitest';
import type { ScheduleSession } from '../src/features/schedule/api';
import {
  activityColor, activityColorToken, addDays, childLink, childMarks, clock, dayName, defaultView, filterChips, filterOptions, groupTitle, instructorLabel,
  instructorsText, isDay, legend, moreChildrenText, noFilters, parseScheduleAddress, parseStoredView, periodLabel, placesText, scheduleAddress,
  scheduleQuery, scheduleViewStorageKey, sessionMark, sessionsByDay, sessionWhen, sofiaDay, sofiaMinutes, startClock, timeRange, toggleFilter,
  waitingText, weekLayout, weekOf,
} from '../src/features/schedule/presentation';

const A = '0a000000-0000-4000-8000-000000000001';
const B = '0a000000-0000-4000-8000-000000000002';
const G = '0b000000-0000-4000-8000-000000000001';
const L = '0c000000-0000-4000-8000-000000000001';
const P = '0d000000-0000-4000-8000-000000000001';

let next = 0;
/** A session by Sofia's wall clock in November (UTC+2): `at('2026-11-18', '17:30', 60)`. */
function at(day: string, time: string, minutes = 60, extra: Partial<ScheduleSession> = {}): ScheduleSession {
  const start = new Date(`${day}T${time}:00+02:00`);
  next += 1;
  return {
    id: `s-${next}`, starts_at: start.toISOString(), ends_at: new Date(start.getTime() + minutes * 60000).toISOString(), status: 'scheduled', moved: false,
    group_id: G, activity_id: A, location_id: null, instructors: [], capacity: 10, taken: 8, waiting: 0, ...extra,
  };
}

describe('schedule — days and hours by Sofia', () => {
  it('puts a session in its day and hour in Sofia across the change of time in October', () => {
    // Saturday 24 October, summer time (UTC+3), and Monday 26 October, winter time (UTC+2): both at 17:00.
    expect(startClock({ starts_at: '2026-10-24T14:00:00Z' })).toBe('17:00');
    expect(startClock({ starts_at: '2026-10-26T15:00:00Z' })).toBe('17:00');
    // Half past midnight on the night of the change is already Sunday in Sofia.
    expect(sofiaDay('2026-10-24T21:30:00Z')).toBe('2026-10-25');
    expect(sofiaMinutes('2026-10-24T21:30:00Z')).toBe(30);
    expect(sofiaDay('2026-10-25T21:30:00Z')).toBe('2026-10-25');
    expect(sofiaMinutes('2026-10-25T21:30:00Z')).toBe(23 * 60 + 30);
  });

  it('and in March', () => {
    expect(startClock({ starts_at: '2026-03-28T15:00:00Z' })).toBe('17:00');
    expect(startClock({ starts_at: '2026-03-30T14:00:00Z' })).toBe('17:00');
    expect(timeRange({ starts_at: '2026-03-29T13:00:00Z', ends_at: '2026-03-29T14:30:00Z' })).toBe('16:00–17:30');
  });

  it('lays a weekly session out at the same hour on both sides of the change', () => {
    const week = weekOf('2026-10-21');
    expect(week).toMatchObject({ from: '2026-10-19', to: '2026-10-25' });
    const saturday = { ...at('2026-10-24', '17:00'), starts_at: '2026-10-24T14:00:00Z', ends_at: '2026-10-24T15:00:00Z' };
    const sunday = { ...at('2026-10-25', '17:00'), starts_at: '2026-10-25T15:00:00Z', ends_at: '2026-10-25T16:00:00Z' };
    const layout = weekLayout([saturday, sunday], week.days);
    expect(layout.days[5].blocks).toMatchObject([{ kind: 'session', start: 17 * 60, end: 18 * 60 }]);
    expect(layout.days[6].blocks).toMatchObject([{ kind: 'session', start: 17 * 60, end: 18 * 60 }]);
  });

  it('counts the days on the calendar, whatever the time zone of the computer', () => {
    expect(addDays('2026-10-25', 1)).toBe('2026-10-26');
    expect(addDays('2026-03-29', -1)).toBe('2026-03-28');
    expect(addDays('2026-12-31', 1)).toBe('2027-01-01');
    expect(weekOf('2026-11-18')).toEqual({ from: '2026-11-16', to: '2026-11-22', days: ['2026-11-16', '2026-11-17', '2026-11-18', '2026-11-19', '2026-11-20', '2026-11-21', '2026-11-22'] });
    expect(weekOf('2026-11-16').from).toBe('2026-11-16');
    expect(weekOf('2026-11-22').from).toBe('2026-11-16');
    expect(weekOf('2027-01-01')).toMatchObject({ from: '2026-12-28', to: '2027-01-03' });
  });

  it('names the period, the day and the time in Bulgarian', () => {
    expect(periodLabel('2026-11-16', '2026-11-22')).toBe('16–22 ноември 2026');
    expect(periodLabel('2026-11-30', '2026-12-06')).toBe('30 ноември – 6 декември 2026');
    expect(periodLabel('2026-12-28', '2027-01-03')).toBe('28 декември 2026 – 3 януари 2027');
    expect(dayName('2026-11-18')).toEqual({ weekday: 'Сряда', short: 'ср', date: '18 ноември', number: 18 });
    expect(dayName('2026-11-22')).toMatchObject({ weekday: 'Неделя', short: 'нд' });
    expect(sessionWhen(at('2026-11-18', '17:30'))).toBe('Сряда, 18 ноември · 17:30–18:30');
    expect(clock(9 * 60 + 5)).toBe('09:05');
  });

  it('takes only real days', () => {
    expect(isDay('2026-11-18')).toBe(true);
    expect(isDay('2026-02-30')).toBe(false);
    expect(isDay('2026-1-8')).toBe(false);
    expect(isDay('утре')).toBe(false);
    expect(isDay(null)).toBe(false);
  });
});

describe('schedule — the week as a calendar', () => {
  const days = weekOf('2026-11-18').days;

  it('starts an hour before the earliest session of the week and ends an hour after the latest', () => {
    const layout = weekLayout([at('2026-11-16', '16:00'), at('2026-11-19', '18:00', 45)], days);
    expect(layout).toMatchObject({ startHour: 15, endHour: 20 });
    expect(layout.days[0].blocks).toMatchObject([{ kind: 'session', start: 960, end: 1020, lane: 0, lanes: 1 }]);
    expect(layout.days[3].blocks).toMatchObject([{ start: 1080, end: 1125 }]);
    expect(layout.days[1].blocks).toEqual([]);
    // Nothing this week: a working day's hours.
    expect(weekLayout([], days)).toMatchObject({ startHour: 8, endHour: 20 });
    // Never before midnight or after the end of the day.
    expect(weekLayout([at('2026-11-16', '00:30'), at('2026-11-17', '23:00')], days)).toMatchObject({ startHour: 0, endHour: 24 });
  });

  it('draws a short session big enough to read, one past midnight to the end of its day, and leaves out other weeks', () => {
    const layout = weekLayout([at('2026-11-16', '16:00', 15), at('2026-11-17', '23:00', 120), at('2026-11-23', '16:00')], days);
    expect(layout.days[0].blocks[0]).toMatchObject({ start: 960, end: 990 });
    expect(layout.days[1].blocks[0]).toMatchObject({ start: 1380, end: 1440 });
    expect(layout.days.flatMap((day) => day.blocks)).toHaveLength(2);
  });

  it('puts sessions at the same time side by side, and the next free place is reused', () => {
    const first = at('2026-11-18', '16:00');
    const second = at('2026-11-18', '16:30');
    const third = at('2026-11-18', '17:00');
    const alone = at('2026-11-18', '19:00');
    const blocks = weekLayout([first, second, third, alone], days).days[2].blocks;
    expect(blocks.map((block) => block.kind === 'session' && [block.session.id, block.lane, block.lanes])).toEqual([
      [first.id, 0, 2], [second.id, 1, 2], [third.id, 0, 2], [alone.id, 0, 1],
    ]);
  });

  it('keeps the order of the API for the same start (then by activity)', () => {
    const chess = at('2026-11-18', '17:00', 60, { activity_id: B });
    const art = at('2026-11-18', '17:00');
    const blocks = weekLayout([chess, art], days).days[2].blocks;
    expect(blocks.map((block) => block.kind === 'session' && block.session.id)).toEqual([chess.id, art.id]);
  });

  it('shows four sessions at once', () => {
    const four = [0, 1, 2, 3].map(() => at('2026-11-18', '17:00'));
    const blocks = weekLayout(four, days).days[2].blocks;
    expect(blocks).toHaveLength(4);
    expect(blocks.every((block) => block.kind === 'session' && block.lanes === 4)).toBe(true);
  });

  it('with more than four at once shows three and "+N още" with the rest', () => {
    const six = [0, 1, 2, 3, 4, 5].map((index) => at('2026-11-18', index < 5 ? '17:00' : '17:30'));
    const earlier = at('2026-11-18', '15:00');
    const blocks = weekLayout([earlier, ...six], days).days[2].blocks;
    expect(blocks[0]).toMatchObject({ kind: 'session', lanes: 1 });
    const shown = blocks.filter((block) => block.kind === 'session' && block.session.id !== earlier.id);
    expect(shown.map((block) => block.kind === 'session' && [block.session.id, block.lane, block.lanes])).toEqual([
      [six[0].id, 0, 4], [six[1].id, 1, 4], [six[2].id, 2, 4],
    ]);
    const more = blocks.find((block) => block.kind === 'more');
    expect(more).toMatchObject({ kind: 'more', lane: 3, lanes: 4, start: 17 * 60, end: 18 * 60 + 30 });
    expect(more?.kind === 'more' && more.sessions.map((session) => session.id)).toEqual([six[3].id, six[4].id, six[5].id]);
  });

  it('gives the cards only the days with sessions, in order, and the legend only the activities shown', () => {
    const wednesday = at('2026-11-18', '17:00');
    const monday = at('2026-11-16', '16:00', 60, { activity_id: B });
    expect(sessionsByDay([wednesday, monday], days)).toEqual([{ day: '2026-11-16', sessions: [monday] }, { day: '2026-11-18', sessions: [wednesday] }]);
    const activities = [{ id: A, title: 'Рисуване', color: 0 }, { id: B, title: 'Шахмат', color: 1 }, { id: 'x', title: 'Футбол', color: 2 }];
    expect(legend([wednesday, monday], activities).map((item) => item.title)).toEqual(['Рисуване', 'Шахмат']);
  });
});

describe('schedule — the address', () => {
  it('reads the view, the day and the filters, and leaves out what is broken', () => {
    const address = parseScheduleAddress(`?view=week&date=2026-11-18&activity=${A}&activity=${A.toUpperCase()}&group=${G}&instructor=nope&location=${L}`);
    expect(address).toEqual({ view: 'week', date: '2026-11-18', filters: { activity: [A], group: [G], instructor: [], location: [L] } });
    // "Месец" and "Ден" come in PR 3; an unknown view and a wrong day fall back to the defaults.
    expect(parseScheduleAddress('?view=month&date=2026-02-30')).toEqual({ view: null, date: null, filters: noFilters });
    expect(parseScheduleAddress(new URLSearchParams({ view: 'cards' })).view).toBe('cards');
    const many = new URLSearchParams();
    for (let index = 0; index < 60; index += 1) many.append('group', `0b000000-0000-4000-8000-${String(index).padStart(12, '0')}`);
    expect(parseScheduleAddress(many).filters.group).toHaveLength(50);
  });

  it('has no filter by teacher in "Моят график", so a colleague\'s link filters nothing unseen', () => {
    expect(parseScheduleAddress(`?instructor=${P}&group=${G}`, { own: true }).filters).toEqual({ ...noFilters, group: [G] });
    expect(parseScheduleAddress(`?instructor=${P}`).filters.instructor).toEqual([P]);
  });

  it('writes the state back in a stable order, and the address reads the same state again', () => {
    const state = { view: 'cards' as const, date: '2026-11-18', filters: { activity: [A, B], group: [], instructor: [P], location: [L] } };
    const search = scheduleAddress(state);
    expect(search).toBe(`?view=cards&date=2026-11-18&activity=${A}&activity=${B}&instructor=${P}&location=${L}`);
    expect(parseScheduleAddress(search)).toEqual(state);
    expect(scheduleAddress({ view: null, date: null, filters: noFilters })).toBe('');
  });

  it('asks the API for the week from Monday to Sunday with the filters as lists', () => {
    const query = new URLSearchParams(scheduleQuery(weekOf('2026-11-18'), { ...noFilters, activity: [A, B], location: [L] }));
    expect(query.get('from')).toBe('2026-11-16');
    expect(query.get('to')).toBe('2026-11-22');
    expect(query.getAll('activity[]')).toEqual([A, B]);
    expect(query.getAll('location[]')).toEqual([L]);
    expect(query.has('group[]')).toBe(false);
  });

  it('adds and takes away one choice', () => {
    const added = toggleFilter(noFilters, 'group', G);
    expect(added.group).toEqual([G]);
    expect(toggleFilter(added, 'group', G).group).toEqual([]);
    expect(noFilters.group).toEqual([]);
  });

  it('remembers the chosen view for the person, and without a choice goes by the width', () => {
    expect(scheduleViewStorageKey('user-1')).toBe('progressive:schedule-view:user-1');
    expect(parseStoredView('cards')).toBe('cards');
    expect(parseStoredView('week')).toBe('week');
    expect(parseStoredView('month')).toBeUndefined();
    expect(parseStoredView(null)).toBeUndefined();
    expect(defaultView(390)).toBe('cards');
    expect(defaultView(767)).toBe('cards');
    expect(defaultView(768)).toBe('week');
    expect(defaultView(1440)).toBe('week');
  });
});

describe('schedule — words and colours', () => {
  it('gives the five colours by the order of the activities, again from the sixth', () => {
    expect([0, 1, 2, 3, 4].map(activityColor)).toEqual(['art', 'science', 'sport', 'music', 'language']);
    expect(activityColor(5)).toBe('art');
    expect(activityColor(7)).toBe('sport');
    expect(activityColor(-1)).toBe('art');
    expect(activityColor(1.5)).toBe('art');
    expect(activityColor(undefined)).toBe('art');
    expect(activityColorToken(3)).toBe('--cat-music');
  });

  it('names who leads, the assistant and who stands in', () => {
    expect(instructorLabel({ profile_id: P, name: 'Мария Колева', role: 'lead' })).toBe('Мария Колева');
    expect(instructorLabel({ profile_id: P, name: 'Иван Петров', role: 'assistant' })).toBe('Иван Петров (помощник)');
    expect(instructorLabel({ profile_id: P, name: 'Деси Маринова', role: 'substitute', replaces: [{ profile_id: 'x', name: 'Ани Петрова' }] }))
      .toBe('Деси Маринова · замества Ани Петрова');
    expect(instructorLabel({ profile_id: P, name: 'Деси Маринова', role: 'substitute', replaces: [] })).toBe('Деси Маринова');
    expect(instructorsText([
      { profile_id: '1', name: 'Мария Колева', role: 'lead' },
      { profile_id: '2', name: 'Иван Петров', role: 'assistant' },
    ])).toBe('Мария Колева, Иван Петров (помощник)');
    expect(instructorsText([])).toBe('');
  });

  it('says the places on a card and in the panel, and who waits in the group', () => {
    expect(placesText({ capacity: 12, taken: 10 }, 'short')).toBe('10/12 места');
    expect(placesText({ capacity: 10, taken: 8 }, 'long')).toBe('8 от 10 места');
    expect(placesText({ capacity: 1, taken: 1 }, 'long')).toBe('1 от 1 място');
    expect(placesText({ capacity: null, taken: 5 }, 'short')).toBe('5 деца');
    expect(placesText({ capacity: null, taken: 1 }, 'long')).toBe('1 дете');
    expect(waitingText(0)).toBeNull();
    expect(waitingText(1)).toBe('1 чака за място в групата');
    expect(waitingText(2)).toBe('2 чакат за място в групата');
  });

  it('says the state in words: "Отменено", "Сега", "По-късно" today, nothing for another day', () => {
    const now = new Date('2026-11-20T16:20:00+02:00');
    expect(sessionMark(at('2026-11-20', '16:00'), now)).toBe('now');
    expect(sessionMark(at('2026-11-20', '16:00', 60, { status: 'cancelled' }), now)).toBe('cancelled');
    expect(sessionMark(at('2026-11-20', '18:00'), now)).toBe('later');
    expect(sessionMark(at('2026-11-20', '14:00'), now)).toBeNull();
    expect(sessionMark(at('2026-11-21', '10:00'), now)).toBeNull();
    expect(sessionMark(at('2026-11-21', '10:00', 60, { moved: true }), now)).toBe('moved');
    // The end is not "Сега" any more.
    expect(sessionMark(at('2026-11-20', '15:20'), now)).toBeNull();
  });

  it('marks the children with words, and the eye leads to the enrollment', () => {
    expect(childMarks({ scope: 'full_period', state: 'active' })).toEqual([]);
    expect(childMarks({ scope: 'trial', state: 'active' })).toEqual(['пробно']);
    expect(childMarks({ scope: 'single', state: 'skipped' })).toEqual(['еднократно', 'пропуснато']);
    expect(childMarks({ scope: 'full_period', state: 'paused' })).toEqual(['пауза']);
    expect(moreChildrenText(4)).toBe('Още 4 деца');
    expect(moreChildrenText(1)).toBe('Още 1 дете');
    expect(childLink(G, 'e-1', true)).toBe(`/dashboard/enrollments?view=groups&group=${G}&enrollment=e-1`);
    expect(childLink(G, 'e-1', false)).toBe(`/dashboard/enrollments?view=groups&group=${G}`);
  });

  it('names the group with its age, and the chosen filters as labels', () => {
    expect(groupTitle({ name: 'Група 2', age_label: '3.–4. клас' })).toBe('Група 2 · 3.–4. клас');
    expect(groupTitle({ name: 'Група 2', age_label: null })).toBe('Група 2');
    const board = {
      activities: [{ id: A, title: 'Шахмат', color: 0 }],
      groups: [{ id: G, name: 'Група 1', activity_id: A, age_label: '1.–2. клас' }],
      locations: [{ id: L, name: 'Двор' }],
      instructors: [{ id: P, name: 'Мария Колева' }],
    };
    expect(filterOptions(board).group).toEqual([{ id: G, label: 'Група 1 · 1.–2. клас', section: 'Шахмат' }]);
    expect(filterChips({ activity: [A], group: [G, B], instructor: [P], location: [L] }, board).map((chip) => chip.label)).toEqual([
      'Дейност: Шахмат', 'Група: Шахмат · Група 1 · 1.–2. клас', 'Група: не е във вашия график', 'Треньор: Мария Колева', 'Място: Двор',
    ]);
  });
});
