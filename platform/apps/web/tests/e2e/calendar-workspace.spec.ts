import {expect,test,type Page} from '@playwright/test';
import {fixture} from './attendance-fixture';
import type {ScheduleBoard,ScheduleSession} from '../../src/features/schedule/api';
const id=(n:number)=>`00000000-0000-4000-8000-${String(n).padStart(12,'0')}`;
async function calendar(page:Page,own=false){
 const f=await fixture(page,{office:!own});
 const base:ScheduleSession={id:id(10),version:1,starts_at:'2026-11-02T15:30:00Z',ends_at:'2026-11-02T16:30:00Z',status:'scheduled',moved:false,group_id:id(4),activity_id:id(3),location_id:id(5),instructors:[{profile_id:id(70),name:'Демо преподавател',role:'lead'}],capacity:12,taken:8,waiting:1,attendance:{present:6,absent:2,unmarked:0}};
 const sessions=[base,...[3,4,5,6].map((day,i)=>({...base,id:id(11+i),starts_at:`2026-11-0${day}T15:30:00Z`,ends_at:`2026-11-0${day}T16:30:00Z`,status:day===4?'cancelled':'scheduled',not_held:day===5,moved:day===6,attendance:null})),{...base,id:id(20),starts_at:'2026-11-03T15:45:00Z',ends_at:'2026-11-03T16:45:00Z',location_id:id(6),group_id:id(7)}];
 const board:ScheduleBoard={sessions,groups:[{id:id(4),name:'Група 2',activity_id:id(3),age_label:'3.–4. клас'},{id:id(7),name:'Начинаещи',activity_id:id(3),age_label:'1.–2. клас'}],activities:[{id:id(3),title:'Шахмат',color:0}],locations:[{id:id(5),name:'Зала „Слънце“'},{id:id(6),name:'Зала „Звезда“'}],instructors:[{id:id(70),name:'Демо преподавател'}],abilities:{[id(4)]:{manage:!own,children:true,substitute:!own},[id(7)]:{manage:!own,children:true,substitute:!own}}};
 const queries:string[]=[];
 await page.route('**/backend/api/v1/**',async route=>{
  const url=new URL(route.request().url());
  if(url.pathname.endsWith('/schedule')){
   queries.push(url.search);const p=url.searchParams;const found=sessions.filter(s=>s.starts_at.slice(0,10)>=p.get('from')!&&s.starts_at.slice(0,10)<=p.get('to')!&&(!p.getAll('location[]').length||p.getAll('location[]').includes(s.location_id!))&&(!p.getAll('group[]').length||p.getAll('group[]').includes(s.group_id)));
   return route.fulfill({json:{...board,sessions:found}});
  }
  return route.fallback();
 });
 return {...f,queries};
}
async function bounds(page:Page){
 expect(await page.evaluate(()=>document.documentElement.scrollWidth-innerWidth)).toBeLessThanOrEqual(1);
 const clipped=await page.getByRole('group',{name:'Изглед',exact:true}).getByRole('button').evaluateAll(nodes=>nodes.filter(el=>el.scrollWidth>el.clientWidth+1).map(el=>el.textContent));expect(clipped).toEqual([]);
 const bad=await page.locator('button:visible,input:visible').evaluateAll(nodes=>nodes.flatMap(el=>{if(el.getRootNode()!==document||el.closest('[data-column]'))return [];const r=el.getBoundingClientRect();return r.width>0&&r.left>=0&&r.right<=innerWidth&&(r.height<43.5||r.width<43.5)?[{text:el.getAttribute('aria-label')??el.textContent,width:r.width,height:r.height}]:[];}));expect(bad).toEqual([]);
}
for(const width of [390,768,1440])test(`all calendar views and attendance facts remain available at ${width}px`,async({page})=>{
 await page.setViewportSize({width,height:1000});const f=await calendar(page);await page.goto('/dashboard/schedule?view=week&date=2026-11-02');
 const views=page.getByRole('group',{name:'Изглед',exact:true});await expect(views.getByRole('button')).toHaveCount(4);await expect(page.getByRole('region',{name:'Седмица по часове',exact:true})).toBeVisible();
 const current=page.locator('[data-session-id]').filter({hasText:'17:30'}).first();await current.scrollIntoViewIfNeeded();await expect(current.getByText('8/12',{exact:true})).toBeVisible();await expect(current.getByTitle('✓ отбелязано',{exact:true})).toBeVisible();await bounds(page);
 await page.screenshot({path:test.info().outputPath(`week-${width}.png`),fullPage:true});
 await views.getByRole('button',{name:'Месец',exact:true}).click();await expect(page.getByRole('region',{name:'Месец',exact:true})).toBeVisible();await bounds(page);await page.screenshot({path:test.info().outputPath(`month-${width}.png`),fullPage:true});
 await views.getByRole('button',{name:'Ден',exact:true}).click();await expect(page.getByRole('button',{name:'По зали',exact:true})).toHaveAttribute('aria-pressed','true');await page.getByRole('button',{name:'По треньори',exact:true}).click();await expect(page).toHaveURL(/by=teachers/);await expect(page.getByRole('region',{name:/— по треньори$/})).toBeVisible();await bounds(page);
 await page.getByRole('button',{name:'По зали',exact:true}).click();await expect(page.getByRole('region',{name:/— по зали$/})).toBeVisible();await page.screenshot({path:test.info().outputPath(`day-${width}.png`),fullPage:true});
 await views.getByRole('button',{name:'Карти',exact:true}).click();await expect(page.locator('[data-session-id]').first().getByText('✓ отбелязано',{exact:true})).toBeVisible();await bounds(page);await page.screenshot({path:test.info().outputPath(`cards-${width}.png`),fullPage:true});
 await page.reload();await expect(views.getByRole('button',{name:'Карти',exact:true})).toHaveAttribute('aria-pressed','true');
 expect(f.errors).toEqual([]);
});

