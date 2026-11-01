import {expect,test} from '@playwright/test';
import {fixture,layout} from './attendance-fixture';
const id=(n:number)=>`00000000-0000-4000-8000-${String(n).padStart(12,'0')}`;
for(const width of [390,768,1440])test(`marking stays over the schedule with its filters and focus at ${width}px`,async({page})=>{
 await page.setViewportSize({width,height:1000});const f=await fixture(page,{office:true});const url=`/dashboard/schedule?view=cards&date=2026-11-02&group=${id(4)}`;
 await page.goto(url);const card=page.locator('[data-session-id]').first();await card.click();const panel=page.getByRole('dialog',{name:/Група 2/});const opener=panel.getByRole('button',{name:/^(Отбележи присъствия|Прегледай присъствията)$/});await opener.click();const modal=page.getByRole('dialog',{name:'Присъствия за заниманието',exact:true});await expect(modal.getByRole('group',{name:'Ана Иванова'})).toBeVisible();await expect(page).toHaveURL(new RegExp('group='+id(4)));const before=page.url();await layout(page);
 await page.screenshot({path:test.info().outputPath(`modal-${width}.png`)});
 await modal.getByRole('group',{name:'Ана Иванова'}).getByRole('button',{name:'Присъства',exact:true}).click();await expect(modal.getByText('Записано: Ана Иванова — Присъства',{exact:true})).toBeVisible();await modal.getByRole('button',{name:'Отмени',exact:true}).click();await expect.poll(()=>f.writes.length).toBe(2);expect(JSON.parse(f.writes[1].body).marks[0]).toMatchObject({status:null,version:1});
 await modal.getByRole('button',{name:'✓ Всички присъстват',exact:true}).click();await expect(modal.getByText('Записано: 2 деца — Присъства',{exact:true})).toBeVisible();await modal.getByRole('button',{name:'Готово',exact:true}).click();await expect(modal).not.toBeVisible();await expect(opener).toBeFocused();await expect(opener).toHaveText('Прегледай присъствията');expect(page.url()).toBe(before);await expect(panel).toContainText('Присъстваха 2');
 await opener.click();await expect(modal.getByText('✓ Всички деца са отбелязани',{exact:true})).toBeVisible();await page.keyboard.press('Escape');await expect(modal).not.toBeVisible();await expect(opener).toBeFocused();await page.keyboard.press('Escape');await expect(panel).not.toBeVisible();await expect(card).toBeFocused();expect(f.errors).toEqual([]);
});
for(const width of [390,768,1440])test(`daily attendance and month are separate useful views at ${width}px`,async({page})=>{
 await page.setViewportSize({width,height:1000});const requested:string[]=[];page.on('request',r=>requested.push(new URL(r.url()).pathname));const f=await fixture(page,{office:true});await page.goto('/dashboard/attendance');await expect(page.getByRole('link',{name:'За отбелязване',exact:true})).toHaveAttribute('aria-current','page');await expect(page.getByRole('heading',{name:'Шахмат · Група 2',exact:true})).toBeVisible();await layout(page);expect(requested.filter(x=>x.endsWith('/attendance/month'))).toHaveLength(0);await page.screenshot({path:test.info().outputPath(`daily-${width}.png`),fullPage:true});
 const article=page.getByRole('article').filter({has:page.getByRole('heading',{name:'Шахмат · Група 2',exact:true})});const button=article.getByRole('button',{name:'Отбележи присъствия',exact:true});await button.click();const modal=page.getByRole('dialog',{name:'Присъствия за заниманието',exact:true});await expect(modal.getByRole('group',{name:'Ана Иванова'})).toBeVisible();await page.keyboard.press('Escape');await expect(button).toBeFocused();
 await page.getByRole('link',{name:'Месечен преглед',exact:true}).click();await expect(page.getByRole('table')).toBeVisible();await expect(page.getByRole('link',{name:'Месечен преглед',exact:true})).toHaveAttribute('aria-current','page');await expect(page.getByText('Как да чета таблицата?',{exact:true})).toBeVisible();await expect(page.getByText(/В процента влизат само/)).not.toBeVisible();await layout(page);await page.screenshot({path:test.info().outputPath(`month-${width}.png`),fullPage:true});await page.reload();await expect(page.getByRole('table')).toBeVisible();expect(f.errors).toEqual([]);
});
test('modal Escape, close and Back warn while marks are offline',async({page})=>{
 const f=await fixture(page,{office:true,offline:true,offlineFailures:100});await page.goto('/dashboard/schedule?view=cards&date=2026-11-02');await page.locator('[data-session-id]').first().click();const opener=page.getByRole('button',{name:'Отбележи присъствия',exact:true});await opener.click();const modal=page.getByRole('dialog',{name:'Присъствия за заниманието',exact:true});await modal.getByRole('group',{name:'Ана Иванова'}).getByRole('button',{name:'Присъства',exact:true}).click();await expect(modal.getByText('Няма връзка. Отметките ще се запишат, щом се върне.')).toBeVisible();let count=0;let accept=false;page.on('dialog',async d=>{count++;if(accept)await d.accept();else await d.dismiss();});
 await page.keyboard.press('Escape');expect(count).toBe(1);await expect(modal).toBeVisible();await modal.getByRole('button',{name:'Затвори присъствията'}).click();expect(count).toBe(2);await page.evaluate(()=>window.history.back());await expect.poll(()=>count).toBe(3);await expect(modal).toBeVisible();await expect(modal.getByRole('group',{name:'Ана Иванова'}).getByRole('button',{name:'✓ Присъства',exact:true})).toHaveAttribute('aria-pressed','true');accept=true;await modal.getByRole('button',{name:'Готово',exact:true}).click();await expect(modal).not.toBeVisible();await expect(opener).toBeFocused();expect(page.url()).toContain('/dashboard/schedule?view=cards&date=2026-11-02');expect(f.writes.length).toBeGreaterThan(0);
});
test('read-only session remains read-only inside the modal',async({page})=>{
 await fixture(page,{office:true,readOnly:true});await page.goto('/dashboard/schedule?view=cards&date=2026-11-02');await page.locator('[data-session-id]').first().click();await page.getByRole('button',{name:'Прегледай присъствията',exact:true}).click();const modal=page.getByRole('dialog',{name:'Присъствия за заниманието',exact:true});await expect(modal.getByText('Само преглед',{exact:true})).toBeVisible();await expect(modal.getByRole('button',{name:'Присъства',exact:true})).toHaveCount(0);await expect(modal.getByRole('button',{name:'Поправи',exact:true})).toHaveCount(0);
});

