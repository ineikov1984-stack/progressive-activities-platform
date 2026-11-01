import { expect, test, type Page } from '@playwright/test';
import type { BillingKind, BillingKindInfo, PaymentTermsSet, PaymentTermsWorkspace, Rules } from '../../src/features/payment-terms/api';

// The server's ready-made sets for two ways of paying (app/Domain/PaymentTerms/Rules/Presets.php).
const bySessions = { mode: 'by_sessions', cutoff_day: null, kept_until_cutoff: null, kept_after_cutoff: null };
const makeup = { enabled: true, within_days: 14, notice_hours: 3, per_month: null, only_if_space: false };
const monthly: Record<'flexible' | 'standard' | 'strict', Rules> = {
  flexible: {
    start: bySessions, end: { ...bySessions, notice_days: 0 },
    pause: { notice_days: 3, kept_on_time: 0, kept_late: 50, free_days_per_school_year: 92, document_required: false, min_days: 0 },
    absences: { tiers: [{ up_to: null, kept: 100 }] }, makeup: { ...makeup, within_days: 30 }, refund: 'parent_choice',
  },
  standard: {
    start: bySessions, end: { mode: 'cutoff', cutoff_day: 15, kept_until_cutoff: 50, kept_after_cutoff: 100, notice_days: 0 },
    pause: { notice_days: 7, kept_on_time: 0, kept_late: 50, free_days_per_school_year: 61, document_required: false, min_days: 0 },
    absences: { tiers: [{ up_to: 3, kept: 100 }, { up_to: null, kept: 50 }] }, makeup, refund: 'parent_choice',
  },
  strict: {
    start: { mode: 'cutoff', cutoff_day: 15, kept_until_cutoff: 100, kept_after_cutoff: 50 },
    end: { mode: 'full_month', cutoff_day: null, kept_until_cutoff: null, kept_after_cutoff: null, notice_days: 0 },
    pause: { notice_days: 0, kept_on_time: 50, kept_late: 50, free_days_per_school_year: 183, document_required: true, min_days: 21 },
    absences: { tiers: [{ up_to: null, kept: 100 }] }, makeup: { ...makeup, within_days: 7, notice_hours: 0, only_if_space: true }, refund: 'parent_choice',
  },
};
const self = {
  flexible: { absence: true, pause_weeks: 4, end: true, cancel_visit: true },
  standard: { absence: true, pause_weeks: 2, end: false, cancel_visit: true },
  strict: { absence: true, pause_weeks: 0, end: false, cancel_visit: true },
};
const visit = (tiers: Array<[number, number]>): Rules => ({ cancellation: { tiers: tiers.map(([min_hours, kept]) => ({ min_hours, kept })) }, no_show_kept: 100, refund: 'parent_choice' });

const otherKinds: BillingKindInfo[] = ([
 ['full_period','Цял период',{...monthly.standard,before_start:{tiers:[{min_days:7,kept:0,credit_offered:false},{min_days:0,kept:100,credit_offered:false}]},after_start:{full_refund_days:7,kept_of_remaining:50}}],
 ['package','Карта с посещения',{freeze_days_with_document:14,extension_days_on_request:7,refund:'parent_choice'}],
 ['day_camp','Дневен лагер',{before_start:{tiers:[{min_days:14,kept:0,credit_offered:false},{min_days:0,kept:100,credit_offered:true}]},absences:{kept_first_days:2,kept_after:50},week_change_free_until_days:3,refund:'parent_choice'}],
 ['residential_camp','Лагер с нощувки',{deposit:{mode:'percent',percent:25,amount_cents:null},before_start:{tiers:[{min_days:30,kept:0,credit_offered:false},{min_days:0,kept:100,credit_offered:false}]},illness_with_document:{kept:0,plus_costs:true},transfer_to_another_child_until_days:7,early_departure_kept:100,refund:'parent_choice'}],
 ['kindergarten','Детска градина',{notice_days:30,deposit_months:1,meals_by_attendance:true,sick_over_working_days:10,sick_next_month_kept:50,refund:'parent_choice'}],
] as [BillingKind,string,Rules][]).map(([value,label,rules])=>({value,label,presets:{flexible:{label:'Гъвкави',rules:{...rules,refund:'money'},self_service:self.flexible},standard:{label:'Стандартни',rules,self_service:self.standard},strict:{label:'Строги',rules:{...rules,refund:'credit'},self_service:self.strict}}}));

