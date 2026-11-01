import { expect, type Page } from '@playwright/test';
import type { Sheet, Today } from '../../src/features/attendance/api';
const id = (n: number) => `00000000-0000-4000-8000-${String(n).padStart(12,'0')}`;
export function sheet(): Sheet {
  const child = (n: number, name: string, extra = {}) => ({ enrollment_id: id(n), child_id: id(n + 100), name, class: '3. клас', scope: 'full_period', state: 'active', expected: true, status: null, warned: false, came_while_paused: false, version: 0, marked_by: null, marked_at: null, paused_until: null, skip: null, late_cancelled: false, ...extra });
  return { session: { id: id(10), starts_at: '2026-11-02T15:30:00Z', ends_at:'2026-11-02T16:30:00Z', status:'scheduled',extra:false,moved_from:null,topic:'Мат с топ и цар',not_held:null,group:{id:id(4),name:'Група 2',age_label:'3.–4. клас'},activity:{id:id(3),title:'Шахмат'},location:{id:id(5),name:'Зала „Слънце“'} },
    abilities:{mark:true,reason_required:false,opens_at:'2026-11-02T15:15:00Z',deadline:'2026-11-04T00:00:00+02:00',reasons:false,not_held:false,held:false},
    counts:{expected:3,present:0,absent:1,warned:1,unmarked:2,trial:1,single:0,paused:1,present_paused:0},last_marked_at:null,history:[],
    children:[child(20,'Ана Иванова'),child(21,'Калоян Петков',{status:'absent',warned:true,skip:{by:'parent',at:'2026-11-01T12:00:00Z'}}),child(22,'Елена Тодорова',{scope:'trial'}),child(23,'Николай Савов',{state:'paused',expected:false,paused_until:'2026-11-15'})] };
}
export async function fixture(page: Page, options: { complete?: boolean; late?: boolean; offline?: boolean; offlineFailures?:number; conflict?: boolean; readOnly?: boolean; notHeld?: boolean; office?:boolean; monthOnly?:boolean; forbidden?:boolean; pendingTotal?:number; panelAttendance?:boolean; sheetForbidden?:boolean; scheduleManage?:boolean } = {}) {
  const errors: string[] = []; page.on('pageerror', (e) => errors.push(e.message));
  await page.clock.setFixedTime(new Date('2026-11-02T15:40:00Z'));
  const current = sheet(); if(options.office){current.abilities.not_held=true;current.abilities.reasons=true;} if (options.late) current.abilities.reason_required = true;
  if (options.complete) { current.children.filter((c) => c.expected && !c.warned).forEach((c) => { c.status = 'present'; c.version = 1; }); current.counts = {...current.counts,present:2,unmarked:0}; }
  if (options.readOnly||options.monthOnly) current.abilities.mark=false;
  if (options.notHeld) {current.abilities.not_held=false;current.abilities.held=!!options.office;current.session.not_held={at:'2026-11-02T15:30:00Z'};current.abilities.mark=false;current.children=[];}
  const writes: { key: string; body: string }[] = []; let fails = options.offline ? (options.offlineFailures??2) : 0;
  await page.route('**/backend/**', async (route) => {
    const req = route.request(); const path = new URL(req.url()).pathname; let json: unknown;
    if (path.endsWith('/csrf-cookie')) return route.fulfill({status:204});
    if (path.endsWith('/me')) json = {user:{id:id(1),name:'Иван Петров',email:'teacher@example.test'}};
    else if (path.endsWith('/organizations')) json = {organizations:[{id:id(2),name:'Школа „Звезда“',slug:'demo',status:'active',role:options.office?'organization_owner':'instructor',permissions:options.office?['view','enrollment.view','attendance.view_month']:['view','enrollment.view_own']}]};
    else if (path.endsWith('/context/organization')) json = {access:{role:options.office?'organization_owner':'instructor',scope:options.office?'all':'own',abilities:options.office?['view','enrollment.view','attendance.view_month',...(options.monthOnly?[]:['attendance.mark','attendance.correct_late'])]:['view','enrollment.view_own'],groups:[],activities:[]}};
    else if (path.endsWith('/schedule/substitute-requests')) json = {requests:[],now:'2026-11-02T15:40:00Z'};
    else if (path.endsWith('/me/substitute-requests')) json = {requests:[],standing_in:[],email_confirmed:true,now:'2026-11-02T15:40:00Z'};
    else if (path.endsWith('/me/teaching')) json = {groups:[{group_id:id(4),group_name:'Група 2',activity_id:id(3),activity_title:'Шахмат',label:'Шахмат · Група 2',basis:'assignment',role:'assistant',starts_on:'2026-10-01',ends_on:null,next_session_starts_at:current.session.starts_at}]};
    else if (path.endsWith('/attendance/month')) json={abilities:{children:true,correct_late:!!options.office&&!options.monthOnly},month:'2026-11',group:{id:id(4),label:'Шахмат · Група 2'},groups:[{id:id(4),label:'Шахмат · Група 2'}],sessions:[{id:id(10),starts_at:current.session.starts_at,past:true,status:'scheduled',not_held:!!current.session.not_held,moved:false,extra:false,topic:current.session.topic,present:current.counts.present,expected:3},...(options.office?Array.from({length:8},(_,i)=>({id:id(410+i),starts_at:`2026-11-${String(4+i*3).padStart(2,'0')}T15:30:00Z`,past:false,status:'scheduled',not_held:false,moved:false,extra:i===3,topic:null,present:0,expected:3})):[])],rows:current.children.map((c)=>({child_id:c.child_id,name:c.name,kind:'period',cells:{[id(10)]:{state:current.session.not_held?'not_held':!c.expected?(c.status==='present'?'paused_present':'paused'):c.warned?'warned':c.status??'unmarked',enrollment_id:c.enrollment_id},...Object.fromEntries(options.office?Array.from({length:8},(_,i)=>[id(410+i),{state:'future',enrollment_id:c.enrollment_id}]):[])},present:c.status==='present'?1:0,absent:c.status==='absent'?1:0,percent:c.status==='present'?100:c.status==='absent'?0:null}))};
    else if (path.endsWith('/attendance/pending')) json={total:options.pendingTotal??2,sessions:Array.from({length:Math.min(20,options.pendingTotal??2)},(_,i)=>({id:id(10+i),starts_at:current.session.starts_at,activity:i===0?'Шахмат':'Футбол',group:i===0?'Група 2':'Група 1',teachers:'Иван Петров',unmarked:current.counts.unmarked,late:!!options.late}))};
    else if (path.endsWith('/attendance/today')) json = {now:'2026-11-02T15:40:00Z',pending:[],sessions:[{id:current.session.id,starts_at:current.session.starts_at,ends_at:current.session.ends_at,status:'scheduled',state:'now',activity:'Шахмат',group:'Група 2',age_label:'3.–4. клас',location:'Зала „Слънце“',my_role:'assistant',leads:['Мария Колева'],replaces:[],counts:current.counts,last_marked_at:null,can_mark:true,opens_at:current.abilities.opens_at,deadline:current.abilities.deadline}]} satisfies Today;
    else if (path.endsWith('/changes')) {
      writes.push({key:req.headers()['idempotency-key'],body:req.postData()!});
      if(options.forbidden)return route.fulfill({status:403,json:{message:'Нямате право за промяна.'}});
      if (fails-- > 0) return route.abort('failed');
      const body = req.postDataJSON(); const applied = [];
      if (options.conflict && writes.length === 1) { current.children[0].status='absent'; current.children[0].marked_by='Мария Колева'; current.children[0].version=1; return route.fulfill({status:409,json:{...current,conflicts:[id(20)]}}); }
      if(body.action==='not_held'||body.action==='held'){current.session.not_held=body.action==='not_held'?{at:'2026-11-02T15:40:00Z',by:'Иван Петров',reason:body.reason}:null;current.abilities.mark=body.action==='held';current.abilities.held=body.action==='not_held';current.abilities.not_held=body.action==='held';}
      else if (body.action === 'topic') current.session.topic=body.topic;
      else for (const c of current.children) { const mark = body.marks?.find((m: { enrollment_id:string }) => m.enrollment_id===c.enrollment_id); if (mark || (body.action==='all_present' && c.expected && c.status===null && !c.warned)) { const from=c.status; c.status=mark ? mark.status : 'present'; c.version++; c.warned=c.status!=='present'&&!!c.skip; c.came_while_paused=!c.expected&&c.status==='present'; applied.push({enrollment_id:c.enrollment_id,from,to:c.status,version:c.version}); current.history.unshift({id:id(300+current.history.length),at:'2026-11-02T15:40:00Z',by:'Иван Петров',enrollment_id:c.enrollment_id,from,to:c.status,kind:'mark'}); } }
      current.counts.present=current.children.filter((c)=>c.expected&&c.status==='present').length; current.counts.absent=current.children.filter((c)=>c.expected&&c.status==='absent').length; current.counts.unmarked=current.children.filter((c)=>c.expected&&c.status===null).length;
      json={...current,applied};
    } else if (path.endsWith(`/attendance/sessions/${id(10)}`)) {if(options.sheetForbidden)return route.fulfill({status:403,json:{message:'Нямате достъп.'}});json=current;}
    else if (path.includes('/enrollment/children/')) json={id:id(120),name:'Ана Иванова',class:'3. клас',age:null,groups:[{enrollment_id:id(20),group_id:id(4),activity:'Шахмат',group:'Група 2',version:1,state:'active',paused_until:null,ended_on:null,abilities:{contacts:false,skip:false,pause:false},attendance:{month:'2026-11',present:4,absent:1,percent:80,sessions:[{session_id:id(10),starts_at:'2026-11-02T15:30:00Z',state:'present'},{session_id:id(11),starts_at:'2026-11-01T15:30:00Z',state:'warned'}]}}]};
    else if(path.endsWith('/schedule'))json={sessions:[{...current.session,group_id:id(4),activity_id:id(3),location_id:id(5),moved:false,not_held:!!current.session.not_held,instructors:[],capacity:10,taken:4,waiting:0,attendance:current.session.not_held?null:current.counts}],groups:[{id:id(4),name:'Група 2',activity_id:id(3),age_label:null}],activities:[{id:id(3),title:'Шахмат',color:0}],locations:[{id:id(5),name:'Зала „Слънце“'}],instructors:[],abilities:{[id(4)]:{children:true,manage:false}}};
    else if(path.includes('/schedule/sessions/'))json={session:{...current.session,group_id:id(4),activity_id:id(3),location_id:id(5),moved:false,instructors:[],capacity:10,taken:4,waiting:0,not_held:!!current.session.not_held,attendance:current.session.not_held?null:current.counts,not_held_detail:current.session.not_held,children:current.children.map(c=>({enrollment_id:c.enrollment_id,child_id:c.child_id,name:c.name,class:c.class,state:c.state,scope:c.scope}))},group:current.session.group,activity:current.session.activity,location:current.session.location,abilities:{children:true,manage:!!options.scheduleManage,attendance:options.panelAttendance===false?null:current.abilities.mark?'mark':'view'},actions:[]};
    else if(path.endsWith('/applications'))json={data:[],total:0};
    else { errors.push(`${req.method()} ${path}`); return route.fulfill({json:{}}); }
    return route.fulfill({json});
  });
  return {errors,writes,current};
}
export async function layout(page: Page) {
  await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth - innerWidth)).toBeLessThanOrEqual(1);
  const small = await page.locator('button,a[href],input,textarea').evaluateAll((nodes) => nodes.flatMap((el) => { const r=el.getBoundingClientRect(); return el.getRootNode()===document&&el.checkVisibility()&&!el.closest('[inert]')&&r.width>0&&r.right>0&&r.left<innerWidth&&(r.width<43.5||r.height<43.5) ? [{text:el.getAttribute('aria-label')??el.textContent,width:r.width,height:r.height}] : []; }));
  expect(small).toEqual([]);
}
