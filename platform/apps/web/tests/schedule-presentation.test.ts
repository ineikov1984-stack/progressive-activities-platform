import { describe, expect, it } from 'vitest';
import type { ChildCard, ChildCardGroup, ScheduleSession } from '../src/features/schedule/api';
import {
  activityColor, activityColorToken, addDays, ageText, blockLines, canSkipSession, cardDays, cardGroupState, cardGroupTitle, childCardMeta,
  contactsGroupOf, initials, pauseGroupOf, pauseLink, profileLink, sessionGroupOf, CHILD_SEARCH_DELAY_MS, CHILD_SEARCH_HINT, childChipLabel, childFilterAllowed, childLink,
  childMarks, childOptionLabel, childrenHeading, childResultsText, childSearchText, chooseChild, clock, dayBlockDetail, fillOf, fillText, hasFilters, moveActive,
  NO_CHILDREN_FOUND, openingPoint, dayLayout, dayName, defaultView, emptyText, filterChips,
  filterOptions, groupTitle, instructorLabel, instructorsText, isDay, legend, monthGrid, monthLayout, monthLine, moreChildrenText, NO_COLUMN, noFilters,
  parseScheduleAddress, parseStoredView, periodLabel, periodOf, placesText, scheduleAddress, scheduleNames, scheduleQuery, scheduleViewStorageKey,
  sessionMark, sessionsByDay, sessionsCount, sessionWhen, shortGroupName, sofiaDay, sofiaMinutes, startClock, stepDay, stepsOf, timeRange, toggleFilter,
  viewsFor, waitingText, weekLayout, weekOf,
} from '../src/features/schedule/presentation';

const A = '0a000000-0000-4000-8000-000000000001';
const B = '0a000000-0000-4000-8000-000000000002';
const G = '0b000000-0000-4000-8000-000000000001';
const L = '0c000000-0000-4000-8000-000000000001';
const P = '0d000000-0000-4000-8000-000000000001';
const C = '0e000000-0000-4000-8000-000000000001';

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

  it('shows two sessions at once side by side', () => {
    const two = [0, 1].map(() => at('2026-11-18', '17:00'));
    const blocks = weekLayout(two, days).days[2].blocks;
    expect(blocks).toHaveLength(2);
    expect(blocks.every((block) => block.kind === 'session' && block.lanes === 2)).toBe(true);
  });

  it('with more than two at once shows the first and "+N още" with the rest, never narrower blocks', () => {
    const four = [0, 1, 2, 3].map((index) => at('2026-11-18', index < 3 ? '17:00' : '17:30'));
    const earlier = at('2026-11-18', '15:00');
    const blocks = weekLayout([earlier, ...four], days).days[2].blocks;
    expect(blocks[0]).toMatchObject({ kind: 'session', lanes: 1 });
    const shown = blocks.filter((block) => block.kind === 'session' && block.session.id !== earlier.id);
    expect(shown.map((block) => block.kind === 'session' && [block.session.id, block.lane, block.lanes])).toEqual([[four[0].id, 0, 2]]);
    const more = blocks.find((block) => block.kind === 'more');
    expect(more).toMatchObject({ kind: 'more', lane: 1, lanes: 2, start: 17 * 60, end: 18 * 60 + 30 });
    expect(more?.kind === 'more' && more.sessions.map((session) => session.id)).toEqual([four[1].id, four[2].id, four[3].id]);
    expect(weekLayout(four, days).days[2].blocks.every((block) => block.lanes <= 2)).toBe(true);
    // The old limit of four is still there for whoever asks for it.
    expect(weekLayout(four, days, 4).days[2].blocks.filter((block) => block.kind === 'session')).toHaveLength(4);
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
    expect(address).toEqual({ view: 'week', date: '2026-11-18', by: 'rooms', filters: { activity: [A], group: [G], instructor: [], location: [L], child: null } });
    // An unknown view and a wrong day fall back to the defaults.
    expect(parseScheduleAddress('?view=year&date=2026-02-30')).toEqual({ view: null, date: null, by: 'rooms', filters: noFilters });
    expect(parseScheduleAddress(new URLSearchParams({ view: 'cards' })).view).toBe('cards');
    expect(['month', 'week', 'day', 'cards'].map((view) => parseScheduleAddress(`?view=${view}`).view)).toEqual(['month', 'week', 'day', 'cards']);
    const many = new URLSearchParams();
    for (let index = 0; index < 60; index += 1) many.append('group', `0b000000-0000-4000-8000-${String(index).padStart(12, '0')}`);
    expect(parseScheduleAddress(many).filters.group).toHaveLength(50);
  });

  it('has no filter by teacher in "Моят график", so a colleague\'s link filters nothing unseen', () => {
    expect(parseScheduleAddress(`?instructor=${P}&group=${G}`, { own: true }).filters).toEqual({ ...noFilters, group: [G] });
    expect(parseScheduleAddress(`?instructor=${P}`).filters.instructor).toEqual([P]);
  });

  it('gives "Моят график" the week and the cards only', () => {
    expect(viewsFor(false).map((view) => view.label)).toEqual(['Месец', 'Седмица', 'Ден', 'Карти']);
    expect(viewsFor(true).map((view) => view.label)).toEqual(['Седмица', 'Карти']);
    expect(parseScheduleAddress('?view=month', { own: true }).view).toBeNull();
    expect(parseScheduleAddress('?view=day', { own: true }).view).toBeNull();
    expect(parseScheduleAddress('?view=cards', { own: true }).view).toBe('cards');
  });

  it('keeps the columns of the day in the address, by places unless chosen otherwise', () => {
    expect(parseScheduleAddress('?view=day&by=teachers').by).toBe('teachers');
    expect(parseScheduleAddress('?view=day&by=rooms').by).toBe('rooms');
    expect(parseScheduleAddress('?view=day&by=nope').by).toBe('rooms');
    expect(scheduleAddress({ view: 'day', date: '2026-11-18', by: 'teachers', filters: noFilters })).toBe('?view=day&date=2026-11-18&by=teachers');
    expect(scheduleAddress({ view: 'day', date: '2026-11-18', by: 'rooms', filters: noFilters })).toBe('?view=day&date=2026-11-18');
  });

  it('writes the state back in a stable order, and the address reads the same state again', () => {
    const state = { view: 'cards' as const, date: '2026-11-18', by: 'rooms' as const, filters: { activity: [A, B], group: [], instructor: [P], location: [L], child: null } };
    const search = scheduleAddress(state);
    expect(search).toBe(`?view=cards&date=2026-11-18&activity=${A}&activity=${B}&instructor=${P}&location=${L}`);
    expect(parseScheduleAddress(search)).toEqual(state);
    expect(scheduleAddress({ view: null, date: null, by: 'rooms', filters: noFilters })).toBe('');
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
    expect(parseStoredView('month')).toBe('month');
    expect(parseStoredView('day')).toBe('day');
    // A teacher who chose the month on another profile in this browser gets the default.
    expect(parseStoredView('month', true)).toBeUndefined();
    expect(parseStoredView('year')).toBeUndefined();
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
    expect(filterChips({ activity: [A], group: [G, B], instructor: [P], location: [L], child: null }, board).map((chip) => chip.label)).toEqual([
      'Дейност: Шахмат', 'Група: Шахмат · Група 1 · 1.–2. клас', 'Група: не е във вашия график', 'Треньор: Мария Колева', 'Място: Двор',
    ]);
  });
});