function workspace(canManage: boolean, saved: PaymentTermsSet[] = []): PaymentTermsWorkspace {
  return {
    payment_terms: saved,
    billing_kinds: [
      { value: 'monthly', label: 'Месечна такса', presets: {
        flexible: { label: 'Гъвкави', rules: monthly.flexible, self_service: self.flexible },
        standard: { label: 'Стандартни', rules: monthly.standard, self_service: self.standard },
        strict: { label: 'Строги', rules: monthly.strict, self_service: self.strict },
      } },
      { value: 'visit', label: 'Пробно занимание или еднократно посещение', presets: {
        flexible: { label: 'Гъвкави', rules: visit([[12, 0], [0, 50]]), self_service: self.flexible },
        standard: { label: 'Стандартни', rules: visit([[24, 0], [12, 50], [0, 100]]), self_service: self.standard },
        strict: { label: 'Строги', rules: visit([[48, 0], [0, 100]]), self_service: self.strict },
      } },
      ...otherKinds,
    ],
    in_marketplace: false,
    usage: { monthly: ['Футбол (6–8 г.)'] },
    can_manage: canManage,
    schema_version: 1,
  };
}

async function fixture(page: Page, options: { canManage?: boolean; reject?: boolean; loadError?: boolean } = {}) {
  const writes: Array<{ method: string; body: Record<string, unknown> }> = [];
  await page.route('**/backend/**', async (route) => {
    const path = new URL(route.request().url()).pathname;
    const method = route.request().method();
    if (path.endsWith('/me')) return route.fulfill({ json: { user: { id: 'user-1', name: 'Мария Петрова', email: 'owner@example.test' } } });
    if (path.endsWith('/organizations')) return route.fulfill({ json: { organizations: [{ id: 'org-1', name: 'Школа', slug: 'school', status: 'active', role: options.canManage === false ? 'viewer' : 'organization_owner', permissions: ['view', 'catalog.view'] }] } });
    if (path.endsWith('/payment-terms') && method === 'GET') return options.loadError ? route.fulfill({status:503,json:{message:'Условията не се заредиха.'}}) : route.fulfill({ json: workspace(options.canManage !== false) });
    if (path.includes('/payment-terms') && method !== 'GET') {
      const body = route.request().postDataJSON() as Record<string, unknown>;
      writes.push({ method, body });
      if (options.reject) {
        return route.fulfill({ status: 422, json: { message: 'The given data was invalid.', errors: { 'rules.end.cutoff_day': ['„ден от месеца“ трябва да е от 1 до 28.'] } } });
      }
      const set: PaymentTermsSet = {
        id: 'terms-1', billing_kind: 'monthly', billing_kind_label: 'Месечна такса', name: String(body.name), preset: 'custom', preset_label: 'Собствени',
        is_default: true, status: 'active', version: 1, self_service: body.self_service as PaymentTermsSet['self_service'],
        current_version: { id: 'version-1', number: 1, schema_version: 1, rules: body.rules as Rules, created_at: '2026-10-30T12:00:00Z' },
        marketplace_violations: {},
      };
      return route.fulfill({ status: 201, json: { payment_terms: set } });
    }
    return route.fulfill({ json: {} });
  });
  return writes;
}

test('the owner starts from "Стандартни", changes a number and saves the organization\'s terms', async ({ page }) => {
  const writes = await fixture(page);
  await page.goto('/dashboard/settings/payment-terms');

  await expect(page.getByRole('heading', { name: 'Такси, отсъствия и отказ', level: 1 })).toBeVisible();
  await expect(page.getByRole('radio', { name: /^Стандартни/ })).toBeChecked();
  await page.locator('summary').filter({hasText:'Преглед за родителя'}).click();
  await openRule(page, 'Ако детето спре по средата на месеца');
  await expect(page.getByText('Ако последният ден на детето в групата е до 15-о число включително, за този месец плащате половината от месечната такса. Ако е след 15-о число, плащате цялата такса.')).toBeVisible();
  await expect(page.getByText('Ползва се при: Футбол (6–8 г.)')).toBeVisible();
  await expect(page.getByText('Организацията още не е запазила свои условия за този начин на плащане — важат „Стандартни“.', { exact: false })).toBeVisible();

  await page.getByLabel('Ден от месеца').first().fill('10');
  await expect(page.getByRole('radio', { name: /^Собствени/ })).toBeChecked();
  await expect(page.getByText('е до 10-о число включително', { exact: false })).toBeVisible();

  await page.getByRole('radio', { name: /^Строги/ }).check();
  await expect(page.getByText('Ако прекратите записването, за месеца, в който е последният ден на детето, плащате цялата месечна такса.')).toBeVisible();
  await page.getByRole('radio', { name: /^Стандартни/ }).check();
  await openRule(page, 'Пауза — ваканция, болест, пътуване');
  await page.getByLabel('Дни предизвестие за пауза').fill('10');

  await page.getByRole('button', { name: 'Запази условията' }).click();
  await expect(page.getByText('Условията за „Месечна такса“ са запазени.')).toBeVisible();
  expect(writes).toHaveLength(1);
  expect(writes[0].method).toBe('POST');
  expect(writes[0].body).toMatchObject({ billing_kind: 'monthly', name: 'Условията на организацията', is_default: true, self_service: self.standard });
  expect((writes[0].body.rules as { pause: { notice_days: number } }).pause.notice_days).toBe(10);
});

