import { expect, test } from '@playwright/test';
import { fixture, layout } from './attendance-fixture';
const id = (n:number) => `00000000-0000-4000-8000-${String(n).padStart(12,'0')}`;
for (const width of [390,768,1440]) {
  test(`teacher attendance and child sheet at ${width}px`, async ({page}) => {
    await page.setViewportSize({width,height:1000}); const f=await fixture(page);
    await page.goto('/dashboard'); await expect(page.getByRole('heading',{name:'Днес',exact:true})).toBeVisible(); await layout(page);
    await page.screenshot({path:test.info().outputPath(`today-${width}.png`),fullPage:true});
    await page.getByRole('link',{name:'Отбележи присъствията',exact:true}).click(); await expect(page.getByRole('heading',{name:'Не се очакват днес'})).toBeVisible(); await layout(page);
    await page.screenshot({path:test.info().outputPath(`mark-${width}.png`),fullPage:true});
    const eye=page.getByRole('button',{name:'Виж Ана Иванова',exact:true}); await eye.click(); await expect(page.getByRole('dialog')).toBeVisible(); await expect(page.getByText('4 от 5 · 80 %')).toBeVisible();
    await page.screenshot({path:test.info().outputPath(`child-${width}.png`),fullPage:false});
    await page.keyboard.press('Escape'); await expect(eye).toBeFocused(); await layout(page);
    await page.getByRole('group',{name:'Ана Иванова'}).getByRole('button',{name:'Присъства',exact:true}).click(); await expect(page.getByText('Записано: Ана Иванова — Присъства',{exact:true})).toBeVisible();
    await page.getByRole('button',{name:'Отмени',exact:true}).click(); await expect.poll(()=>f.writes.length).toBe(2); expect(JSON.parse(f.writes[1].body).marks[0]).toMatchObject({version:1,status:null});
    await page.getByRole('button',{name:'✓ Всички присъстват',exact:true}).click(); await expect(page.getByText('Неотбелязани 0',{exact:true})).toBeVisible();
    await expect(page.getByText('Записано: 2 деца — Присъства',{exact:true})).toBeVisible(); await page.reload(); await expect(page.getByText('✓ Всички деца са отбелязани',{exact:true})).toBeVisible(); await layout(page);
    await page.screenshot({path:test.info().outputPath(`history-${width}.png`),fullPage:true}); await page.goto('/dashboard'); await page.getByRole('link',{name:'Присъствия за месеца →',exact:true}).first().click(); await expect(page.getByRole('region',{name:'Присъствия за месеца'})).toBeVisible(); await layout(page); expect(f.errors).toEqual([]);
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
 await fixture(page,{readOnly:true}); await page.goto(`/dashboard/attendance/${id(10)}`); await expect(page.getByRole('heading',{name:'Не се очакват днес'})).toBeVisible(); await expect(page.getByText('Само преглед — отбелязването още не е започнало или нямате право за промяна.',{exact:true})).toBeVisible();await expect(page.getByRole('button',{name:'Поправи',exact:true})).toHaveCount(0); await expect(page.getByRole('button',{name:'Присъства',exact:true})).toHaveCount(0);
 await page.unroute('**/backend/**'); await fixture(page,{notHeld:true}); await page.reload(); await expect(page.getByText('Заниманието не се проведе — присъствия не се отбелязват.',{exact:true})).toBeVisible(); await expect(page.getByRole('button',{name:'Присъства',exact:true})).toHaveCount(0);
});

test('pending marks warn on sidebar navigation and browser Back',async({page})=>{
 const f=await fixture(page,{offline:true,offlineFailures:100});await page.goto('/dashboard');await page.getByRole('link',{name:'Отбележи присъствията',exact:true}).click();await page.getByRole('group',{name:'Ана Иванова'}).getByRole('button',{name:'Присъства',exact:true}).click();await expect(page.getByText('Няма връзка. Отметките ще се запишат, щом се върне.')).toBeVisible();let prompts=0;let allowExit=false;page.on('dialog',async d=>{prompts++;if(allowExit)await d.accept();else await d.dismiss();});
 await page.getByRole('link',{name:'Моят график',exact:true}).click();await expect(page).toHaveURL(new RegExp(`/attendance/${id(10)}`));expect(prompts).toBe(1);
 await page.evaluate(()=>window.history.back());await expect.poll(()=>prompts).toBe(2);await expect(page).toHaveURL(new RegExp(`/attendance/${id(10)}`));await expect(page.getByRole('group',{name:'Ана Иванова'}).getByRole('button',{name:'✓ Присъства',exact:true})).toHaveAttribute('aria-pressed','true');expect(f.writes.length).toBeGreaterThan(0);allowExit=true;await page.getByRole('link',{name:'Моят график',exact:true}).click();await expect(page).toHaveURL(/dashboard\/schedule/);expect(prompts).toBe(3);
});
test('a slow background refresh does not erase a topic draft',async({page})=>{
 const f=await fixture(page);await page.clock.install({time:new Date('2026-11-02T15:40:00Z')});await page.goto(`/dashboard/attendance/${id(10)}`);const topic=page.getByLabel('Тема на заниманието (по избор)');await expect(topic).toBeVisible();
 let release:()=>void=()=>{};let arrived=false;const wait=new Promise<void>(r=>release=r);const stale=structuredClone(f.current);
 await page.route(`**/attendance/sessions/${id(10)}`,async route=>{arrived=true;await wait;await route.fulfill({json:stale});});
 await page.clock.fastForward(30_000);await expect.poll(()=>arrived).toBe(true);await topic.fill('Нова тема, която още пиша');release();await page.waitForTimeout(100);await expect(topic).toHaveValue('Нова тема, която още пиша');await page.clock.fastForward(900);await expect.poll(()=>f.writes.length).toBe(1);expect(JSON.parse(f.writes[0].body)).toEqual({action:'topic',topic:'Нова тема, която още пиша'});
});

test('Back leaves a clean attendance page without a confirmation',async({page})=>{
 await fixture(page);await page.goto('/dashboard');await page.getByRole('link',{name:'Отбележи присъствията',exact:true}).click();await expect(page.getByRole('heading',{name:'Не се очакват днес'})).toBeVisible();page.on('dialog',async d=>{await d.dismiss();throw new Error('Clean page must not ask to discard changes');});await page.evaluate(()=>window.history.back());await expect(page).toHaveURL(/\/dashboard$/);
});