describe('schedule — the filter "Дете"', () => {
  const board = { activities: [], groups: [], locations: [], instructors: [] };

  it('keeps only the child\'s identifier in the address and in the request, never the name', () => {
    const state = { view: 'week' as const, date: '2026-11-18', by: 'rooms' as const, filters: { ...noFilters, group: [G], child: C } };
    const search = scheduleAddress(state);
    expect(search).toBe(`?view=week&date=2026-11-18&group=${G}&child=${C}`);
    expect(parseScheduleAddress(search)).toEqual(state);
    expect(parseScheduleAddress(`?child=${C.toUpperCase()}`).filters.child).toBe(C);
    const query = new URLSearchParams(scheduleQuery(weekOf('2026-11-18'), { ...noFilters, child: C }));
    expect(query.get('child')).toBe(C);
    expect(query.has('child[]')).toBe(false);
    expect(new URLSearchParams(scheduleQuery(weekOf('2026-11-18'), noFilters)).has('child')).toBe(false);
  });

  it('drops a broken child, and one from a link where the person may not filter by a child', () => {
    expect(parseScheduleAddress('?child=Ана Иванова').filters.child).toBeNull();
    expect(parseScheduleAddress(`?child=${C}`, { own: true }).filters.child).toBeNull();
    expect(parseScheduleAddress(`?child=${C}`, { children: false }).filters.child).toBeNull();
    expect(parseScheduleAddress(`?child=${C}`, { children: true }).filters.child).toBe(C);
  });

  it('is one child at a time, counts as a filter and goes with "Изчисти филтрите"', () => {
    const chosen = chooseChild(noFilters, C);
    expect(chosen.child).toBe(C);
    expect(chooseChild(chosen, A).child).toBe(A);
    expect(chooseChild(chosen, null).child).toBeNull();
    expect(noFilters.child).toBeNull();
    expect(hasFilters(chosen)).toBe(true);
    expect(hasFilters(noFilters)).toBe(false);
    expect(emptyText('week', hasFilters(chosen))).toBe('Няма занимания по избраните филтри.');
  });

  it('labels the chosen child with the name from this page\'s memory, else "Избрано дете"', () => {
    expect(filterChips({ ...noFilters, child: C }, board, 'Ана Иванова')).toEqual([{ key: 'child', id: C, label: 'Дете: Ана Иванова' }]);
    expect(filterChips({ ...noFilters, child: C }, board)).toEqual([{ key: 'child', id: C, label: 'Избрано дете' }]);
    expect(childChipLabel('')).toBe('Избрано дете');
  });

  it('is only for who sees the enrollments, by the rights read from the API, and never in "Моят график"', () => {
    const office = { permissions: ['view'], access: { abilities: ['view', 'enrollment.view'] } };
    expect(childFilterAllowed(false, office)).toBe(true);
    expect(childFilterAllowed(true, office)).toBe(false);
    // A teacher sees the children of their groups, but does not search the organization's.
    expect(childFilterAllowed(false, { permissions: ['view', 'enrollment.view_own'], access: { abilities: ['view', 'enrollment.view_own'] } })).toBe(false);
    // The rights read win over the role's; without them, the role's.
    expect(childFilterAllowed(false, { permissions: ['enrollment.view'], access: { abilities: ['view'] } })).toBe(false);
    expect(childFilterAllowed(false, { permissions: ['enrollment.view'] })).toBe(true);
  });

  it('searches from two letters, without the spaces around, up to 80, after about 250 ms', () => {
    expect(childSearchText('')).toBeNull();
    expect(childSearchText(' А ')).toBeNull();
    expect(childSearchText('  Ан ')).toBe('Ан');
    expect(childSearchText('ана иванова')).toBe('ана иванова');
    expect(childSearchText('я'.repeat(90))).toHaveLength(80);
    expect(CHILD_SEARCH_DELAY_MS).toBe(250);
    expect(CHILD_SEARCH_HINT).toBe('Напишете поне 2 букви от името.');
  });

  it('shows the name and the class, and says how many were found or that none were', () => {
    expect(childOptionLabel({ name: 'Ана Иванова', class: '3. клас' })).toBe('Ана Иванова · 3. клас');
    expect(childOptionLabel({ name: 'Ана Иванова', class: null })).toBe('Ана Иванова');
    expect(childResultsText(0)).toBe(NO_CHILDREN_FOUND);
    expect(NO_CHILDREN_FOUND).toBe('Няма намерени деца в групите, които виждате.');
    expect(childResultsText(1)).toBe('Намерено 1 дете');
    expect(childResultsText(5)).toBe('Намерени 5 деца');
  });

  it('moves through the names with the arrows, round at either end', () => {
    expect(moveActive(-1, 3, 'ArrowDown')).toBe(0);
    expect(moveActive(0, 3, 'ArrowDown')).toBe(1);
    expect(moveActive(2, 3, 'ArrowDown')).toBe(0);
    expect(moveActive(-1, 3, 'ArrowUp')).toBe(2);
    expect(moveActive(0, 3, 'ArrowUp')).toBe(2);
    expect(moveActive(2, 3, 'ArrowUp')).toBe(1);
    // A name from an older, longer list.
    expect(moveActive(7, 3, 'ArrowDown')).toBe(0);
    expect(moveActive(7, 3, 'ArrowUp')).toBe(2);
    expect(moveActive(0, 0, 'ArrowDown')).toBe(-1);
  });
});