for(const width of [390,1440])test(`long roster scrolls inside the modal with visible header and Done at ${width}px`,async({page})=>{
 await page.setViewportSize({width,height:1000});const f=await fixture(page,{office:true});for(let i=0;i<8;i++)f.current.children.push({...f.current.children[0],enrollment_id:id(900+i),child_id:id(950+i),name:`Демо дете ${i+1}`});f.current.counts.expected+=8;f.current.counts.unmarked+=8;
 await page.goto('/dashboard/schedule?view=cards&date=2026-11-02');await page.locator('[data-session-id]').first().click();await page.getByRole('button',{name:'Отбележи присъствия',exact:true}).click();const modal=page.getByRole('dialog',{name:'Присъствия за заниманието',exact:true});await expect(modal.locator('[role="group"]')).toHaveCount(12);await expect(modal.getByText('Само неотбелязаните · Как работи?',{exact:true})).toBeVisible();await expect(modal.getByText('Записаните отсъствия, предупредените отсъствия',{exact:false})).not.toBeVisible();await page.screenshot({path:test.info().outputPath(`roster-${width}.png`)});await modal.getByRole('group',{name:'Николай Савов'}).getByRole('button',{name:'Присъства',exact:true}).scrollIntoViewIfNeeded();await expect(modal.getByRole('heading',{name:'Шахмат · Група 2 · 3.–4. клас',exact:true})).toBeInViewport();await expect(modal.getByRole('button',{name:'Готово',exact:true})).toBeInViewport();await layout(page);expect(f.errors).toEqual([]);
});

