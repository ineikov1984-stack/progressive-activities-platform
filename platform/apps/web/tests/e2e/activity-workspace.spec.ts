import {expect,test,type Page} from '@playwright/test';
import {fixture} from './attendance-fixture';
import type {ActivityDraft} from '../../src/features/catalog/admin/api';
const id=(n:number)=>`00000000-0000-4000-8000-${String(n).padStart(12,'0')}`;
async function catalog(page:Page,failList=false){
 const f=await fixture(page,{office:true});let failures=failList?Infinity:0;
 const draft:ActivityDraft={id:id(40),slug:'demo-chess',version:1,status:'draft',title:'Шахмат и логически предизвикателства за начинаещи',category:'Логика и игри',summary:'Демонстрационни занимания за развитие на мисленето.',age_label:'1.–4. клас',sections:{about:'Играем и учим.',benefits:[],gallery:[],faq:[]},offering:{id:id(41),version:1,location_id:null,lead_instructor_profile_id:null,price_options:[]}};
 const activities:ActivityDraft[]=[draft,{...draft,id:id(42),title:'Рисуване и творчески ателиета',category:'Изкуства',status:'published',slug:'demo-art'},{...draft,id:id(43),title:'Музикално приключение',category:'Музика',status:'archived'}];
 const writes:Record<string,unknown>[]=[];
 await page.route('**/backend/api/v1/**',async route=>{
  const req=route.request(),path=new URL(req.url()).pathname;let json:unknown;
  if(path.endsWith('/catalog/activities')){
   if(req.method()==='POST'){const body=req.postDataJSON();writes.push(body);const activity={...draft,...body,id:id(60)};activities.push(activity);json={activity};}
   else {if(failures-->0)return route.fulfill({status:503,json:{message:'Дейностите не се заредиха.'}});json={activities};}
  }
  else if(path.match(/\/catalog\/activities\/[^/]+$/)){const activity=activities.find(a=>path.endsWith(a.id))!;if(req.method()==='PATCH'){const body=req.postDataJSON();writes.push(body);Object.assign(activity,body,{version:activity.version+1});}json={activity};}
  else if(path.endsWith('/groups'))json={groups:[]};
  else if(path.endsWith('/catalog/profile'))json={profile:{slug:'demo',public_name:'Демо школа'}};
  else if(path.endsWith('/catalog/locations'))json={locations:[]};
  else if(path.endsWith('/catalog/instructors'))json={instructors:[]};
  else return route.fallback();
  return route.fulfill({json});
 });return {...f,writes,activities,draft,recover:()=>{failures=0;}};
}
async function layout(page:Page){
 expect(await page.evaluate(()=>document.documentElement.scrollWidth-innerWidth)).toBeLessThanOrEqual(1);
 const bad=await page.locator('button,a[href],input,select').evaluateAll(nodes=>nodes.flatMap(el=>{if(el.getRootNode()!==document||!el.checkVisibility()||el.closest('[inert]'))return [];const label=el instanceof HTMLInputElement&&el.type==='radio'?el.labels?.[0]:null;const r=(label??el).getBoundingClientRect();return r.width>0&&r.left<innerWidth&&r.right>0&&(r.width<43.5||r.height<43.5)?[{text:el.getAttribute('aria-label')??el.textContent,width:r.width,height:r.height}]:[];}));expect(bad).toEqual([]);
}
for(const width of [390,768,1440])test(`activity cards, filters and categories use the workspace layout at ${width}px`,async({page})=>{
 await page.setViewportSize({width,height:1000});const f=await catalog(page);await page.goto('/dashboard/activities');await expect(page.getByRole('article')).toHaveCount(3);await layout(page);await page.screenshot({path:test.info().outputPath(`activities-${width}.png`),fullPage:true});
 const published=page.getByRole('article').filter({hasText:'Рисуване и творчески ателиета'});await expect(published.getByRole('link',{name:'Преглед като родител'})).toHaveAttribute('href','/o/demo/activities/demo-art');await expect(published.getByRole('progressbar')).toHaveCount(0);await expect(published.getByRole('link',{name:'Прегледай',exact:true})).toHaveAttribute('href',/dashboard\/activities\//);
 await page.getByRole('button',{name:'Чернови 1',exact:true}).click();await expect(page.getByRole('article')).toHaveCount(1);await page.getByRole('searchbox',{name:'Търсене в дейностите'}).fill('няма такава');await expect(page.getByRole('heading',{name:'Няма намерени дейности'})).toBeVisible();await page.getByRole('button',{name:'Изчисти филтрите',exact:true}).click();await expect(page.getByRole('article')).toHaveCount(3);
 const create=page.getByRole('button',{name:'Създай нова дейност',exact:true});await create.click();const form=page.getByRole('form',{name:'Нова дейност'});await expect(form.getByLabel('Име на дейността')).toBeFocused();await expect(page.getByRole('article')).toHaveCount(0);
 await form.getByRole('radio',{name:'Наука и технологии',exact:true}).locator('..').click();await expect(form.getByRole('radio',{name:'Наука и технологии',exact:true})).toBeChecked();await layout(page);await page.screenshot({path:test.info().outputPath(`new-activity-${width}.png`),fullPage:true});
 await form.getByRole('button',{name:'Откажи',exact:true}).click();await expect(create).toBeFocused();await expect(page.getByRole('article')).toHaveCount(3);expect(f.errors).toEqual([]);
});

test('activity loading error offers retry and then restores the list',async({page})=>{
 const f=await catalog(page,true);await page.goto('/dashboard/activities');await expect(page.getByRole('alert').filter({hasText:'Дейностите'})).toBeVisible();await expect(page.getByRole('region',{name:'Дейности',exact:true})).toHaveAttribute('aria-busy','false');f.recover();await page.getByRole('button',{name:'Опитай пак',exact:true}).click();await expect(page.getByRole('article')).toHaveCount(3);
});

for(const width of [390,768,1440])test(`creating and changing editor steps keeps the activity and automatic save at ${width}px`,async({page})=>{
 await page.setViewportSize({width,height:1000});const f=await catalog(page);await page.goto('/dashboard/activities?new=1');await page.getByLabel('Име на дейността',{exact:true}).fill('Демо роботика за начинаещи');await page.getByRole('radio',{name:'Наука и технологии',exact:true}).locator('..').click();await page.getByRole('button',{name:'Продължи към детайлите',exact:true}).click();await expect(page.getByRole('heading',{level:1,name:'Демо роботика за начинаещи'})).toBeVisible();
 await page.getByLabel('Кратко описание',{exact:true}).fill('Ново описание за демо роботиката.');
 const choice=page.getByRole('combobox',{name:'Избери стъпка',exact:true});if(width<=820){await choice.selectOption('2');await expect(choice).toHaveValue('2');}else await page.getByRole('navigation',{name:'Стъпки',exact:true}).getByRole('button').nth(1).click();
 await expect.poll(()=>f.writes.some(w=>w.summary==='Ново описание за демо роботиката.')).toBe(true);await layout(page);await page.screenshot({path:test.info().outputPath(`editor-${width}.png`),fullPage:true});
 await page.reload();await expect(page).toHaveURL(/step=2/);await expect(page.getByRole('heading',{level:1,name:'Демо роботика за начинаещи'})).toBeVisible();if(width<=820)await expect(choice).toHaveValue('2');expect(f.errors).toEqual([]);
});

test('archived activity opens for inspection with the correct status',async({page})=>{
 await page.setViewportSize({width:390,height:1000});const f=await catalog(page);await page.goto('/dashboard/activities');const archived=page.getByRole('article').filter({hasText:'Музикално приключение'});await archived.getByRole('link',{name:'Прегледай',exact:true}).click();await expect(page.getByRole('status').filter({hasText:'Архивирана'})).toBeVisible();await expect(page.getByRole('note')).toContainText('Дейността е в архива.');await expect(page.getByLabel('Име на дейността',{exact:true})).toBeDisabled();expect(f.writes).toEqual([]);await page.screenshot({path:test.info().outputPath('archived-390.png'),fullPage:true});
});

for(const width of [390,768,1440])test(`draft page preview uses current editor content without publishing at ${width}px`,async({page})=>{
 await page.setViewportSize({width,height:1000});const f=await catalog(page);await page.goto(`/dashboard/activities/${id(40)}`);
 await page.getByLabel('Кратко описание',{exact:true}).fill('Последно въведено описание още в редактора.');
 const trigger=page.getByRole('button',{name:'Преглед на страницата',exact:true});await trigger.click();
 const dialog=page.getByRole('dialog',{name:'Преглед на страницата',exact:true});await expect(dialog).toBeVisible();await expect(dialog.getByRole('heading',{name:'Преглед на страницата',exact:true})).toBeFocused();
 await expect(dialog.getByText('Последно въведено описание още в редактора.',{exact:true})).toBeVisible();await expect(dialog.getByRole('heading',{name:'За дейността',exact:true})).toBeVisible();await expect(dialog.getByRole('link')).toHaveCount(0);
 await expect(dialog.getByText('Играем и учим.',{exact:true})).toBeVisible();await expect(dialog.getByRole('button',{name:'Обратно към редактора'})).toBeVisible();
 expect(await dialog.evaluate(node=>node.scrollWidth-node.clientWidth)).toBeLessThanOrEqual(1);expect(await dialog.locator('main').evaluate(node=>node.scrollWidth-node.clientWidth)).toBeLessThanOrEqual(1);
 await page.keyboard.press('Shift+Tab');await expect(dialog.getByRole('button',{name:'Обратно към редактора'})).toBeFocused();await page.keyboard.press('Tab');await expect(dialog.getByRole('button',{name:'Затвори прегледа'})).toBeFocused();
 await dialog.getByText('Идеи за по-пълно представяне',{exact:false}).click();await expect(dialog.getByText(/Може да добавите ползи/)).toBeVisible();
 await page.screenshot({path:test.info().outputPath(`page-preview-${width}.png`),fullPage:true});
 await page.keyboard.press('Escape');await expect(dialog).toHaveCount(0);await expect(trigger).toBeFocused();await expect(page.getByLabel('Кратко описание',{exact:true})).toHaveValue('Последно въведено описание още в редактора.');
 const choice=page.getByRole('combobox',{name:'Избери стъпка',exact:true});if(width<=820)await choice.selectOption('3');else await page.getByRole('navigation',{name:'Стъпки',exact:true}).getByRole('button').nth(2).click();
 await page.locator('#w-about').fill('Ново подробно представяне на дейността.');await trigger.click();await expect(dialog.getByText('Ново подробно представяне на дейността.',{exact:true})).toBeVisible();await expect(dialog.getByText('Играем и учим.',{exact:true})).toHaveCount(0);
 await dialog.getByRole('button',{name:'Обратно към редактора'}).click();await expect(page.locator('#w-about')).toHaveValue('Ново подробно представяне на дейността.');expect(f.errors).toEqual([]);expect(f.writes.every(w=>!('status' in w))).toBe(true);
});

for(const width of [390,1440])test(`rich draft preview renders private gallery and instructor photo at ${width}px`,async({page})=>{
 await page.setViewportSize({width,height:1000});
 const f=await catalog(page);f.draft.sections={about:'Подробно представяне',benefits:['Увереност и логическо мислене'],methodology:'Учим чрез практика.',gallery:[{path:'catalog/demo/gallery.png',alt:'Демо галерия'}],faq:[{question:'Нужен ли е опит?',answer:'Не е нужен предварителен опит.'}]};f.draft.offering!.price_options=[{unit:'month',enrollment_mode:'monthly',amount_cents:6500}];
 f.draft.offering!.lead_instructor_profile_id=id(71);await page.route('**/backend/api/v1/catalog/instructors',route=>route.fulfill({json:{instructors:[{id:id(71),public_name:'Демо преподавател',is_public:true,version:1,photo_path:'catalog/demo/portrait.png'}]}}));
 const headers:string[]=[];await page.route('**/backend/api/v1/catalog/media/**',route=>{headers.push(route.request().headers()['x-organization-id']);return route.fulfill({contentType:'image/png',body:Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aWQAAAABJRU5ErkJggg==','base64')});});
 await page.goto(`/dashboard/activities/${id(40)}`);await page.getByRole('button',{name:'Преглед на страницата',exact:true}).click();const dialog=page.getByRole('dialog');await expect(dialog.getByText('Увереност и логическо мислене',{exact:true})).toBeVisible();await expect(dialog.getByText('Учим чрез практика.',{exact:true})).toBeVisible();await expect(dialog.getByText(/65,00/)).toBeVisible();await expect(dialog.getByRole('img',{name:'Демо галерия'})).toBeVisible();await dialog.locator('summary').filter({hasText:'Нужен ли е опит?'}).click();await expect(dialog.getByText('Не е нужен предварителен опит.',{exact:true})).toBeVisible();const portrait=dialog.getByRole('img',{name:'Демо преподавател',exact:true});await expect(portrait).toBeVisible();const box=await portrait.boundingBox();expect(box?.width).toBe(160);expect(box?.height).toBe(160);expect(headers.length).toBeGreaterThan(0);expect(headers.every(Boolean)).toBe(true);expect(f.errors).toEqual([]);
});