test('a refused number is shown under its rule', async ({ page }) => {
  await fixture(page, { reject: true });
  await page.goto('/dashboard/settings/payment-terms');
  await openRule(page, 'Ако детето спре по средата на месеца');
  await page.getByLabel('Ден от месеца').first().fill('31');
  await openRule(page, 'Ако детето спре по средата на месеца');
  await page.getByRole('button', { name: 'Запази условията' }).click();

  await expect(page.getByText('Проверете отбелязаните полета.')).toBeVisible();
  await expect(page.getByLabel('Ден от месеца').first()).toBeFocused();
  await expect(page.getByRole('group', { name: 'Ако детето спре по средата на месеца' })).toContainText('„ден от месеца“ трябва да е от 1 до 28.');
});

test('a member who does not manage terms reads them without changing them', async ({ page }) => {
  await fixture(page, { canManage: false });
  await page.goto('/dashboard/settings/payment-terms');

  await expect(page.getByText('Само собственикът и администраторът могат да променят условията.', { exact: false })).toBeVisible();
  await openRule(page, 'Ако детето спре по средата на месеца');
  await expect(page.getByLabel('Ден от месеца').first()).toBeDisabled();
  await expect(page.getByRole('button', { name: 'Запази условията' })).toHaveCount(0);
});

for (const width of [390, 768, 1440]) {
  test(`fits ${width} px without sideways scrolling`, async ({ page }) => {
    await fixture(page);
    await page.setViewportSize({ width, height: 900 });
    await page.goto('/dashboard/settings/payment-terms');
    await expect(page.getByRole('heading', { name: 'Такси, отсъствия и отказ', level: 1 })).toBeVisible();

    await workspaceLayout(page);
    await page.screenshot({path:test.info().outputPath(`terms-${width}.png`),fullPage:true});
    await openRule(page, 'Пауза — ваканция, болест, пътуване');
    await page.locator('summary').filter({hasText:'Преглед за родителя'}).click();
    await workspaceLayout(page);
    await page.screenshot({path:test.info().outputPath(`terms-detail-${width}.png`),fullPage:true});
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(0);
    await expect(page.getByRole('button', { name: 'Запази условията' })).toBeVisible();
    // Every number field on screen is big enough to tap (folded ones have no size).
    const small = await page.locator('input[type="number"]').evaluateAll((inputs) => inputs.filter((input) => {
      const height = input.getBoundingClientRect().height;
      return height > 0 && height < 44;
    }).length);
    expect(small).toBe(0);
  });
}