for(const width of [390,768,1440])test(`schedule panel shows protected individual statuses and refreshes after marking at ${width}px`,async({page})=>{
 await page.setViewportSize({width,height:1000});const f=await fixture(page,{office:true});
 await page.goto('/dashboard/schedule?view=cards&date=2026-11-02');await page.locator('[data-session-id]').first().click();const panel=page.getByRole('dialog',{name:/Група 2/});
 const ana=panel.getByRole('listitem').filter({hasText:'Ана Иванова'});
 await expect(ana.getByText('? Неотбелязано',{exact:true})).toBeVisible();
 await expect(panel.getByText('Предупредено отсъствие',{exact:true})).toBeVisible();
 await expect(panel.getByText('В пауза',{exact:true})).toBeVisible();
 await panel.getByRole('button',{name:'Отбележи присъствия',exact:true}).click();const modal=page.getByRole('dialog',{name:'Присъствия за заниманието',exact:true});
 await modal.getByRole('group',{name:'Ана Иванова'}).getByRole('button',{name:'Присъства',exact:true}).click();
 await expect(modal.locator('footer').getByRole('status')).toHaveText('Всичко е записано');
 await modal.getByRole('button',{name:'Готово',exact:true}).click();await expect(ana.getByText('✓ Присъства',{exact:true})).toBeVisible();
 await layout(page);await page.screenshot({path:test.info().outputPath(`panel-status-${width}.png`)});expect(f.errors).toEqual([]);
});
test('a visible roster alone never fetches or reveals attendance without its permission',async({page})=>{
 const requests:string[]=[];page.on('request',r=>requests.push(new URL(r.url()).pathname));await fixture(page,{office:true,panelAttendance:false});
 await page.goto('/dashboard/schedule?view=cards&date=2026-11-02');await page.locator('[data-session-id]').first().click();const panel=page.getByRole('dialog',{name:/Група 2/});
 await expect(panel.getByText('Ана Иванова',{exact:true})).toBeVisible();await expect(panel.getByText('? Неотбелязано',{exact:true})).toHaveCount(0);
 expect(requests.filter(p=>p.includes('/attendance/sessions/'))).toEqual([]);
});
test('failed sheet access leaves the roster usable without individual marks',async({page})=>{
 await fixture(page,{office:true,sheetForbidden:true});await page.goto('/dashboard/schedule?view=cards&date=2026-11-02');await page.locator('[data-session-id]').first().click();const panel=page.getByRole('dialog',{name:/Група 2/});
 await expect(panel.getByText('Присъствията не се заредиха.',{exact:false})).toBeVisible();await expect(panel.getByText('Ана Иванова',{exact:true})).toBeVisible();await expect(panel.getByText('? Неотбелязано',{exact:true})).toHaveCount(0);
});
test('footer keeps offline save feedback visible while the list scrolls',async({page})=>{
 await page.setViewportSize({width:390,height:800});await fixture(page,{office:true,offline:true,offlineFailures:100});await page.goto('/dashboard/attendance');await page.getByRole('article').first().getByRole('button',{name:'Отбележи присъствия',exact:true}).click();const modal=page.getByRole('dialog',{name:'Присъствия за заниманието',exact:true});
 await modal.getByRole('group',{name:'Ана Иванова'}).getByRole('button',{name:'Присъства',exact:true}).click();const status=modal.locator('footer').getByRole('status');await expect(status).toHaveText('Няма връзка · има незаписани промени');
 await modal.getByText('Тема и история на заниманието',{exact:true}).scrollIntoViewIfNeeded();await expect(status).toBeInViewport();await layout(page);await page.screenshot({path:test.info().outputPath('offline-footer-390.png')});
});
test('dirty topic is not announced as saved and a refused save stays visibly unsaved',async({page})=>{
 await fixture(page,{office:true,forbidden:true});await page.goto('/dashboard/attendance');await page.getByRole('article').first().getByRole('button',{name:'Отбележи присъствия',exact:true}).click();const modal=page.getByRole('dialog',{name:'Присъствия за заниманието',exact:true});
 await modal.getByText('Тема и история на заниманието',{exact:true}).click();await modal.getByLabel('Тема на заниманието (по избор)').fill('Нова тема');await expect(modal.locator('footer').getByRole('status')).toHaveText('Има незаписани промени');
 await expect(modal.locator('footer').getByRole('status')).toHaveText('Не е записано');
});

