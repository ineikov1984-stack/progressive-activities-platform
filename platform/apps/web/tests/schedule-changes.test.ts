import { describe, expect, it } from 'vitest';
import { canApply, selectSessions, warningKeys } from '../src/features/schedule/changes';
import type { ScheduleSession } from '../src/features/schedule/api';
import type { ChangePreview } from '../src/features/schedule/changes';
import { movedFromLabel } from '../src/features/schedule/presentation';
const now = new Date('2026-11-01T12:00:00Z');
const session = (id: string, status = 'scheduled', starts_at = '2026-11-03T15:00:00Z') => ({ id, status, starts_at, version: 1, taken: 2 } as ScheduleSession);
describe('schedule change selection', () => {
  it('keeps previous weeks, ignores started/cancelled sessions and removes by id', () => {
    const first = selectSessions({}, [session('a')], now).selected;
    const next = selectSessions(first, [session('b'), session('c', 'cancelled'), session('d', 'scheduled', '2026-10-01T12:00:00Z')], now).selected;
    expect(Object.keys(next)).toEqual(['a', 'b']);
    expect(Object.keys(selectSessions(next, [session('a')], now, true).selected)).toEqual(['b']);
  });
  it('refuses the whole selection when it would exceed 300', () => {
    const selected = selectSessions({}, Array.from({ length: 300 }, (_, i) => session(String(i))), now).selected;
    expect(selectSessions(selected, [session('extra')], now)).toEqual({ selected, limit: true });
  });
  it('requires confirmation only for the current warning keys and a nonempty preview', () => {
    const warning = { type: 'instructor', session_id: 'a', other_session_id: 'b', needs_confirmation: true, message: 'Застъпване' };
    const preview = { totals: { sessions: 1 }, warnings: [warning] } as ChangePreview;
    expect(warningKeys([warning])).toEqual([{ type: 'instructor', session_id: 'a', other_session_id: 'b' }]);
    expect(canApply(preview, false)).toBe(false); expect(canApply(preview, true)).toBe(true);
    expect(canApply(undefined, true)).toBe(false);
    expect(canApply({ ...preview, totals: { ...preview.totals, sessions: 0 } }, true)).toBe(false);
  });
});

it('shows the original Sofia time, including winter offset', () => {
  expect(movedFromLabel({ moved_from: '2026-11-18T15:30:00Z' })).toBe('Преместено от сряда, 18 ноември · 17:30');
  expect(movedFromLabel({ moved_from: null })).toBeNull();
});