async function openRule(page:Page,title:string){await page.locator('summary').filter({hasText:title}).click();}
async function workspaceLayout(page:Page){
 expect(await page.evaluate(()=>document.documentElement.scrollWidth-innerWidth)).toBeLessThanOrEqual(1);
 const small=await page.locator('button,a[href],input,select,summary').evaluateAll(nodes=>nodes.flatMap(el=>{if(el.getRootNode()!==document||!el.checkVisibility()||el.closest('[inert]'))return [];const label=el instanceof HTMLInputElement&&['radio','checkbox'].includes(el.type)?el.labels?.[0]:null;const r=(label??el).getBoundingClientRect();return r.width>0&&r.left<innerWidth&&r.right>0&&(r.width<43.5||r.height<43.5)?[{text:el.getAttribute('aria-label')??el.textContent,width:r.width,height:r.height}]:[];}));expect(small).toEqual([]);
}
test('switching payment kinds keeps each draft and custom values, sections and save are independent',async({page})=>{
 const writes=await fixture(page);await page.setViewportSize({width:390,height:1000});await page.goto('/dashboard/settings/payment-terms');
 await openRule(page,'Пауза — ваканция, болест, пътуване');await page.getByLabel('Дни предизвестие за пауза').fill('9');
 await page.getByRole('radio',{name:/^Строги/}).check();await page.getByRole('radio',{name:/^Собствени/}).check();await expect(page.getByLabel('Дни предизвестие за пауза')).toHaveValue('9');
 await page.getByRole('combobox',{name:'Начин на плащане'}).selectOption('visit');await expect(page.getByText('Още нямате дейност с такава цена.')).toBeVisible();await openRule(page,'Ако не дойде, без да откаже');await page.getByRole('radio',{name:/^Гъвкави/}).check();
 await page.getByRole('combobox',{name:'Начин на плащане'}).selectOption('monthly');await openRule(page,'Пауза — ваканция, болест, пътуване');await expect(page.getByLabel('Дни предизвестие за пауза')).toHaveValue('9');await page.getByRole('button',{name:'Разгъни всички',exact:true}).click();await expect(page.locator('details[data-rule-section]:not([open])')).toHaveCount(0);await workspaceLayout(page);await page.getByRole('button',{name:'Прибери всички',exact:true}).click();await expect(page.locator('details[data-rule-section][open]')).toHaveCount(0);await page.getByRole('button',{name:'Върни запазените',exact:true}).click();await openRule(page,'Пауза — ваканция, болест, пътуване');await expect(page.getByLabel('Дни предизвестие за пауза')).toHaveValue('7');expect(writes).toHaveLength(0);
});

test('all seven billing kinds keep their rule sections, preview and readable mobile controls',async({page})=>{
 const errors:string[]=[];page.on('pageerror',error=>errors.push(error.message));await fixture(page);await page.setViewportSize({width:390,height:1000});await page.goto('/dashboard/settings/payment-terms');const select=page.getByRole('combobox',{name:'Начин на плащане'});await expect(select.locator('option')).toHaveCount(7);
 for(const kind of ['monthly','visit',...otherKinds.map(item=>item.value)]){
  await select.selectOption(kind);await page.getByRole('button',{name:'Разгъни всички',exact:true}).click();await expect(page.locator('details[data-rule-section]:not([open])')).toHaveCount(0);expect(await page.getByRole('spinbutton').count()).toBeGreaterThan(0);await workspaceLayout(page);const preview=page.locator('summary').filter({hasText:'Преглед за родителя'});if(!await preview.evaluate(el=>(el.parentElement as HTMLDetailsElement).open))await preview.click();await expect(page.getByRole('heading',{name:'Така го четат родителите',exact:true})).toBeVisible();
 }expect(errors).toEqual([]);
});
test('loading error offers retry without losing the page context',async({page})=>{
 const options={loadError:true};await fixture(page,options);await page.goto('/dashboard/settings/payment-terms');await expect(page.getByRole('alert').filter({hasText:'Условията не се заредиха.'})).toBeVisible();options.loadError=false;await page.getByRole('button',{name:'Опитайте отново',exact:true}).click();await expect(page.getByRole('combobox',{name:'Начин на плащане'})).toBeVisible();
});
test('dirty save bar stays above mobile navigation and leaving asks before discarding edits',async({page})=>{
 await fixture(page);await page.setViewportSize({width:390,height:850});await page.goto('/dashboard/settings/payment-terms');await openRule(page,'Пауза — ваканция, болест, пътуване');await page.getByLabel('Дни предизвестие за пауза').fill('9');await page.getByLabel('Дни предизвестие за пауза').press('Tab');const save=page.getByRole('button',{name:'Запази условията',exact:true});await save.scrollIntoViewIfNeeded();expect(await save.evaluate(el=>el.getBoundingClientRect().bottom)).toBeLessThan(780);
 page.once('dialog',dialog=>dialog.dismiss());await page.getByRole('navigation',{name:'Основно меню на телефон'}).getByRole('link',{name:'Начало',exact:true}).click();await expect(page).toHaveURL(/settings\/payment-terms/);await expect(page.getByLabel('Дни предизвестие за пауза')).toHaveValue('9');
});
