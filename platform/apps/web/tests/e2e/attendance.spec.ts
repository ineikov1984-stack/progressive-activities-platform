import { expect, test, type Page } from '@playwright/test';
import type { Sheet, Today } from '../../src/features/attendance/api';
const id = (n: number) => `00000000-0000-4000-8000-${String(n).padStart(12,'0')}`;
function sheet(): Sheet {
  const child = (n: number, name: string, extra = {}) => ({ enrollment_id: id(n), child_id: id(n + 100), name, class: '3. клас', scope: 'full_period', state: 'active', expected: true, status: null, warned: false, came_while_paused: false, version: 0, marked_by: null, marked_at: null, paused_until: null, skip: null, late_cancelled: false, ...extra });
  return { session: { id: id(10), starts_at: '2026-11-02T15:30:00Z', ends_at:'2026-11-02T16:30:00Z', status:'scheduled',extra:false,moved_from:null,topic:'Мат с топ и цар',not_held:null,group:{id:id(4),name:'Група 2',age_label:'3.–4. клас'},activity:{id:id(3),title:'Шахмат'},location:{id:id(5),name:'Зала „Слънце“'} },
    abilities:{mark:true,reason_required:false,opens_at:'2026-11-02T15:15:00Z',deadline:'2026-11-04T00:00:00+02:00',reasons:false,not_held:false,held:false},
    counts:{expected:3,present:0,absent:1,warned:1,unmarked:2,trial:1,single:0,paused:1,present_paused:0},last_marked_at:null,history:[],
    children:[child(20,'Ана Иванова'),child(21,'Калоян Петков',{status:'absent',warned:true,skip:{by:'parent',at:'2026-11-01T12:00:00Z'}}),child(22,'Елена Тодорова',{scope:'trial'}),child(23,'Николай Савов',{state:'paused',expected:false,paused_until:'2026-11-15'})] };
}
async function fixture(page: Page, options: { complete?: boolean; late?: boolean; offline?: boolean; conflict?: boolean; readOnly?: boolean; notHeld?: boolean } = {}) {
  const errors: string[] = []; page.on('pageerror', (e) => errors.push(e.message));
  await page.clock.setFixedTime(new Date('2026-11-02T15:40:00Z'));
  const current = sheet(); if (options.late) current.abilities.reason_required = true;
  if (options.complete) { current.children.filter((c) => c.expected && !c.warned).forEach((c) => { c.status = 'present'; c.version = 1; }); current.counts = {...current.counts,present:2,unmarked:0}; }
  if (options.readOnly) current.abilities.mark=false;
  if (options.notHeld) {current.session.not_held={at:'2026-11-02T15:30:00Z'};current.abilities.mark=false;current.children=[];}
  const writes: { key: string; body: string }[] = []; let fails = options.offline ? 2 : 0;
  await page.route('**/backend/**', async (route) => {
    const req = route.request(); const path = new URL(req.url()).pathname; let json: unknown;
    if (path.endsWith('/csrf-cookie')) return route.fulfill({status:204});
    if (path.endsWith('/me')) json = {user:{id:id(1),name:'Иван Петров',email:'teacher@example.test'}};
    else if (path.endsWith('/organizations')) json = {organizations:[{id:id(2),name:'Школа „Звезда“',slug:'demo',status:'active',role:'instructor',permissions:['view','enrollment.view_own']}]};
    else if (path.endsWith('/context/organization')) json = {access:{role:'instructor',scope:'own',abilities:['view','enrollment.view_own'],groups:[],activities:[]}};
    else if (path.endsWith('/me/teaching')) json = {groups:[{group_id:id(4),group_name:'Група 2',activity_id:id(3),activity_title:'Шахмат',label:'Шахмат · Група 2',basis:'assignment',role:'assistant',starts_on:'2026-10-01',ends_on:null,next_session_starts_at:current.session.starts_at}]};
    else if (path.endsWith('/attendance/month')) json={month:'2026-11',group:{id:id(4),label:'Шахмат · Група 2'},groups:[{id:id(4),label:'Шахмат · Група 2'}],sessions:[{id:id(10),starts_at:current.session.starts_at,extra:false,topic:current.session.topic,present:current.counts.present,expected:3}],rows:current.children.map((c)=>({child_id:c.child_id,name:c.name,kind:'period',cells:{[id(10)]:{state:c.status??'unmarked',enrollment_id:c.enrollment_id}},present:c.status==='present'?1:0,absent:c.status==='absent'?1:0,percent:null}))};
    else if (path.endsWith('/attendance/today')) json = {now:'2026-11-02T15:40:00Z',pending:[],sessions:[{id:current.session.id,starts_at:current.session.starts_at,ends_at:current.session.ends_at,status:'scheduled',state:'now',activity:'Шахмат',group:'Група 2',age_label:'3.–4. клас',location:'Зала „Слънце“',my_role:'assistant',leads:['Мария Колева'],replaces:[],counts:current.counts,last_marked_at:null,can_mark:true,opens_at:current.abilities.opens_at,deadline:current.abilities.deadline}]} satisfies Today;
    else if (path.endsWith('/changes')) {
      writes.push({key:req.headers()['idempotency-key'],body:req.postData()!});
      if (fails-- > 0) return route.abort('failed');
      const body = req.postDataJSON(); const applied = [];
      if (options.conflict && writes.length === 1) { current.children[0].status='absent'; current.children[0].marked_by='Мария Колева'; current.children[0].version=1; return route.fulfill({status:409,json:{...current,conflicts:[id(20)]}}); }
      if (body.action === 'topic') current.session.topic=body.topic;
      else for (const c of current.children) { const mark = body.marks?.find((m: { enrollment_id:string }) => m.enrollment_id===c.enrollment_id); if (mark || (body.action==='all_present' && c.expected && c.status===null && !c.warned)) { const from=c.status; c.status=mark ? mark.status : 'present'; c.version++; c.warned=c.status!=='present'&&!!c.skip; c.came_while_paused=!c.expected&&c.status==='present'; applied.push({enrollment_id:c.enrollment_id,from,to:c.status,version:c.version}); current.history.unshift({id:id(300+current.history.length),at:'2026-11-02T15:40:00Z',by:'Иван Петров',enrollment_id:c.enrollment_id,from,to:c.status,kind:'mark'}); } }
      current.counts.present=current.children.filter((c)=>c.expected&&c.status==='present').length; current.counts.absent=current.children.filter((c)=>c.expected&&c.status==='absent').length; current.counts.unmarked=current.children.filter((c)=>c.expected&&c.status===null).length;
      json={...current,applied};
    } else if (path.endsWith(`/attendance/sessions/${id(10)}`)) json=current;
    else if (path.includes('/enrollment/children/')) json={id:id(120),name:'Ана Иванова',class:'3. клас',age:null,groups:[{enrollment_id:id(20),group_id:id(4),activity:'Шахмат',group:'Група 2',version:1,state:'active',paused_until:null,ended_on:null,abilities:{contacts:false,skip:false,pause:false},attendance:{month:'2026-11',present:4,absent:1,percent:80,sessions:[{session_id:id(10),starts_at:'2026-11-02T15:30:00Z',state:'present'},{session_id:id(11),starts_at:'2026-11-01T15:30:00Z',state:'warned'}]}}]};
    else { errors.push(`${req.method()} ${path}`); return route.fulfill({json:{}}); }
    return route.fulfill({json});
  });
  return {errors,writes,current};
}
async function layout(page: Page) {
  await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth - innerWidth)).toBeLessThanOrEqual(1);
  const small = await page.locator('button,a[href],input,textarea').evaluateAll((nodes) => nodes.flatMap((el) => { const r=el.getBoundingClientRect(); return el.getRootNode()===document&&el.checkVisibility()&&!el.closest('[inert]')&&r.width>0&&r.right>0&&r.left<innerWidth&&(r.width<43.5||r.height<43.5) ? [{text:el.getAttribute('aria-label')??el.textContent,width:r.width,height:r.height}] : []; }));
  expect(small).toEqual([]);
}
for (const width of [390,768,1440]) {
  test(`teacher attendance and child sheet at ${width}px`, async ({page}) => {
    await page.setViewportSize({width,height:1000}); const f=await fixture(page);
    await page.goto('/dashboard'); await expect(page.getByRole('heading',{name:'Днес',exact:true})).toBeVisible(); await layout(page);
    if (width!==768) await page.screenshot({path:test.info().outputPath(`today-${width}.png`),fullPage:true});
    await page.getByRole('link',{name:'Отбележи присъствията',exact:true}).click(); await expect(page.getByRole('heading',{name:'Не се очакват днес'})).toBeVisible(); await layout(page);
    if (width!==768) await page.screenshot({path:test.info().outputPath(`mark-${width}.png`),fullPage:true});
    const eye=page.getByRole('button',{name:'Виж Ана Иванова',exact:true}); await eye.click(); await expect(page.getByRole('dialog')).toBeVisible(); await expect(page.getByText('4 от 5 · 80 %')).toBeVisible();
    if (width!==768) await page.screenshot({path:test.info().outputPath(`child-${width}.png`),fullPage:false});
    await page.keyboard.press('Escape'); await expect(eye).toBeFocused(); await layout(page);
    await page.getByRole('group',{name:'Ана Иванова'}).getByRole('button',{name:'Присъства',exact:true}).click(); await expect(page.getByText('Записано: Ана Иванова — Присъства',{exact:true})).toBeVisible();
    await page.getByRole('button',{name:'Отмени',exact:true}).click(); await expect.poll(()=>f.writes.length).toBe(2); expect(JSON.parse(f.writes[1].body).marks[0]).toMatchObject({version:1,status:null});
    await page.getByRole('button',{name:'✓ Всички присъстват',exact:true}).click(); await expect(page.getByText('Неотбелязани 0',{exact:true})).toBeVisible();
    await expect(page.getByText('Записано: 2 деца — Присъства',{exact:true})).toBeVisible(); await page.reload(); await expect(page.getByText('✓ Всички деца са отбелязани',{exact:true})).toBeVisible(); await layout(page);
    if (width!==768) await page.screenshot({path:test.info().outputPath(`history-${width}.png`),fullPage:true}); await page.goto('/dashboard'); await page.getByRole('link',{name:'Присъствия за месеца →',exact:true}).first().click(); await expect(page.getByRole('region',{name:'Присъствия за месеца'})).toBeVisible(); await layout(page); expect(f.errors).toEqual([]);
  });
}
test('offline requests keep body/key, serialize later clicks and retry on reconnect',async({page})=>{
  const f=await fixture(page,{offline:true}); await page.goto(`/dashboard/attendance/${id(10)}`);
  const row=page.getByRole('group',{name:'Ана Иванова'}); await row.getByRole('button',{name:'Присъства',exact:true}).click(); await expect(page.getByText('Няма връзка. Отметките ще се запишат, щом се върне.')).toBeVisible();
  expect(await page.evaluate(()=>{const event=new Event('beforeunload',{cancelable:true}); window.dispatchEvent(event); return event.defaultPrevented;})).toBe(true);
  await row.getByRole('button',{name:'Отсъства',exact:true}).click(); await page.evaluate(()=>window.dispatchEvent(new Event('online'))); await page.waitForTimeout(100); await page.evaluate(()=>window.dispatchEvent(new Event('online')));
  await expect.poll(()=>f.writes.length).toBe(4); expect(f.writes[0]).toEqual(f.writes[1]); expect(f.writes[0]).toEqual(f.writes[2]); expect(JSON.parse(f.writes[3].body).marks[0].version).toBe(1);
});
test('late correction requires reason and conflict replaces the roster',async({page})=>{
  const f=await fixture(page,{late:true,conflict:true}); await page.goto(`/dashboard/attendance/${id(10)}`); await page.getByRole('button',{name:'Поправи',exact:true}).click();
  const row=page.getByRole('group',{name:'Ана Иванова'}); await row.getByRole('button',{name:'Присъства',exact:true}).click(); await expect(page.getByLabel('Причина (задължителна)')).toBeFocused(); expect(f.writes).toHaveLength(0);
  await page.getByLabel('Причина (задължителна)').fill('Поправка след проверка'); await row.getByRole('button',{name:'Присъства',exact:true}).click(); await expect(page.getByText(/Междувременно Мария Колева отбеляза/)).toBeVisible(); await expect(row.getByRole('button',{name:'✕ Отсъства',exact:true})).toHaveAttribute('aria-pressed','true');
});

