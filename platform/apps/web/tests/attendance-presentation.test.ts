import { expect, it } from 'vitest';
import { deadlineText, historyLines, initialView, clock, childLabel, undoAllowed } from '../src/features/attendance/presentation';
import type { AttendanceChild, History, Sheet } from '../src/features/attendance/api';
const child = { enrollment_id: 'e', name: 'Ана Иванова' } as AttendanceChild;
const row = (id: string, at: string, from: History['from'], to: History['to'], extra: Partial<History> = {}): History => ({ id, at, from, to, by: 'Иван Петров', kind: 'mark', enrollment_id: 'e', ...extra });
it('uses Sofia times, calendar deadlines and initial mode including late corrections', () => {
  expect(clock('2026-11-03T15:00:00Z')).toBe('17:00'); expect(deadlineText('2026-11-05T00:00:00+02:00')).toContain('4 ноември');
  expect(initialView({ abilities: { mark: true, reason_required: false }, counts: { unmarked: 1 } } as Sheet)).toBe('mark');
  expect(initialView({ abilities: { mark: true, reason_required: true }, counts: { unmarked: 1 } } as Sheet)).toBe('view');
});
it('hides a change and its undo only for the same child, actor and 60 second window', () => {
  expect(historyLines([row('a','2026-11-03T15:00:00Z',null,'present'), row('b','2026-11-03T15:00:59Z','present',null)], [child])).toEqual([]);
  expect(historyLines([row('a','2026-11-03T15:00:00Z',null,'present'), row('b','2026-11-03T15:01:01Z','present',null)], [child])).toHaveLength(2);
  expect(historyLines([row('a','2026-11-03T15:00:00Z',null,'present'), row('b','2026-11-03T15:00:01Z','present',null,{by:'Мария'})], [child])).toHaveLength(2);
});
it('groups consecutive first marks, retains late reasons and shows newest lines first', () => {
  const lines = historyLines([row('a','2026-11-03T15:00:00Z',null,'present'), row('b','2026-11-03T15:00:01Z',null,'absent',{enrollment_id:'other'}), row('c','2026-11-03T16:00:00Z','absent','present',{reason:'Поправка'})], [child]);
  expect(lines).toHaveLength(2); expect(lines[0].text).toContain('Причина: Поправка'); expect(lines[1].text).toContain('1 присъстват, 1 отсъстват');
  expect(historyLines([{id:'x',at:'2026-11-03T17:00:00Z',kind:'not_held',by:'Мария',reason:'Зала'}],[])[0].text).toContain('Не се проведе');
});
it('keeps paused presence and warned absence distinct from ordinary absence', () => {
  expect(childLabel({...child, expected:false, came_while_paused:true})).toContain('дошло по време на пауза'); expect(childLabel({...child, expected:true, warned:true})).toBe('Предупредено отсъствие');
});

it('allows undo to a previous status after the deadline but never clearing a late first mark',()=>{
 const applied={enrollment_id:'e',from:null,to:'present' as const,version:1}; expect(undoAllowed([applied],false)).toBe(true); expect(undoAllowed([applied],true)).toBe(false); expect(undoAllowed([{...applied,from:'absent'}],true)).toBe(true);
});
