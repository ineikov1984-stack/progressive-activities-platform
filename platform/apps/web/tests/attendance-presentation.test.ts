import { expect, it } from 'vitest';
import { deadlineText, historyLines, initialView, clock, childLabel, undoAllowed, attendanceView, panelChildState, saveFeedback, attendanceAction } from '../src/features/attendance/presentation';
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

it('keeps month links explicit and defaults to daily work only for people who may mark',()=>{
 expect(attendanceView('',true)).toBe('pending');expect(attendanceView('',false)).toBe('month');expect(attendanceView('view=month',true)).toBe('month');expect(attendanceView('group=g&month=2026-11',true)).toBe('month');expect(attendanceView('group=g&view=pending',true)).toBe('pending');expect(attendanceView('view=pending',false)).toBe('month');
});

it('distinguishes future, missing, warned, paused and closed sessions in the schedule panel',()=>{
 const session={starts_at:'2026-11-02T15:30:00Z',status:'scheduled',not_held:null} as Sheet['session'];
 const c={...child,expected:true,status:null,warned:false,came_while_paused:false} as AttendanceChild;
 const now=Date.parse('2026-11-02T15:40:00Z');
 expect(panelChildState(session,c,now)).toBe('unmarked');
 expect(panelChildState(session,c,now-3600000)).toBe('future');
 expect(panelChildState(session,{...c,status:'present'},now)).toBe('present');
 expect(panelChildState(session,{...c,status:'absent'},now)).toBe('absent');
 expect(panelChildState(session,{...c,status:'absent',warned:true},now)).toBe('warned');
 expect(panelChildState(session,{...c,expected:false},now)).toBe('paused');
 expect(panelChildState(session,{...c,expected:false,came_while_paused:true,status:'present'},now)).toBe('paused_present');
 expect(panelChildState({...session,status:'cancelled'},c,now)).toBe('cancelled');
 expect(panelChildState({...session,not_held:{at:session.starts_at}},c,now)).toBe('not_held');
});
it('always reports dirty, pending, offline, failed, saved and read-only states truthfully',()=>{
 expect(saveFeedback({pending:0,failed:false,offline:false},true,false)).toBe('Всичко е записано');
 expect(saveFeedback({pending:0,failed:false,offline:false},true,true)).toBe('Има незаписани промени');
 expect(saveFeedback({pending:1,failed:false,offline:false},true,false)).toBe('Записва се…');
 expect(saveFeedback({pending:1,failed:false,offline:true},true,false)).toBe('Няма връзка · има незаписани промени');
 expect(saveFeedback({pending:1,failed:true,offline:false},true,false)).toBe('Не е записано');
 expect(saveFeedback({pending:0,failed:false,offline:false},false,false)).toBe('Само преглед');
 expect(saveFeedback({pending:0,failed:false,offline:false},false,true,true)).toBe('Не е записано');
});

it('uses marking as the primary action only when the session can still be marked',()=>{
 expect(attendanceAction('mark',null)).toEqual({label:'Отбележи присъствия',primary:true});
 expect(attendanceAction('mark',{unmarked:3})).toEqual({label:'Отбележи присъствия',primary:true});
 expect(attendanceAction('mark',{unmarked:0})).toEqual({label:'Прегледай присъствията',primary:false});
 expect(attendanceAction('view',{unmarked:3})).toEqual({label:'Прегледай присъствията',primary:false});
});