test('phone actions, selection, date and filter history keep their functionality',async({page})=>{
 await page.setViewportSize({width:390,height:844});const f=await calendar(page);await page.goto('/dashboard/schedule?view=cards&date=2026-11-02');
 const actions=page.getByRole('button',{name:'Действия',exact:true});await expect(actions).toHaveAttribute('aria-expanded','false');await expect(page.getByRole('button',{name:'Почивен ден',exact:true})).not.toBeVisible();
 await actions.click();await expect(page.getByRole('button',{name:'Почивен ден',exact:true})).toBeVisible();await expect(page.getByRole('button',{name:'+ Допълнително занимание',exact:true})).toBeVisible();await page.getByRole('button',{name:'Избери',exact:true}).click();await page.getByRole('checkbox').first().check();
 const bulk=page.getByRole('region',{name:'Избрани занимания',exact:true});await expect(bulk.getByRole('button',{name:'Смени залата'})).toBeVisible();await expect(bulk.getByRole('button',{name:'Назначи заместник'})).toBeVisible();
 const nav=await page.getByRole('navigation',{name:'Основно меню на телефон'}).boundingBox();const bar=await bulk.boundingBox();expect(bar!.y+bar!.height).toBeLessThanOrEqual(nav!.y+1);await page.keyboard.press('Escape');await expect(bulk).not.toBeVisible();await actions.focus();await page.keyboard.press('Escape');await expect(actions).toBeFocused();await expect(actions).toHaveAttribute('aria-expanded','false');
 await page.getByLabel('Избери дата',{exact:true}).fill('2026-11-03');await expect(page).toHaveURL(/date=2026-11-03/);await page.getByRole('group',{name:'Изглед',exact:true}).getByRole('button',{name:'Ден',exact:true}).click();
 await page.getByRole('button',{name:'Филтри',exact:true}).click();const filters=page.getByRole('dialog',{name:'Филтри',exact:true});await filters.getByRole('checkbox',{name:'Зала „Звезда“',exact:true}).click();await expect(filters.getByRole('checkbox',{name:'Зала „Звезда“',exact:true})).toBeChecked();await filters.getByRole('button',{name:'Готово',exact:true}).click();
 await expect(page.getByRole('list',{name:'Избрани филтри'})).toContainText('Зала „Звезда“');await expect(page.locator('[data-session-id]')).toHaveCount(1);const filtered=page.url();await page.reload();await expect(page.locator('[data-session-id]')).toHaveCount(1);expect(page.url()).toBe(filtered);
 await page.getByRole('button',{name:'Изчисти филтрите',exact:true}).click();await expect(page.locator('[data-session-id]')).toHaveCount(2);await page.goBack();await expect(page.locator('[data-session-id]')).toHaveCount(1);await expect(page).toHaveURL(filtered);await bounds(page);expect(f.errors).toEqual([]);
});

test('teacher retains only own views and filters without office actions',async({page})=>{
 await page.setViewportSize({width:390,height:844});await calendar(page,true);await page.goto('/dashboard/schedule?view=week&date=2026-11-02');
 await expect(page.getByRole('heading',{name:'Моят график',exact:true})).toBeVisible();const views=page.getByRole('group',{name:'Изглед',exact:true});await expect(views.getByRole('button')).toHaveCount(2);await expect(page.getByRole('button',{name:'Действия',exact:true})).toHaveCount(0);
 await page.getByRole('button',{name:'Филтри',exact:true}).click();const filters=page.getByRole('dialog',{name:'Филтри',exact:true});await expect(filters.getByRole('heading',{name:'Треньор',exact:true})).toHaveCount(0);await expect(filters.getByLabel('Дете',{exact:true})).toHaveCount(0);await expect(filters.getByRole('heading',{name:'Място',exact:true})).toBeVisible();
});