describe('schedule — periods of the views', () => {
  it('names and asks for the month\'s six weeks, the week, and the day', () => {
    expect(periodOf('month', '2026-11-18')).toMatchObject({ from: '2026-10-26', to: '2026-12-06', label: 'Ноември 2026' });
    expect(periodOf('month', '2026-11-18').days).toHaveLength(42);
    expect(periodOf('week', '2026-11-18')).toMatchObject({ from: '2026-11-16', to: '2026-11-22', label: '16–22 ноември 2026' });
    expect(periodOf('cards', '2026-11-18')).toMatchObject({ from: '2026-11-16', to: '2026-11-22' });
    expect(periodOf('day', '2026-11-18')).toEqual({ from: '2026-11-18', to: '2026-11-18', days: ['2026-11-18'], label: 'Сряда, 18 ноември 2026' });
  });

  it('moves a month, a week or a day with ‹ ›, and says which', () => {
    expect(stepDay('month', '2026-11-18', 1)).toBe('2026-12-01');
    expect(stepDay('month', '2026-12-31', 1)).toBe('2027-01-01');
    expect(stepDay('month', '2026-01-31', -1)).toBe('2025-12-01');
    expect(stepDay('week', '2026-11-18', -1)).toBe('2026-11-09');
    expect(stepDay('cards', '2026-11-18', 1)).toBe('2026-11-23');
    expect(stepDay('day', '2026-10-25', 1)).toBe('2026-10-26');
    expect(stepDay('day', '2026-03-01', -1)).toBe('2026-02-28');
    expect(stepsOf('month')).toEqual({ previous: 'Предишен месец', next: 'Следващ месец' });
    expect(stepsOf('cards')).toEqual({ previous: 'Предишна седмица', next: 'Следваща седмица' });
    expect(stepsOf('day')).toEqual({ previous: 'Предишен ден', next: 'Следващ ден' });
    expect([emptyText('month'), emptyText('week'), emptyText('day'), emptyText('cards')])
      .toEqual(['Няма занимания в този месец.', 'Няма занимания в тази седмица.', 'Няма занимания в този ден.', 'Няма занимания в тази седмица.']);
    expect(emptyText('week', true)).toBe('Няма занимания по избраните филтри.');
    expect(emptyText('month', true)).toBe('Няма занимания по избраните филтри.');
  });
});

