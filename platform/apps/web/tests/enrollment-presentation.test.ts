import { describe, expect, it } from 'vitest';
import type { GroupRoster, RosterEnrollment } from '../src/features/enrollment/api';
import { addDays, lastDayOfMonth, placesText, priceText, rosterNotes, skippableSessions, sofiaDay } from '../src/features/enrollment/presentation';

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
});
