import { describe, expect, it } from 'vitest';
import { canRequest, isOffice, requestStatus, selection, substituteGroupHref, when } from '../src/features/substitute/presentation';
import { myGroups } from '../src/features/me/presentation';
import { teachingCard } from '../src/features/dashboard/tasks';
import type { DashboardOrganization, TeachingGroup } from '../src/features/dashboard/api';
import type { OptionSession, RequestSession } from '../src/features/substitute/api';
const org: DashboardOrganization = {id:'org',name:'Школа',slug:'demo',role:'instructor',status:'active',permissions:[],access:{role:'instructor',scope:'own',abilities:[],groups:[],activities:[]}};
const group: TeachingGroup = {group_id:'g',group_name:'Група 2',activity_id:'a',activity_title:'Шахмат',label:'Шахмат',basis:'substitute',role:'lead',starts_on:'2026-11-02',ends_on:null,next_session_starts_at:'2026-11-01T23:30:00Z'};
const session: RequestSession = {session_id:'s',starts_at:'2026-11-02T14:00:00Z',ends_at:'2026-11-02T15:00:00Z',activity:'Шахмат',group:'Група 1',age_label:null,location:null,state:'assigned',closed_reason:null,substitute:{profile_id:'old',name:'Стар колега'},decided_by:{id:'u',name:'Елена Димитрова'},decided_at:null,now:{teaching:false,substitute:{profile_id:'new',name:'Мария Колева'}}};
describe('teacher substitute presentation', () => {
 it('uses the current substitute, never the historical decision', () => {expect(requestStatus(session)).toMatchObject({label:'✓ Има заместник',text:'Мария Колева ще ви замести',decision:'Назначи Елена Димитрова'});});
 it('shows a returned teacher truthfully', () => {expect(requestStatus({...session,now:{teaching:true,substitute:null}})).toMatchObject({text:'Заниманието отново е ваше.'});});
 it('shows release without inventing a remaining teacher', () => {expect(requestStatus({...session,now:{teaching:false,substitute:null}})).toMatchObject({label:'✓ Решено',text:'Не е нужно да идвате',decision:'Реши Елена Димитрова'});});
 it('keeps an open request the teacher’s responsibility', () => {expect(requestStatus({...session,state:'open'})).toMatchObject({label:'Чака решение',text:'Докато няма заместник, заниманието е ваше.'});});
 it('names a declined decision', () => {expect(requestStatus({...session,state:'declined'})).toMatchObject({label:'Не е одобрена',text:'Заниманието остава ваше.',decision:'Отказа Елена Димитрова'});});
 it.each([['moved','преместено'],['cancelled','отменено'],['started','мина без заместник'],['not_teaching','Вече не водите'],['archived','архива']])('explains %s expiry', (closed_reason,text) => {expect(requestStatus({...session,state:'expired',closed_reason}).text).toContain(text);});
 it('starts all available sessions checked, excludes already requested', () => {expect(selection([{id:'a',requested:false},{id:'b',requested:true},{id:'c',requested:false}] as OptionSession[])).toEqual(['a','c']);});
 it('preserves choices after conflict and drops gone or now-requested sessions', () => {expect(selection([{id:'a',requested:true},{id:'b',requested:false},{id:'new',requested:false}] as OptionSession[],['a','b','gone'])).toEqual(['b']);});
 it('preselects the session from the schedule', () => {expect(selection([{id:'a',requested:false},{id:'b',requested:false}] as OptionSession[],undefined,'b')).toEqual(['b']);});
 it('requires both rights and the whole organization for the office', () => {
   expect(isOffice({...org,access:{...org.access!,scope:'all',abilities:['schedule.manage','team.assign']}})).toBe(true);
   expect(isOffice({...org,access:{...org.access!,scope:'groups',abilities:['schedule.manage','team.assign']}})).toBe(false);
   expect(isOffice({...org,access:{...org.access!,scope:'all',abilities:['team.assign']}})).toBe(false);
   expect(isOffice({...org,role:'platform_admin',access:{...org.access!,role:'platform_admin',scope:'all',abilities:['schedule.manage','team.assign']}})).toBe(false);
 });
 it('offers a request only to a teaching team member outside the office', () => {
   expect(canRequest(org,[group])).toBe(true);expect(canRequest(org,[])).toBe(false);
   expect(canRequest({...org,access:{...org.access!,scope:'all',abilities:['schedule.manage','team.assign']}},[group])).toBe(false);
   expect(canRequest({...org,role:'platform_admin',access:undefined},[group])).toBe(false);
 });
 it('opens a substituted group in the schedule on its Sofia day in both entry points', () => {const href='/dashboard/schedule?view=week&date=2026-11-02';expect(substituteGroupHref(group)).toBe(href);expect(myGroups([group])[0].groups[0].href).toBe(href);expect(teachingCard(group).href).toBe(href);});
 it.each([['2026-10-25T00:30:00Z','03:30'],['2026-10-25T01:30:00Z','03:30'],['2026-03-29T01:30:00Z','04:30']])('uses Sofia at DST boundary %s', (starts_at,clock) => {expect(when({starts_at,ends_at:starts_at})).toContain(clock);});
});