describe('schedule — the month', () => {
  it('is six weeks from the Monday of the week with the first day, also when the month starts on a Monday', () => {
    expect(monthGrid('2026-11-30')).toMatchObject({ month: '2026-11', from: '2026-10-26', to: '2026-12-06' });
    // 1 февруари 2027 is a Monday: the grid starts with it.
    expect(monthGrid('2027-02-10')).toMatchObject({ from: '2027-02-01', to: '2027-03-14' });
    const layout = monthLayout([], '2026-11-01');
    expect(layout.weeks).toHaveLength(6);
    expect(layout.weeks.every((week) => week.length === 7)).toBe(true);
    expect(layout.weeks[0].map((day) => [day.number, day.inMonth])).toEqual([[26, false], [27, false], [28, false], [29, false], [30, false], [31, false], [1, true]]);
    expect(layout.weeks[4][6]).toMatchObject({ day: '2026-11-29', inMonth: true });
    expect(layout.weeks[5][1]).toMatchObject({ day: '2026-12-01', inMonth: false });
  });

  it('shows three lines in a day and "+N още" for the rest, in the API\'s order, and leaves out what is outside the grid', () => {
    const five = ['16:00', '16:00', '17:00', '17:30', '18:00'].map((time, index) => at('2026-11-18', time, 60, { activity_id: index === 1 ? B : A }));
    const layout = monthLayout([...five, at('2026-12-07', '16:00'), at('2026-10-26', '16:00')], '2026-11-18');
    const wednesday = layout.weeks[3][2];
    expect(wednesday).toMatchObject({ day: '2026-11-18', more: 2 });
    expect(wednesday.shown.map((session) => session.id)).toEqual(five.slice(0, 3).map((session) => session.id));
    expect(wednesday.sessions).toHaveLength(5);
    // The first day of the grid is from October, still shown; 7 December is outside the six weeks.
    expect(layout.weeks[0][0].sessions).toHaveLength(1);
    expect(layout.weeks.flat().reduce((sum, day) => sum + day.sessions.length, 0)).toBe(6);
  });

  it('puts a session in its day by Sofia at the change of time in October and in March', () => {
    // Half past midnight on 25 October is summer time (UTC+3); on 26 October winter time (UTC+2).
    const october = monthLayout([
      { ...at('2026-10-25', '00:30'), starts_at: '2026-10-24T21:30:00Z', ends_at: '2026-10-24T22:30:00Z' },
      { ...at('2026-10-26', '00:30'), starts_at: '2026-10-25T22:30:00Z', ends_at: '2026-10-25T23:30:00Z' },
    ], '2026-10-01');
    const day = (layout: ReturnType<typeof monthLayout>, value: string) => layout.weeks.flat().find((item) => item.day === value);
    expect(october.weeks[0][0].day).toBe('2026-09-28');
    expect(day(october, '2026-10-24')?.sessions).toHaveLength(0);
    expect(day(october, '2026-10-25')?.sessions).toHaveLength(1);
    expect(day(october, '2026-10-26')?.sessions).toHaveLength(1);
    const march = monthLayout([
      { ...at('2026-03-29', '00:30'), starts_at: '2026-03-28T22:30:00Z', ends_at: '2026-03-28T23:30:00Z' },
      { ...at('2026-03-30', '00:30'), starts_at: '2026-03-29T21:30:00Z', ends_at: '2026-03-29T22:30:00Z' },
    ], '2026-03-15');
    expect(march.weeks[0][0].day).toBe('2026-02-23');
    expect(day(march, '2026-03-28')?.sessions).toHaveLength(0);
    expect(day(march, '2026-03-29')?.sessions).toHaveLength(1);
    expect(day(march, '2026-03-30')?.sessions).toHaveLength(1);
  });

  it('writes a line as "16:00 Шахмат · Гр. 1", and counts the sessions in words', () => {
    const names = scheduleNames({
      activities: [{ id: A, title: 'Шахмат', color: 0 }], groups: [{ id: G, name: 'Група 1', activity_id: A, age_label: null }], locations: [], instructors: [],
    });
    expect(monthLine(at('2026-11-18', '16:00'), names)).toBe('16:00 Шахмат · Гр. 1');
    expect(shortGroupName('Група 12')).toBe('Гр. 12');
    expect(shortGroupName('Начинаещи')).toBe('Начинаещи');
    expect(shortGroupName('Групата на Мими')).toBe('Групата на Мими');
    expect([sessionsCount(0), sessionsCount(1), sessionsCount(3)]).toEqual(['няма занимания', '1 занимание', '3 занимания']);
    expect([sessionsCount(3, 1), sessionsCount(0, 2)]).toEqual(['3 занимания, 1 отменено', 'няма занимания, 2 отменени']);
  });
});