for(const width of [390,768,1440])test(`compact marking explains bulk behavior on demand at ${width}px`,async({page})=>{
 await page.setViewportSize({width,height:900});await fixture(page,{office:true,scheduleManage:true});await page.goto('/dashboard/schedule?view=cards&date=2026-11-02');await page.locator('[data-session-id]').first().click();const panel=page.getByRole('dialog',{name:/Група 2/});
 await expect(panel.getByText('„Премести“ и „Отмени“ са достъпни до 15 минути преди началото.',{exact:true})).toBeVisible();await expect(panel.getByRole('button',{name:'Премести',exact:true})).toHaveCount(0);
 const action=panel.getByRole('button',{name:'Отбележи присъствия',exact:true});await action.click();const modal=page.getByRole('dialog',{name:'Присъствия за заниманието',exact:true});
 const help=modal.getByText('Само неотбелязаните · Как работи?',{exact:true});const details=modal.getByText('Записаните отсъствия, предупредените отсъствия',{exact:false});await expect(details).not.toBeVisible();await expect(modal.getByText('Предупредено отсъствие · Родителят предупреди',{exact:false})).toBeVisible();
 await layout(page);await page.screenshot({path:test.info().outputPath(`compact-${width}.png`)});await help.focus();await page.keyboard.press('Enter');await expect(details).toBeVisible();await page.keyboard.press('Enter');await expect(details).not.toBeVisible();await page.keyboard.press('Escape');await expect(action).toBeFocused();
});
test('completed schedule attendance opens for inspection and unauthorized users see no cutoff hint',async({page})=>{
 await fixture(page,{office:true,complete:true});await page.goto('/dashboard/schedule?view=cards&date=2026-11-02');await page.locator('[data-session-id]').first().click();const panel=page.getByRole('dialog',{name:/Група 2/});await expect(panel.getByText('„Премести“ и „Отмени“ са достъпни до 15 минути преди началото.',{exact:true})).toHaveCount(0);await expect(panel.getByRole('button',{name:'Отбележи присъствия',exact:true})).toHaveCount(0);await panel.getByRole('button',{name:'Прегледай присъствията',exact:true}).click();const modal=page.getByRole('dialog',{name:'Присъствия за заниманието',exact:true});await expect(modal.getByText('✓ Всички деца са отбелязани',{exact:true})).toBeVisible();await expect(modal.getByRole('button',{name:'Поправи',exact:true})).toBeVisible();
});

for (const width of [390, 768, 1440]) {
  test(`review statuses share a consistent column at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 1000 }); await fixture(page, { office: true, complete: true, readOnly: true });
    await page.goto('/dashboard/schedule?view=cards&date=2026-11-02'); await page.locator('[data-session-id]').first().click(); await page.getByRole('button', { name: 'Прегледай присъствията', exact: true }).click();
    const modal = page.getByRole('dialog', { name: 'Присъствия за заниманието', exact: true });
    for (const [name, status] of [['Ана Иванова', '✓ Присъства'], ['Калоян Петков', '✕ Предупредено отсъствие']]) {
      const row = modal.getByRole('group', { name, exact: true }); const label = row.getByText(status, { exact: true }); await expect(label).toBeVisible();
      const placement = await label.evaluate(el => { const r = el.getBoundingClientRect(); const row = el.parentElement!.getBoundingClientRect(); return { left: r.left, right: r.right, top: r.top, rowLeft: row.left, rowRight: row.right }; });
      if (width < 560) { const eye = await row.getByRole('button', { name: `Виж ${name}`, exact: true }).boundingBox(); expect(placement.top).toBeGreaterThanOrEqual(eye!.y + eye!.height); expect(Math.abs(placement.left - placement.rowLeft)).toBeLessThanOrEqual(1); }
      else { expect(Math.abs(placement.right - placement.rowRight)).toBeLessThanOrEqual(1); }

    }
    await expect(modal.getByText('✓ Всички деца са отбелязани', { exact: true })).toBeVisible(); await layout(page); await page.screenshot({ path: test.info().outputPath(`review-aligned-${width}.png`) });
  });
  test(`incomplete review does not signal success at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 1000 }); await fixture(page, { office: true, readOnly: true });
    await page.goto('/dashboard/schedule?view=cards&date=2026-11-02'); await page.locator('[data-session-id]').first().click(); await page.getByRole('button', { name: 'Прегледай присъствията', exact: true }).click();
    const modal = page.getByRole('dialog', { name: 'Присъствия за заниманието', exact: true }); const summary = modal.getByText('Има неотбелязани деца', { exact: true }); await expect(summary).toBeVisible();
    const colors = await summary.evaluate(el => { const actual = getComputedStyle(el.parentElement!).backgroundColor; const probe = document.createElement('span'); probe.style.color = 'var(--status-available-soft)'; el.append(probe); const success = getComputedStyle(probe).color; probe.remove(); return { actual, success }; });
    expect(colors.actual).not.toBe(colors.success); await expect(modal.getByText('Неотбелязани 2', { exact: true })).toHaveCount(1); await layout(page); await page.screenshot({ path: test.info().outputPath(`review-pending-${width}.png`) });
  });
}