test('topic debounce and paused attendance use the queue; read-only and not-held have no write controls',async({page})=>{
 const f=await fixture(page); await page.goto(`/dashboard/attendance/${id(10)}`); await page.getByLabel('Тема на заниманието (по избор)').fill('Нова тема');
 await expect.poll(()=>f.writes.length).toBe(1); expect(JSON.parse(f.writes[0].body)).toEqual({action:'topic',topic:'Нова тема'});
 await page.getByRole('group',{name:'Николай Савов'}).getByRole('button',{name:'Присъства',exact:true}).click(); await expect(page.getByText('✓ Присъства · дошло по време на пауза',{exact:true})).toBeVisible();
 await expect.poll(()=>f.writes.length).toBe(2); expect(JSON.parse(f.writes.at(-1)!.body).marks[0].enrollment_id).toBe(id(23));
});
test('read-only and not-held sessions hide marking buttons',async({page})=>{
 await fixture(page,{readOnly:true}); await page.goto(`/dashboard/attendance/${id(10)}`); await expect(page.getByRole('heading',{name:'Не се очакват днес'})).toBeVisible(); await expect(page.getByRole('button',{name:'Поправи',exact:true})).toHaveCount(0); await expect(page.getByRole('button',{name:'Присъства',exact:true})).toHaveCount(0);
 await page.unroute('**/backend/**'); await fixture(page,{notHeld:true}); await page.reload(); await expect(page.getByText('Заниманието не се проведе — присъствия не се отбелязват.',{exact:true})).toBeVisible(); await expect(page.getByRole('button',{name:'Присъства',exact:true})).toHaveCount(0);
});