describe('schedule — the day by places and by people', () => {
  const L2 = '0c000000-0000-4000-8000-000000000002';
  const P2 = '0d000000-0000-4000-8000-000000000002';
  const P3 = '0d000000-0000-4000-8000-000000000003';
  const dictionary = {
    locations: [{ id: L2, name: 'Ателие' }, { id: L, name: 'Зала „Слънце“' }],
    instructors: [{ id: P3, name: 'Ани Петрова' }, { id: P2, name: 'Деси Маринова' }, { id: P, name: 'Мария Колева' }],
  };
  const lead = (id: string, name: string) => ({ profile_id: id, name, role: 'lead' });

  it('has a column for each place of the day in the dictionary\'s order, and "Без зала" last', () => {
    const sessions = [
      at('2026-11-18', '17:00', 60, { location_id: L }),
      at('2026-11-18', '16:00', 60, { location_id: null }),
      at('2026-11-18', '17:30', 60, { location_id: L }),
      at('2026-11-18', '18:00', 60, { location_id: L2 }),
      at('2026-11-19', '09:00', 60, { location_id: L2 }),
    ];
    const layout = dayLayout(sessions, '2026-11-18', 'rooms', dictionary);
    expect(layout.columns.map((column) => column.title)).toEqual(['Ателие', 'Зала „Слънце“', 'Без зала']);
    expect(layout.columns[2].id).toBe(NO_COLUMN);
    // Two at the same time in a place stand side by side.
    expect(layout.columns[1].blocks.map((block) => [block.lane, block.lanes])).toEqual([[0, 2], [1, 2]]);
    // The hours of the day only: 9:00 on Thursday does not widen them.
    expect(layout).toMatchObject({ startHour: 15, endHour: 20 });
    expect(dayLayout(sessions, '2026-11-17', 'rooms', dictionary).columns).toEqual([]);
  });

  it('by people has who leads or stands in, a session led by two in both columns, assistants in the block, and "Без преподавател" last', () => {
    const shared = at('2026-11-18', '16:00', 60, { instructors: [lead(P, 'Мария Колева'), lead(P2, 'Деси Маринова')] });
    const assisted = at('2026-11-18', '17:00', 60, { instructors: [lead(P, 'Мария Колева'), { profile_id: P3, name: 'Ани Петрова', role: 'assistant' }] });
    const standIn = at('2026-11-18', '18:00', 60, { instructors: [{ profile_id: P2, name: 'Деси Маринова', role: 'substitute', replaces: [{ profile_id: P3, name: 'Ани Петрова' }] }] });
    const nobody = at('2026-11-18', '19:00', 60, { instructors: [] });
    const layout = dayLayout([shared, assisted, standIn, nobody], '2026-11-18', 'teachers', dictionary);
    expect(layout.columns.map((column) => column.title)).toEqual(['Деси Маринова', 'Мария Колева', 'Без преподавател']);
    const ids = (index: number) => layout.columns[index].blocks.map((block) => block.kind === 'session' && block.session.id);
    expect(ids(0)).toEqual([shared.id, standIn.id]);
    expect(ids(1)).toEqual([shared.id, assisted.id]);
    expect(ids(2)).toEqual([nobody.id]);
  });

  it('says who leads by places, and the place and whom one stands in for by people', () => {
    const names = scheduleNames({ activities: [], groups: [], locations: dictionary.locations, instructors: dictionary.instructors });
    const standIn = at('2026-11-18', '18:00', 60, { location_id: L, instructors: [{ profile_id: P2, name: 'Деси Маринова', role: 'substitute', replaces: [{ profile_id: P3, name: 'Ани Петрова' }] }] });
    expect(dayBlockDetail(standIn, 'rooms', names)).toBe('Деси Маринова · замества Ани Петрова');
    expect(dayBlockDetail(standIn, 'teachers', names)).toBe('Зала „Слънце“ · замества Ани Петрова');
    expect(dayBlockDetail(at('2026-11-18', '18:00'), 'teachers', names)).toBe('Без зала');
    expect(dayBlockDetail(at('2026-11-18', '18:00'), 'rooms', names)).toBe('Без преподавател');
  });
});

