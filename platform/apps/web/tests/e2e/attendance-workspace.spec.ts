import {expect,test} from '@playwright/test';
import {fixture,layout} from './attendance-fixture';

for(const width of [390,768,1440])test(`child stays in the attendance window with saved marks at ${width}px`,async({page})=>{
 await page.setViewportSize({width,height:900}); const f=await fixture(page,{office:true});
 await page.goto('/dashboard/attendance'); await expect(page.getByRole('link',{name:'Към графика →'})).toBeVisible();
 await layout(page); await page.screenshot({path:test.info().outputPath(`workspace-${width}.png`),fullPage:true});
 await page.getByRole('article').first().getByRole('button',{name:'Отбележи присъствия',exact:true}).click();
 const modal=page.getByRole('dialog',{name:'Присъствия за заниманието',exact:true});
 const ana=modal.getByRole('group',{name:'Ана Иванова',exact:true});
 await ana.getByRole('button',{name:'Присъства',exact:true}).click(); await expect(modal.locator('footer').getByRole('status')).toHaveText('Всичко е записано');
 if(width>=560) { const columns=await modal.getByRole('group').evaluateAll(rows=>rows.map(row=>row.querySelector('button[aria-pressed]')?.getBoundingClientRect().left).filter(x=>x!==undefined)); expect(Math.max(...columns)-Math.min(...columns)).toBeLessThanOrEqual(1); }
 await page.screenshot({path:test.info().outputPath(`marking-${width}.png`)});
 const eye=ana.getByRole('button',{name:'Виж Ана Иванова',exact:true});await eye.click();
 await expect(modal.getByRole('heading',{name:'Ана Иванова',exact:true})).toBeFocused();
 await expect(page.locator('dialog[open]')).toHaveCount(1);await expect(ana).not.toBeVisible();
 await expect(modal.getByText('4 от 5 · 80 %')).toBeVisible();
 await expect(modal.getByRole('button',{name:/Покажи телефона/})).toHaveCount(0);
 await layout(page);await page.screenshot({path:test.info().outputPath(`child-${width}.png`)});
 await modal.getByRole('button',{name:'← Назад към присъствията',exact:true}).click();
 await expect(eye).toBeFocused();await expect(ana.getByRole('button',{name:'✓ Присъства',exact:true})).toHaveAttribute('aria-pressed','true');
 await eye.click();await page.keyboard.press('Escape');await expect(eye).toBeFocused();await expect(modal).toBeVisible();
 await eye.click();await page.evaluate(()=>history.back());await expect(eye).toBeFocused();await expect(modal).toBeVisible();
 await page.keyboard.press('Escape');await expect(modal).not.toBeVisible();expect(f.errors).toEqual([]);
});

test('offline queue, topic draft and roster position survive a child visit',async({page})=>{
 await page.setViewportSize({width:390,height:800});const f=await fixture(page,{office:true,offline:true,offlineFailures:100});
 for(let i=0;i<12;i++)f.current.children.push({...f.current.children[0],enrollment_id:`demo-${i}`,name:`Демо дете ${i}`});
 await page.goto('/dashboard/attendance');await page.getByRole('article').first().getByRole('button',{name:'Отбележи присъствия',exact:true}).click();
 const modal=page.getByRole('dialog',{name:'Присъствия за заниманието',exact:true});
 await modal.getByRole('group',{name:'Ана Иванова',exact:true}).getByRole('button',{name:'Присъства',exact:true}).click();
 await modal.getByText('Тема и история на заниманието',{exact:true}).click();await modal.getByLabel('Тема на заниманието (по избор)').fill('Запазена тема');
 const eye=modal.getByRole('button',{name:'Виж Демо дете 11',exact:true});await eye.scrollIntoViewIfNeeded();
 // Capture the actual scroll container, not the page or modal shell.
 const before=await eye.evaluate(el=>{let p=el.parentElement;while(p&&getComputedStyle(p).overflowY!=='auto')p=p.parentElement;return p?.scrollTop??0;});expect(before).toBeGreaterThan(0);
 await eye.click();await expect(modal.getByRole('heading',{name:'Ана Иванова',exact:true})).toBeVisible();
 await expect(modal.locator('footer').getByRole('status')).toHaveText('Няма връзка · има незаписани промени');
 await page.keyboard.press('Escape');await expect(eye).toBeFocused();
 const after=await eye.evaluate(el=>{let p=el.parentElement;while(p&&getComputedStyle(p).overflowY!=='auto')p=p.parentElement;return p?.scrollTop??0;});expect(Math.abs(after-before)).toBeLessThanOrEqual(1);
 await expect(modal.getByLabel('Тема на заниманието (по избор)')).toHaveValue('Запазена тема');
 await expect(modal.getByRole('group',{name:'Ана Иванова',exact:true}).getByRole('button',{name:'✓ Присъства',exact:true})).toHaveAttribute('aria-pressed','true');
 let warned=false;page.once('dialog',async d=>{warned=true;await d.dismiss();});await page.keyboard.press('Escape');await expect.poll(()=>warned).toBe(true);await expect(modal).toBeVisible();expect(f.writes.length).toBeGreaterThan(0);expect(f.errors).toEqual([]);
});

test('child loading error can retry or return inside the same window',async({page})=>{
 await fixture(page,{office:true});let fail=true;
 await page.route('**/backend/**/enrollment/children/**',async route=>{if(fail)return route.fulfill({status:503,json:{message:'Картата не се зареди.'}});return route.fallback();});
 await page.goto('/dashboard/attendance');await page.getByRole('article').first().getByRole('button',{name:'Отбележи присъствия',exact:true}).click();
 const modal=page.getByRole('dialog',{name:'Присъствия за заниманието',exact:true});const eye=modal.getByRole('button',{name:'Виж Ана Иванова',exact:true});await eye.click();
 await expect(modal.getByRole('alert')).toBeVisible();await expect(page.locator('dialog[open]')).toHaveCount(1);
 fail=false;await modal.getByRole('button',{name:'Опитай пак',exact:true}).click();await expect(modal.getByText('4 от 5 · 80 %')).toBeVisible();
 await modal.getByRole('button',{name:'Назад към списъка',exact:true}).click();await expect(eye).toBeFocused();
});
