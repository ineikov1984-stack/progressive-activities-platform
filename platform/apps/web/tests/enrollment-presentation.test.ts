import { describe, expect, it } from 'vitest';
import type { ChildRecord, GroupRoster, HistoryItem, RosterEnrollment, StaffGroup } from '../src/features/enrollment/api';
import {
  activitySummary, actorText, addDays, ageText, applicationPlaces, childLine, historyText, ordinalText, queueText, reasonText, recordStatusText, recordTone, needsGroupPlace, waitingQueues, groupFill, groupsByActivity, hasPlaceForWaiting, lastDayOfMonth, matchesGroupFilter, matchesGroupSearch, moveInSummary,
  placesText, priceText, rosterNotes, scheduleText, skippableSessions, sofiaDay, waitingText, weekdayDay,
} from '../src/features/enrollment/presentation';

const enrollment = (patch: Partial<RosterEnrollment> = {}): RosterEnrollment => ({
  id: 'e1', child: { id: 'c1', name: 'Ани Иванова', class_label: '3 клас' }, scope: 'full_period', status: 'active',
  starts_on: '2026-10-20', ends_on: null, version: 1, pauses: [], skipped_sessions: [], moves_to: null, ...patch,
});

describe('enrollment presentation', () => {
  it('describes free places in plain Bulgarian', () => {
    expect(placesText(null)).toBe('Без ограничение на местата');
    expect(placesText(0)).toBe('Няма свободни места');
    expect(placesText(1)).toBe('1 свободно място');
    expect(placesText(4)).toBe('4 свободни места');
  });

  it('shows the price that was agreed when the parent applied', () => {
    expect(priceText({ amount_cents: 4500, currency: 'EUR', unit: 'month' })).toMatch(/45,00\s*€ на месец/);
    expect(priceText({ amount_cents: 0, currency: 'EUR', unit: 'session' })).toBe('Безплатно');
  });

  it('works with calendar days in Sofia', () => {
    // 22:30 UTC on 9 Nov is already 10 Nov in Sofia.
    expect(sofiaDay('2026-11-09T22:30:00Z')).toBe('2026-11-10');
    expect(addDays('2026-10-31', 1)).toBe('2026-11-01');
    expect(addDays('2026-03-29', -1)).toBe('2026-03-28');
    expect(lastDayOfMonth('2026-02-10')).toBe('2026-02-28');
    expect(lastDayOfMonth('2026-11-29')).toBe('2026-11-30');
  });

  it('says in words what is special about a child on the roster', () => {
    const notes = rosterNotes(enrollment({
      status: 'ended', ends_on: '2026-11-30',
      pauses: [{ id: 'p1', starts_on: '2026-10-27', ends_on: '2026-11-02', reason_category: 'health' }, { id: 'p2', starts_on: '2026-11-16', ends_on: '2026-11-20', reason_category: 'holiday' }],
      skipped_sessions: [{ session_id: 's1', starts_at: '2026-11-10T14:00:00Z' }],
    }), '2026-10-28');
    expect(notes.map((note) => note.kind)).toEqual(['paused', 'pause', 'skips', 'ends']);
    expect(notes[0].text).toMatch(/^В пауза до 2 ноември$/);
    expect(notes[3].text).toMatch(/^Последен ден 30 ноември$/);

    const moving = rosterNotes(enrollment({ status: 'ended', ends_on: '2026-11-11', moves_to: { group_id: 'g2', group_name: 'Четвъртък', starts_on: '2026-11-12' } }), '2026-10-28');
    expect(moving[0].text).toMatch(/^Премества се в „Четвъртък“ от 12 ноември$/);
    expect(rosterNotes(enrollment({ starts_on: '2026-11-02' }), '2026-10-28')[0].kind).toBe('starts');
  });

  it('offers only sessions the child attends for skipping', () => {
    const roster = { upcoming_sessions: [
      { id: 's1', starts_at: '2026-11-03T14:00:00Z' }, { id: 's2', starts_at: '2026-11-10T14:00:00Z' },
      { id: 's3', starts_at: '2026-11-17T14:00:00Z' }, { id: 's4', starts_at: '2026-12-01T14:00:00Z' },
    ] } as GroupRoster;
    const ids = skippableSessions(roster, enrollment({
      ends_on: '2026-11-30',
      pauses: [{ id: 'p1', starts_on: '2026-11-09', ends_on: '2026-11-11', reason_category: 'other' }],
      skipped_sessions: [{ session_id: 's3', starts_at: '2026-11-17T14:00:00Z' }],
    })).map((session) => session.id);
    expect(ids).toEqual(['s1']);
  });

  const group = (patch: Partial<StaffGroup> = {}): StaffGroup => ({
    id: 'g1', name: 'Група 1', status: 'published', capacity_max: 12, activity_id: 'a1', activity_title: 'Шахмат',
    activity_category: 'Наука', schedule: null, location_name: 'Зала 2', enrolled_for_period: 2, waitlist_count: 0, ...patch,
  });

  it('writes the weekly days and times of a group', () => {
    expect(scheduleText(null)).toBe('');
    expect(scheduleText({ frequency: 'weekly', slots: [{ weekday: 5, starts_at: '16:00', duration_minutes: 60 }] })).toBe('Петък 16:00–17:00');
    expect(scheduleText({ frequency: 'weekly', slots: [{ weekday: 2, starts_at: '15:30', duration_minutes: 45 }, { weekday: 4, starts_at: '15:30', duration_minutes: 0 }] }))
      .toBe('Вторник 15:30–16:15, четвъртък 15:30');
    expect(scheduleText({ frequency: 'biweekly', slots: [{ weekday: 6, starts_at: '10:00', duration_minutes: 90 }] })).toBe('През седмица, събота 10:00–11:30');
  });

  it('says how full a group is in words, not only with a bar', () => {
    expect(groupFill(group())).toEqual({ text: '2 от 12 места', full: false, percent: 17 });
    expect(groupFill(group({ enrolled_for_period: 12 }))).toEqual({ text: 'Пълна · 12 от 12', full: true, percent: 100 });
    expect(groupFill(group({ capacity_max: null, enrolled_for_period: 1 }))).toEqual({ text: '1 записано · без ограничение', full: false, percent: null });
  });

  it('puts groups under their activity and filters them', () => {
    const groups = [
      group(),
      group({ id: 'g2', name: 'Група 2', enrolled_for_period: 12, waitlist_count: 3 }),
      group({ id: 'g3', name: 'Ателие', activity_id: 'a2', activity_title: 'Рисуване', activity_category: 'Изкуство', capacity_max: 8, enrolled_for_period: 0, location_name: null }),
    ];
    const sections = groupsByActivity(groups);
    expect(sections.map((section) => [section.title, section.groups.map((item) => item.id)])).toEqual([['Шахмат', ['g1', 'g2']], ['Рисуване', ['g3']]]);
    expect(activitySummary(sections[0])).toBe('2 групи · 14 от 24 места · 1 пълна · 3 чакат');
    expect(activitySummary(sections[0], 1)).toBe('показани 1 от 2 групи · 14 от 24 места · 1 пълна · 3 чакат');
    expect(activitySummary(sections[1])).toBe('1 група · 0 от 8 места');
    expect(sections[0].placeForWaiting).toBe(0);
    expect(hasPlaceForWaiting(group({ waitlist_count: 1, enrolled_for_period: 11 }))).toBe(true);
    expect(hasPlaceForWaiting(group({ waitlist_count: 1, enrolled_for_period: 12 }))).toBe(false);
    expect(waitingText(1)).toBe('1 чака');
    expect(waitingText(3)).toBe('3 чакат');
    expect(groups.filter((item) => matchesGroupFilter(item, 'full')).map((item) => item.id)).toEqual(['g2']);
    expect(groups.filter((item) => matchesGroupFilter(item, 'free')).map((item) => item.id)).toEqual(['g1', 'g3']);
    expect(groups.filter((item) => matchesGroupFilter(item, 'waiting')).map((item) => item.id)).toEqual(['g2']);
    expect(groups.filter((item) => matchesGroupSearch(item, 'ШАХ')).map((item) => item.id)).toEqual(['g1', 'g2']);
    expect(groups.filter((item) => matchesGroupSearch(item, 'зала')).map((item) => item.id)).toEqual(['g1', 'g2']);
  });

  it('says how full the group of an application is', () => {
    const base = { id: 'g1', name: 'Група 1' };
    expect(applicationPlaces(base)).toBeUndefined();
    expect(applicationPlaces({ ...base, capacity_max: 12, places_taken: 12 })).toEqual({ text: 'Групата е пълна · 12 от 12', full: true });
    expect(applicationPlaces({ ...base, capacity_max: 12, places_taken: 9 })).toEqual({ text: '3 свободни места', full: false });
    expect(applicationPlaces({ ...base, capacity_max: null, places_taken: 30 })).toEqual({ text: 'Без ограничение на местата', full: false });
    // A trial, one-off or open-day visit is decided by its own session, not by the group count.
    expect(needsGroupPlace({ scope: 'full_period', visit_offer_title: null })).toBe(true);
    expect(needsGroupPlace({ scope: 'trial', visit_offer_title: null })).toBe(false);
    expect(needsGroupPlace({ scope: 'full_period', visit_offer_title: 'Ден на отворените врати' })).toBe(false);
  });

  it('keeps the waiting children of a group together, in the order they applied', () => {
    const app = (id: string, group: string) => ({ id, group: { id: group, name: group } }) as Parameters<typeof waitingQueues>[0][number];
    expect(waitingQueues([app('1', 'a'), app('2', 'b'), app('3', 'a')]).map((queue) => queue.map((item) => item.id))).toEqual([['1', '3'], ['2']]);
  });

  it('tells from when a move into a group is possible', () => {
    expect(moveInSummary([])).toEqual({ text: 'Няма насрочени занимания', possible: false });
    expect(moveInSummary([{ date: '2026-11-05', starts_at: '2026-11-05T14:00:00Z', remaining: 0 }, { date: '2026-11-12', starts_at: '2026-11-12T14:00:00Z', remaining: 0 }]))
      .toEqual({ text: 'Няма място в следващите 2 занимания', possible: false });
    expect(moveInSummary([{ date: '2026-11-05', starts_at: '2026-11-05T14:00:00Z', remaining: 0 }, { date: '2026-11-26', starts_at: '2026-11-26T14:00:00Z', remaining: 1 }]))
      .toEqual({ text: 'Свободно място от 26 ноември', possible: true });
    expect(moveInSummary([{ date: '2026-11-05', starts_at: '2026-11-05T14:00:00Z', remaining: 3 }]))
      .toEqual({ text: 'Има място от следващото занимание · 3 свободни места', possible: true });
    expect(weekdayDay('2026-11-26')).toMatch(/^чт.*26 ноември$/);
  });

  it('describes the child, the queue and the other groups in words', () => {
    expect(ageText(1)).toBe('1 година');
    expect(ageText(8)).toBe('8 години');
    expect(ageText(null)).toBe('');
    expect(childLine({ id: 'c1', name: 'Ани', class_label: '2 клас', school_name: '120 СУ', age: 8 })).toBe('2 клас · 120 СУ · 8 години');
    expect(childLine({ id: 'c1', name: 'Ани', class_label: null })).toBe('');
    expect([1, 2, 3, 7, 8, 11, 12, 21, 22, 100].map(ordinalText)).toEqual(['1-во', '2-ро', '3-то', '7-мо', '8-мо', '11-то', '12-то', '21-во', '22-ро', '100-то']);
    expect(queueText(2)).toBe('Чака място · 2-ро в реда');
    expect(queueText(null)).toBe('Чака място');

    const base = { id: 'r1', scope: 'full_period', activity_title: 'Шах', group_id: 'g1', group_name: 'Група 2', session_starts_at: null };
    const enrolled = (patch: Partial<Extract<ChildRecord, { kind: 'enrollment' }>> = {}): ChildRecord => ({
      ...base, kind: 'enrollment', status: 'active', starts_on: '2026-10-01', ends_on: null, paused_until: null, next_pause: null, moves_to: null, next_session_at: null, ...patch,
    });
    const today = '2026-10-28';
    expect(recordStatusText(enrolled(), today)).toBe('Записано от 1 октомври');
    expect(recordStatusText(enrolled({ starts_on: '2026-11-01' }), today)).toBe('Записано · започва на 1 ноември');
    expect(recordStatusText(enrolled({ paused_until: '2026-11-10' }), today)).toBe('В пауза до 10 ноември');
    expect(recordStatusText(enrolled({ status: 'ended', ends_on: '2026-11-30' }), today)).toBe('Последен ден 30 ноември');
    expect(recordStatusText(enrolled({ status: 'ended', ends_on: '2026-09-30' }), today)).toBe('Приключило на 30 септември');
    expect(recordStatusText(enrolled({ status: 'ended', ends_on: '2026-11-11', moves_to: { group_name: 'Група 3', starts_on: '2026-11-12' } }), today))
      .toBe('Премества се в „Група 3“ от 12 ноември');
    expect(recordStatusText({ ...base, kind: 'application', status: 'waitlisted', submitted_at: '2026-10-20T10:00:00Z', queue_position: 3 }, today)).toBe('Чака място · 3-то в реда');
    expect(recordStatusText({ ...base, kind: 'application', status: 'submitted', submitted_at: '2026-10-20T10:00:00Z', queue_position: null }, today)).toBe('Нова заявка от 20 октомври');
    expect(recordTone(enrolled(), today)).toBe('active');
    expect(recordTone(enrolled({ status: 'ended', ends_on: '2026-11-30' }), today)).toBe('ending');
    expect(recordTone(enrolled({ paused_until: '2026-11-10' }), today)).toBe('paused');
    expect(recordTone(enrolled({ starts_on: '2026-11-01' }), today)).toBe('waiting');
  });

  it('tells the history in words and says who did it', () => {
    const item = (patch: Partial<HistoryItem>): HistoryItem => ({
      id: 'h1', type: 'enrollment.application_submitted', occurred_at: '2026-10-28T10:00:00Z', actor: null, public_reason: null, internal_note: null, details: {}, ...patch,
    });
    expect(historyText(item({ details: { status: 'waitlisted' } }))).toBe('Подадена заявка — групата беше пълна, детето е в чакащите');
    expect(historyText(item({ type: 'enrollment.application_approved', actor: { kind: 'automatic', name: null } }))).toBe('Одобрена автоматично — имаше място');
    expect(historyText(item({ type: 'enrollment.application_approved', actor: { kind: 'team', name: 'Петя' } }))).toBe('Заявката е одобрена');
    expect(historyText(item({ type: 'enrollment.paused', details: { starts_on: '2026-11-03', ends_on: '2026-11-10', reason_category: 'holiday' } })))
      .toBe('Пауза 3 ноември – 10 ноември · Ваканция или пътуване');
    expect(historyText(item({ type: 'enrollment.pause_ended', details: { cancelled: true } }))).toBe('Паузата е отменена');
    expect(historyText(item({ type: 'enrollment.transferred', details: { from_group_name: 'Група 1', effective_date: '2026-11-12' } }))).toBe('Преместено от „Група 1“ от 12 ноември');
    expect(historyText(item({ type: 'enrollment.ended', details: { last_day: '2026-11-30' } }))).toBe('Прекратено — последен ден 30 ноември');
    expect(historyText(item({ type: 'something.new' }))).toBe('Промяна в записването');
    expect(actorText({ kind: 'parent', name: null })).toBe('родителят');
    expect(actorText({ kind: 'automatic', name: null })).toBe('автоматично');
    expect(actorText({ kind: 'team', name: 'Петя Колева' })).toBe('Петя Колева');
    expect(actorText(null)).toBe('');
    expect(reasonText(item({ public_reason: 'Рожден ден', actor: { kind: 'parent', name: null } }))).toBe('Причина от родителя: „Рожден ден“');
    expect(reasonText(item({ public_reason: 'Групата е пълна', actor: { kind: 'team', name: 'Петя' } }))).toBe('Казахме на родителя: „Групата е пълна“');
    expect(reasonText(item({}))).toBe('');
  });
});