describe('schedule — how full, at a glance', () => {
  it('gives the level and the words: empty, a little, almost full, full, without a limit', () => {
    expect(fillOf({ capacity: 10, taken: 0 })).toEqual({ level: 'empty', percent: 0, word: 'Няма записани' });
    expect(fillOf({ capacity: 10, taken: 3 })).toEqual({ level: 'low', percent: 30, word: null });
    expect(fillOf({ capacity: 10, taken: 8 })).toEqual({ level: 'almost', percent: 80, word: null });
    expect(fillOf({ capacity: 10, taken: 10 })).toEqual({ level: 'full', percent: 100, word: 'Пълна' });
    // More than the places (a visit on a full day) is full too, and the bar does not run over.
    expect(fillOf({ capacity: 10, taken: 11 })).toEqual({ level: 'full', percent: 100, word: 'Пълна' });
    expect(fillOf({ capacity: null, taken: 8 })).toEqual({ level: 'none', percent: 0, word: null });
    expect(fillOf({ capacity: null, taken: 0 })).toEqual({ level: 'none', percent: 0, word: 'Няма записани' });
  });

  it('says it in a block, on a card and in the panel', () => {
    expect([fillText({ capacity: 10, taken: 8 }, 'block'), fillText({ capacity: 10, taken: 8 }, 'short'), fillText({ capacity: 10, taken: 8 }, 'long')])
      .toEqual(['8/10', '8/10 места', '8 от 10 места']);
    expect([fillText({ capacity: 10, taken: 10 }, 'block'), fillText({ capacity: 10, taken: 10 }, 'short'), fillText({ capacity: 10, taken: 10 }, 'long')])
      .toEqual(['Пълна', '10/10 места · Пълна', '10 от 10 места · Пълна']);
    expect([fillText({ capacity: 12, taken: 0 }, 'block'), fillText({ capacity: 12, taken: 0 }, 'long')]).toEqual(['Няма записани', '0 от 12 места · Няма записани']);
    expect([fillText({ capacity: null, taken: 8 }, 'block'), fillText({ capacity: null, taken: 1 }, 'long'), fillText({ capacity: null, taken: 0 }, 'short')])
      .toEqual(['8 деца', '1 дете', 'Няма записани']);
  });

  it('heads the children with who comes and who is away, so the places and the names agree', () => {
    const kids = (states: string[]) => states.map((state) => ({ state }));
    expect(childrenHeading(kids(['active', 'active', 'paused', 'skipped', 'active']), false)).toBe('Децата: 3 идват · 2 в пауза или пропускат');
    expect(childrenHeading(kids(['active', 'paused']), false)).toBe('Децата: 1 идва · 1 е в пауза или пропуска');
    expect(childrenHeading(kids(['active', 'active']), false)).toBe('Децата: 2 идват');
    expect(childrenHeading(kids(['active', 'paused']), true)).toBe('Децата, които щяха да дойдат: 2');
  });
});

describe('schedule — after the UX review', () => {
  const days = weekOf('2026-11-18').days;
  const names = scheduleNames({
    activities: [{ id: A, title: 'Шахмат', color: 0 }], groups: [{ id: G, name: 'Група 1', activity_id: A, age_label: null }],
    locations: [{ id: L, name: 'Двор' }], instructors: [],
  });

  it('counts the sessions held in a day of the month apart from the cancelled ones', () => {
    const layout = monthLayout([at('2026-11-18', '16:00'), at('2026-11-18', '17:00', 60, { status: 'cancelled' }), at('2026-11-18', '18:00')], '2026-11-18');
    expect(layout.weeks[3][2]).toMatchObject({ held: 2, cancelled: 1 });
    expect(layout.weeks[3][3]).toMatchObject({ held: 0, cancelled: 0 });
  });

  it('puts Monday to Friday in the cards always, Saturday and Sunday only with sessions', () => {
    const saturday = at('2026-11-21', '10:00');
    expect(cardDays([at('2026-11-18', '17:00'), saturday], days).map((item) => [item.day, item.sessions.length, item.weekday])).toEqual([
      ['2026-11-16', 0, true], ['2026-11-17', 0, true], ['2026-11-18', 1, true], ['2026-11-19', 0, true], ['2026-11-20', 0, true], ['2026-11-21', 1, false],
    ]);
  });

  it('writes a block line by line, the state next to the time, how full in words where needed', () => {
    const now = new Date('2026-11-20T16:20:00+02:00');
    const going = at('2026-11-20', '16:00', 60, { location_id: L, capacity: 10, taken: 10 });
    expect(blockLines(going, 'week', 'rooms', names, now)).toEqual({
      time: '16:00', mark: 'now', lines: ['Шахмат', 'Пълна', 'Група 1', 'Двор'],
      label: '16:00–17:00, Шахмат, Група 1, Двор, 10 от 10 места · Пълна, Сега',
    });
    const day = blockLines(at('2026-11-20', '18:00', 60, { capacity: 10, taken: 8 }), 'day', 'rooms', names, now);
    expect(day).toMatchObject({ time: '18:00–19:00', mark: null, lines: ['Шахмат · Група 1', '8/10', 'Без преподавател'] });
    const cancelled = blockLines(at('2026-11-19', '18:00', 60, { status: 'cancelled' }), 'week', 'rooms', names, now);
    expect(cancelled).toMatchObject({ mark: 'cancelled', lines: ['Шахмат', 'Група 1'] });
    expect(cancelled.label.endsWith('Отменено')).toBe(true);
  });

  it('opens the week at today\'s column and the session going on now, else the first one today, else the first of the week', () => {
    const now = new Date('2026-11-20T16:20:00+02:00');
    const week = (sessions: ScheduleSession[]) => weekLayout(sessions, days).days.map((column) => ({ blocks: column.blocks, today: column.day === '2026-11-20' }));
    expect(openingPoint('week', week([at('2026-11-21', '09:00'), at('2026-11-20', '16:00'), at('2026-11-20', '18:00')]), now)).toEqual({ column: 4, minute: 16 * 60 });
    expect(openingPoint('week', week([at('2026-11-21', '09:00'), at('2026-11-20', '18:00')]), now)).toEqual({ column: 4, minute: 18 * 60 });
    // Nothing today: today's column still, at the week's first session.
    expect(openingPoint('week', week([at('2026-11-18', '15:30'), at('2026-11-21', '09:00')]), now)).toEqual({ column: 4, minute: 9 * 60 });
    // Another week: its first session.
    const other = weekLayout([at('2026-11-25', '17:00'), at('2026-11-24', '16:00')], weekOf('2026-11-25').days).days.map((column) => ({ blocks: column.blocks }));
    expect(openingPoint('week', other, now)).toEqual({ column: 1, minute: 16 * 60 });
    expect(openingPoint('week', weekLayout([], weekOf('2026-11-25').days).days.map((column) => ({ blocks: column.blocks })), now)).toBeNull();
  });

  it('opens the day at the place of the session going on now, else at the first one', () => {
    const now = new Date('2026-11-20T16:20:00+02:00');
    const columns = (sessions: ScheduleSession[], today: boolean) => dayLayout(sessions, '2026-11-20', 'rooms', { locations: [{ id: L, name: 'Двор' }], instructors: [] }).columns
      .map((column) => ({ blocks: column.blocks, today }));
    const sessions = [at('2026-11-20', '15:00', 60, { location_id: L }), at('2026-11-20', '16:00', 60, { location_id: null })];
    expect(openingPoint('day', columns(sessions, true), now)).toEqual({ column: 1, minute: 16 * 60 });
    expect(openingPoint('day', columns(sessions, false), now)).toEqual({ column: 0, minute: 15 * 60 });
  });
});

