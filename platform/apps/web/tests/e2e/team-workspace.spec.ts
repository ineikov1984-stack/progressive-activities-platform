import {expect,test,type Page} from '@playwright/test';
import {fixture} from './attendance-fixture';
import type {TeamMember,AccessCard} from '../../src/features/team/api';
const id=(n:number)=>`00000000-0000-4000-8000-${String(n).padStart(12,'0')}`;
async function team(page:Page,options:{viewer?:boolean;denied?:boolean;fail?:boolean;empty?:boolean}={}){
 const base=await fixture(page,{office:true});let failed=!!options.fail;
 const permissions=options.denied?['view']:['view','team.view',...(options.viewer?[]:['team.manage','catalog.manage'])];
 const groups=Array.from({length:3},(_,i)=>({group_id:id(60+i),activity:i===2?'Рисуване':'Шахмат',group:`Група ${i+1} · 1.–4. клас`,label:`Шахмат · Група ${i+1}`,role:i===1?'assistant':'lead',starts_on:'2026-10-01',ends_on:null,version:1}));
 const member:TeamMember={id:id(50),full_name:'Мария Демо Колева',state:'login',role:'instructor',instructor_profile_id:id(70),groups,archived_at:null,version:1,...(options.viewer?{}:{phone:'+359888000111',email:'teacher@example.test',last_login_at:'2026-11-02T08:00:00Z'})};
 const members:TeamMember[]=[member,{...member,id:id(51),full_name:'Иван Демо Нейков',state:'profile_only',role:null,groups:[],invitation:null},{...member,id:id(52),full_name:'Елена Демо Тодорова',state:'profile_only',role:null,groups:[groups[0]],invitation:{status:'pending',expires_at:'2026-11-08T08:00:00Z',role:'instructor'}},{...member,id:id(53),full_name:'Петър Демо Георгиев',role:'coordinator',instructor_profile_id:null,groups:[]}];
 const access:AccessCard={member_id:member.id,state:'access',role:'instructor',scope:'own',allowed_scopes:['own'],groups:[],activities:[],rights:[],template:null,version:1,editable:!options.viewer,reason:null};
 const requests:string[]=[];
 await page.route('**/backend/api/v1/**',async route=>{
  const req=route.request(),url=new URL(req.url()),path=url.pathname;let json:unknown;
  if(path.endsWith('/organizations'))json={organizations:[{id:id(2),name:'Школа „Звезда“',slug:'demo',status:'active',role:options.viewer?'coordinator':'organization_owner',permissions}]};
  else if(path.endsWith('/context/organization'))json={access:{role:options.viewer?'coordinator':'organization_owner',scope:'all',abilities:permissions,groups:[],activities:[]}};
  else if(path.endsWith('/team')){requests.push(url.search);if(failed)return route.fulfill({status:503,json:{message:'Списъкът на екипа не се зареди.'}});json={members:options.empty?[]:url.searchParams.has('archived')?[{...member,state:'archived',full_name:'Архивен Демо Колега',archived_at:'2026-10-01T08:00:00Z'}]:members,can_manage:!options.viewer};}
  else if(path.endsWith(`/team/members/${member.id}/access`))json={access};
  else if(path.endsWith(`/team/members/${member.id}`))json={member,...(options.viewer?{}:{history:[]})};
  else return route.fallback();
  return route.fulfill({json});
 });return {...base,member,requests,recover:()=>{failed=false;}};
}
async function layout(page:Page){
 expect(await page.evaluate(()=>document.documentElement.scrollWidth-innerWidth)).toBeLessThanOrEqual(1);
 const small=await page.locator('button,a[href],input,select,summary').evaluateAll(nodes=>nodes.flatMap(el=>{if(el.getRootNode()!==document||!el.checkVisibility()||el.closest('[inert]'))return [];const label=el instanceof HTMLInputElement&&['radio','checkbox'].includes(el.type)?el.labels?.[0]:null;const r=(label??el).getBoundingClientRect();return r.width>0&&r.left<innerWidth&&r.right>0&&(r.width<43.5||r.height<43.5)?[{text:el.getAttribute('aria-label')??el.textContent,width:r.width,height:r.height}]:[];}));expect(small).toEqual([]);
}
async function openFilters(page:Page){const toggle=page.getByRole('button',{name:/^Филтри/});if(await toggle.isVisible())await toggle.click();}
for(const width of [390,768,1440])test(`team layout, multiple roles, archive and profile at ${width}px`,async({page})=>{
 await page.setViewportSize({width,height:1000});const f=await team(page);await page.goto('/dashboard/team');await expect(page.getByRole('link',{name:f.member.full_name,exact:true})).toBeVisible();await expect(page.getByRole('link',{name:f.member.full_name,exact:true})).toHaveCSS('text-decoration-line','underline');await layout(page);expect(await page.getByRole('heading',{name:'Екип',exact:true}).evaluate(el=>el.getBoundingClientRect().left)).toBeGreaterThan(width>=1200?280:10);await page.screenshot({path:test.info().outputPath(`team-${width}.png`),fullPage:true});
 await page.getByRole('button',{name:/3 групи/}).click();await expect(page.getByRole('link',{name:/Група 3/})).toBeVisible();await openFilters(page);
 await page.getByRole('button',{name:/Роля Всички роли/}).click();await page.getByRole('checkbox',{name:'Преподавател',exact:true}).check();await expect(page.getByRole('link',{name:'Петър Демо Георгиев',exact:true})).toHaveCount(0);await page.getByRole('checkbox',{name:'Координатор',exact:true}).check();await expect(page.getByRole('link',{name:'Петър Демо Георгиев',exact:true})).toBeVisible();await page.keyboard.press('Escape');await expect(page.getByRole('button',{name:/Роля/})).toBeFocused();await layout(page);await page.screenshot({path:test.info().outputPath(`filters-${width}.png`),fullPage:true});
 await page.getByLabel('Достъп',{exact:true}).selectOption('archived');await expect(page.getByRole('link',{name:'Архивен Демо Колега',exact:true})).toBeVisible();expect(f.requests).toContain('?archived=1');await page.getByRole('button',{name:'Изчисти всички филтри',exact:true}).click();await expect(page.getByRole('link',{name:f.member.full_name,exact:true})).toBeVisible();
 await page.getByRole('searchbox',{name:'Търси по име'}).fill('няма такъв');await expect(page.getByText('Никой не отговаря на търсенето')).toBeVisible();await page.getByRole('button',{name:'Изчисти всички филтри',exact:true}).click();
 await page.getByRole('link',{name:f.member.full_name,exact:true}).click();await expect(page.getByRole('heading',{name:f.member.full_name,level:1})).toBeVisible();await expect(page.getByRole('heading',{name:'Групи',exact:true})).toBeVisible();await expect(page.getByRole('heading',{name:'Права',exact:true})).toBeVisible();await expect(page.getByRole('heading',{name:'Данни',exact:true})).toBeVisible();await layout(page);await page.screenshot({path:test.info().outputPath(`profile-${width}.png`),fullPage:true});expect(f.errors).toEqual([]);
});
test('team viewer sees provided data without management actions or contacts',async({page})=>{
 await page.setViewportSize({width:390,height:1000});const f=await team(page,{viewer:true});await page.goto('/dashboard/team');await expect(page.getByRole('link',{name:f.member.full_name,exact:true})).toBeVisible();await expect(page.getByRole('button',{name:'+ Добави в екипа',exact:true})).toHaveCount(0);await expect(page.getByRole('link',{name:'Изпрати покана',exact:true})).toHaveCount(0);await expect(page.getByText('+359888000111',{exact:true})).toHaveCount(0);await page.getByRole('link',{name:f.member.full_name,exact:true}).click();await expect(page.getByRole('heading',{name:'Права',exact:true})).toBeVisible();await expect(page.getByRole('button',{name:'+ Добави група',exact:true})).toHaveCount(0);await expect(page.getByRole('button',{name:'Промени данните',exact:true})).toHaveCount(0);expect(f.errors).toEqual([]);
});
test('team access guard does not request the team',async({page})=>{
 const f=await team(page,{denied:true});await page.goto('/dashboard/team');await expect(page.getByRole('note')).toContainText('Страницата „Екип“ не е за вашата роля');expect(f.requests).toEqual([]);
});
test('team error retries, empty state and add panel returns focus',async({page})=>{
 const f=await team(page,{fail:true,empty:true});await page.goto('/dashboard/team');await expect(page.getByRole('alert').filter({hasText:'Списъкът'})).toContainText('Списъкът на екипа не се зареди.');f.recover();await page.getByRole('button',{name:'Опитай отново',exact:true}).click();await expect(page.getByText('В екипа още няма никого')).toBeVisible();const add=page.getByRole('button',{name:'+ Добави в екипа',exact:true});await add.click();await expect(page.getByRole('dialog')).toBeVisible();await page.keyboard.press('Escape');await expect(page.getByRole('dialog')).toHaveCount(0);await expect(add).toBeFocused();expect(f.errors).toEqual([]);
});