describe('schedule — the card of a child', () => {
  const group = (extra: Partial<ChildCardGroup>): ChildCardGroup => ({
    enrollment_id: 'e-1', group_id: G, activity: 'Шах', group: 'Вторник', version: 3, state: 'active', paused_until: null, ended_on: null,
    abilities: { contacts: false, skip: false, pause: false }, ...extra,
  });
  const everything = { contacts: true, skip: true, pause: true };
  const now = new Date('2026-11-20T16:20:00+02:00');
  const ahead = { starts_at: '2026-11-20T16:00:00Z', status: 'scheduled', group_id: G };

  it('says the class and the age, and the initials for the circle', () => {
    expect(childCardMeta({ class: '3. клас', age: 9 })).toBe('3. клас · 9 години');
    expect(childCardMeta({ class: null, age: 1 })).toBe('1 година');
    expect(childCardMeta({ class: '1. клас', age: null })).toBe('1. клас');
    expect(childCardMeta({ class: null, age: null })).toBe('');
    expect(ageText(0)).toBe('0 години');
    expect(ageText(-1)).toBeNull();
    expect(ageText(2.5)).toBeNull();
    expect(initials('Ана Иванова')).toBe('АИ');
    expect(initials('  ана  мария иванова ')).toBe('АМ');
    expect(initials('Ана')).toBe('А');
  });

  it('names each group and says its state in words', () => {
    expect(cardGroupTitle(group({}))).toBe('Шах · Вторник');
    expect(cardGroupTitle(group({ activity: null, group: null }))).toBe('Група');
    expect(cardGroupState(group({}))).toEqual({ text: 'Записано', tone: 'active' });
    expect(cardGroupState(group({ state: 'paused', paused_until: '2026-11-30' }))).toEqual({ text: 'Пауза до 30 ноември', tone: 'paused' });
    expect(cardGroupState(group({ state: 'paused' }))).toEqual({ text: 'Пауза', tone: 'paused' });
    expect(cardGroupState(group({ state: 'trial' }))).toEqual({ text: 'Пробно', tone: 'visit' });
    expect(cardGroupState(group({ state: 'single' }))).toEqual({ text: 'Еднократно', tone: 'visit' });
    expect(cardGroupState(group({ state: 'ended', ended_on: '2026-10-15' }))).toEqual({ text: 'Приключи на 15 октомври', tone: 'ended' });
  });

  it('finds the session\'s group by the enrollment, else by the group', () => {
    const card: Pick<ChildCard, 'groups'> = { groups: [group({ enrollment_id: 'e-2', group_id: 'g-2' }), group({ enrollment_id: 'e-1' })] };
    expect(sessionGroupOf(card, { session: ahead, child: { enrollment_id: 'e-1', state: 'active' } })?.enrollment_id).toBe('e-1');
    expect(sessionGroupOf(card, { session: { ...ahead, group_id: 'g-2' }, child: { enrollment_id: 'e-9', state: 'active' } })?.enrollment_id).toBe('e-2');
    expect(sessionGroupOf(card, { session: { ...ahead, group_id: 'g-9' }, child: { enrollment_id: 'e-9', state: 'active' } })).toBeUndefined();
  });

  it('offers "Пропусни" only with the right, for a session ahead that the child comes to', () => {
    const context = { session: ahead, child: { enrollment_id: 'e-1', state: 'active' } };
    // 16:00 UTC is 18:00 in Sofia: ahead of 16:20.
    expect(canSkipSession({ groups: [group({ abilities: everything })] }, context, now)).toBe(true);
    expect(canSkipSession({ groups: [group({})] }, context, now)).toBe(false);
    expect(canSkipSession({ groups: [group({ abilities: everything })] }, { ...context, session: { ...ahead, starts_at: '2026-11-20T14:00:00Z' } }, now)).toBe(false);
    expect(canSkipSession({ groups: [group({ abilities: everything })] }, { ...context, session: { ...ahead, status: 'cancelled' } }, now)).toBe(false);
    expect(canSkipSession({ groups: [group({ abilities: everything })] }, { ...context, child: { enrollment_id: 'e-1', state: 'skipped' } }, now)).toBe(false);
    expect(canSkipSession({ groups: [group({ abilities: everything })] }, { ...context, child: { enrollment_id: 'e-1', state: 'paused' } }, now)).toBe(false);
    expect(canSkipSession({ groups: [group({ abilities: everything, state: 'ended' })] }, context, now)).toBe(false);
    expect(canSkipSession({ groups: [] }, context, now)).toBe(false);
  });

  it('leads "Пауза" to an enrollment for the period that runs, the session\'s first', () => {
    const context = { session: ahead, child: { enrollment_id: 'e-1', state: 'active' } };
    const other = group({ enrollment_id: 'e-2', group_id: 'g-2', abilities: everything });
    expect(pauseGroupOf({ groups: [other, group({ abilities: everything })] }, context)?.enrollment_id).toBe('e-1');
    expect(pauseGroupOf({ groups: [other, group({ abilities: everything, state: 'trial' })] }, context)?.enrollment_id).toBe('e-2');
    expect(pauseGroupOf({ groups: [group({ abilities: everything, state: 'paused' })] }, context)?.enrollment_id).toBe('e-1');
    expect(pauseGroupOf({ groups: [group({ abilities: everything, state: 'ended' })] }, context)).toBeUndefined();
    expect(pauseGroupOf({ groups: [group({})] }, context)).toBeUndefined();
    expect(pauseLink(group({}))).toBe(`/dashboard/enrollments?view=groups&group=${G}&enrollment=e-1`);
  });

  it('shows the phone through a group where the person sees contacts, and the profile only to who sees the enrollments', () => {
    const context = { session: ahead, child: { enrollment_id: 'e-1', state: 'active' } };
    const seen = group({ enrollment_id: 'e-2', group_id: 'g-2', abilities: { contacts: true, skip: false, pause: false } });
    expect(contactsGroupOf({ groups: [group({}), seen] }, context)?.enrollment_id).toBe('e-2');
    expect(contactsGroupOf({ groups: [group({ abilities: everything }), seen] }, context)?.enrollment_id).toBe('e-1');
    expect(contactsGroupOf({ groups: [group({})] }, context)).toBeUndefined();
    expect(profileLink({ groups: [seen, group({})] }, context, true)).toBe(`/dashboard/enrollments?view=groups&group=${G}&enrollment=e-1`);
    expect(profileLink({ groups: [group({})] }, context, false)).toBeNull();
    expect(profileLink({ groups: [] }, context, true)).toBeNull();
  });
});

it('not-held overrides current and moved states and removes capacity from block descriptions',()=>{
 const session=at('2026-11-18','17:30',60,{not_held:true,moved:true});const now=new Date('2026-11-18T17:40:00+02:00');expect(sessionMark(session,now)).toBe('not_held');expect(sessionMark({...session,status:'cancelled'},now)).toBe('cancelled');expect(monthLayout([session],'2026-11-18').weeks.flat().find(d=>d.day==='2026-11-18')).toMatchObject({held:0,notHeld:1,cancelled:0});
 const names=scheduleNames({activities:[{id:A,title:'Шахмат',color:0}],groups:[{id:G,name:'Група 2',activity_id:A,age_label:null}],locations:[],instructors:[]});const words=blockLines(session,'week','rooms',names,now);expect(words.label).toContain('Не се проведе');expect(words.label).not.toContain('8 от 10');
});
