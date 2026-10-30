import { expect, test, type Page } from '@playwright/test';
import type { ActivityGroup, OpenDayCampaign, VisitOffer } from '../../src/features/catalog/admin/api';

const session = { id: 'session-1', starts_at: '2035-11-01T14:00:00Z', ends_at: '2035-11-01T15:00:00Z', status: 'scheduled' };
const group: ActivityGroup = { id: 'group-1', offering_id: 'offering-1', version: 1, name: 'Начинаещи', age_label: '6–8 г.', status: 'published', capacity_min: 1, capacity_max: 12, instructor_ids: [], schedule_pattern: { timezone: 'Europe/Sofia', frequency: 'weekly' }, price_options: [], sessions: [session], conflicts: [] };
const offer: VisitOffer = { id: 'offer-1', activity_id: 'activity-1', group_id: group.id, campaign_id: null, type: 'open_day', title: 'Едно посещение', description: 'Описание', audience_age_label: '6–8 г.', audience_class_label: null, visit_limit: 3, fee_type: 'paid', amount_cents: 1250, currency: 'EUR', fee_reason: 'Материали', booking_deadline_hours: 12, waitlist_enabled: true, status: 'draft', version: 4, sessions: [session], published_at: null, closed_at: null, cancelled_at: null };
const visitTerms = { kind: 'visit', rules: { cancellation: { tiers: [{ min_hours: 24, kept: 0 }, { min_hours: 0, kept: 100 }] }, no_show_kept: 100, refund: 'money' } };
const termsWorkspace = {
  payment_terms: [], in_marketplace: false, can_manage: true, schema_version: 1, usage: {},
  billing_kinds: [{ value: 'visit', label: 'Посещение', presets: Object.fromEntries((['flexible', 'standard', 'strict'] as const).map((preset) => [preset, { label: { flexible: 'Гъвкави', standard: 'Стандартни', strict: 'Строги' }[preset], rules: visitTerms.rules, self_service: { absence: false, pause_weeks: 0, end: false, cancel_visit: true } }])) }],
};

async function fixture(page: Page) {
  const state = { groups: [group], offers: [{ ...offer }], campaigns: [] as OpenDayCampaign[], coordinator: false, errors: {} as Record<string, string[]>, requested: [] as string[], writes: [] as Record<string, unknown>[], prices: [] as { enrollment_mode: string; unit: string; amount_cents: number; is_available: boolean }[] };
  const activity = () => ({ id: 'activity-1', title: 'Шахмат', age_label: '6–8 г.', slug: 'chess', status: 'published', version: 1, category: 'Шахмат', summary: 'Описание', sections: {}, offering: state.prices.length ? { id: 'offering-1', version: 1, payment_terms: {}, price_options: state.prices } : null });
  await page.route('**/backend/**', async (route) => {
    const path = new URL(route.request().url()).pathname;
    state.requested.push(path);
    let body: unknown = {};
    if (path.endsWith('/me')) body = { user: { id: 'user-1', name: 'Координатор', email: 'coordinator@example.test' } };
    else if (path.endsWith('/organizations')) body = { organizations: [{ id: 'org-1', name: 'Школа', slug: 'school', status: 'active', role: state.coordinator ? 'coordinator' : 'organization_owner', permissions: ['catalog.view', 'catalog.manage', 'catalog.publish'] }] };
    else if (path.endsWith('/invitations')) {
      if (state.coordinator) { await route.fulfill({ status: 403, json: { message: 'Forbidden' } }); return; }
      body = { invitations: [] };
    } else if (path.endsWith('/audit-events')) body = { events: [] };
    else if (path.endsWith('/open-day-campaigns')) body = { campaigns: state.campaigns };
    else if (path.endsWith('/groups')) body = { groups: state.groups };
    else if (path.endsWith('/payment-terms')) body = termsWorkspace;
    else if (path.endsWith('/activities/activity-1')) body = { activity: activity() };
    else if (path.endsWith('/activities')) body = { activities: [activity()] };
    else if (path.endsWith('/profile')) body = { profile: null };
    else if (path.endsWith('/locations')) body = { locations: [] };
    else if (path.endsWith('/instructors')) body = { instructors: [] };
    else if (path.includes('/visit-offers') && route.request().method() !== 'GET') {
      const input = route.request().postDataJSON(); state.writes.push(input);
      if (Object.keys(state.errors).length) { await route.fulfill({ status: 422, json: { message: 'Невалидни данни', errors: state.errors } }); return; }
      const saved = { ...(state.offers[0] ?? offer), ...input, version: (state.offers[0]?.version ?? 0) + 1 };
      state.offers = [saved]; body = { visit_offer: saved };
    } else if (path.endsWith('/visit-offers')) body = { visit_offers: state.offers };
    await route.fulfill({ json: body });
  });
  return state;
}

test('coordinator can open and save an offer without invitation permission', async ({ page }) => {
  const state = await fixture(page); state.coordinator = true;
  await page.goto('/dashboard/activities/activity-1/visit-offers?offer=offer-1');
  await expect(page.getByLabel('Публично заглавие')).toHaveValue('Едно посещение');
  await page.getByLabel('Публично заглавие').fill('Редактирано от координатор');
  await page.getByRole('button', { name: 'Запази черновата' }).click();
  await expect(page.getByRole('status')).toHaveText('Предложението е запазено.');
  expect(state.writes[0].version).toBe(4);
  expect(state.requested).not.toContain('/backend/api/v1/invitations');
  expect(state.requested).not.toContain('/backend/api/v1/audit-events');
});

test('group setup detour restores unfinished fields while normal dirty navigation remains protected', async ({ page }) => {
  const state = await fixture(page); state.groups = []; state.offers = []; state.coordinator = true;
  await page.goto('/dashboard/activities/activity-1/visit-offers');
  await page.getByLabel('Публично заглавие').fill('Незавършено предложение');
  await page.getByLabel('Описание (по желание)').fill('Текст преди създаването на група');
  await page.getByLabel('Подходящ клас').fill('I–II клас');
  await page.getByLabel('Вид посещение').selectOption('open_day');
  await page.getByRole('button', { name: '4. Цена и условия', exact: true }).click();
  await page.getByLabel('Цена за деня').selectOption('paid');
  await page.getByLabel('Цена в евро').fill('19,99');
  await page.getByLabel('Публично основание').fill('Материали');
  page.once('dialog', (dialog) => dialog.dismiss());
  await page.getByRole('link', { name: '← Редактор на дейността', exact: true }).click();
  await expect(page).toHaveURL(/visit-offers$/);
  await page.getByRole('button', { name: '2. Група и занятия', exact: true }).click();
  await page.getByRole('link', { name: 'Добавете група и график в редактора на дейността.' }).click();
  await expect(page).toHaveURL(/activities\/activity-1\?step=7$/);
  state.groups = [group];
  // Step 8 "Специални посещения" leads to the dates-only visits (docs/product/visits.md, т. 8).
  await page.getByRole('button', { name: 'Напред: Специални посещения', exact: true }).click();
  await page.getByRole('link', { name: 'Избери дати за посещения', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Група и занятия', exact: true })).toBeVisible();
  await page.getByRole('button', { name: '1. Вид и съдържание', exact: true }).click();
  await expect(page.getByLabel('Публично заглавие')).toHaveValue('Незавършено предложение');
  await expect(page.getByLabel('Описание (по желание)')).toHaveValue('Текст преди създаването на група');
  await expect(page.getByLabel('Подходящ клас')).toHaveValue('I–II клас');
  await page.getByRole('button', { name: '4. Цена и условия', exact: true }).click();
  await expect(page.getByLabel('Цена в евро')).toHaveValue('19,99');
  await expect(page.getByLabel('Публично основание')).toHaveValue('Материали');
  await page.getByRole('button', { name: '2. Група и занятия', exact: true }).click();
  await page.getByRole('combobox', { name: 'Група', exact: true }).selectOption('group-1');
  await page.getByRole('checkbox').check();
  await page.getByRole('button', { name: 'Запази черновата' }).click();
  await expect(page.getByRole('status')).toHaveText('Предложението е запазено.');
  expect(state.writes[0]).toMatchObject({ title: 'Незавършено предложение', description: 'Текст преди създаването на група', amount_cents: 1999, session_ids: ['session-1'] });
  expect(state.requested).not.toContain('/backend/api/v1/invitations');
});

test('cancelled campaign membership is explained and locked while content remains editable', async ({ page }) => {
  const state = await fixture(page);
  state.campaigns = [{ id: 'cancelled-1', title: 'Отменена кампания', slug: 'cancelled', description: null, starts_on: null, ends_on: null, image_path: null, status: 'cancelled', version: 2, published_at: null, visit_offers: [{ id: offer.id, title: offer.title, status: offer.status, type: offer.type }] }];
  state.offers[0].campaign_id = 'cancelled-1';
  await page.goto('/dashboard/activities/activity-1/visit-offers?offer=offer-1');
  await expect(page.getByRole('combobox', { name: /Кампания/ })).toBeDisabled();
  await expect(page.getByText(/Кампанията е отменена/)).toBeVisible();
  await page.getByLabel('Публично заглавие').fill('Ново публично заглавие');
  await page.getByRole('button', { name: 'Запази черновата' }).click();
  await expect(page.getByRole('status')).toHaveText('Предложението е запазено.');
  expect(state.requested.filter((path) => /open-day-campaigns\//.test(path))).toHaveLength(0);
  await expect(page.getByText(/променени от друг служител/)).toHaveCount(0);
});

test('protected-field validation summaries open and focus each invalid control', async ({ page }) => {
  const state = await fixture(page); state.offers[0].status = 'published';
  const fields = ['type', 'fee_type', 'booking_deadline_hours', 'waitlist_enabled'];
  state.errors = Object.fromEntries(fields.map((key) => [key, [`Защитено поле ${key}.`]]));
  await page.goto('/dashboard/activities/activity-1/visit-offers?offer=offer-1');
  await page.getByRole('button', { name: 'Запази промените' }).click();
  for (const key of fields) {
    await page.getByRole('button', { name: '5. Преглед и публикуване', exact: true }).click();
    await page.getByRole('button', { name: `Защитено поле ${key}.`, exact: true }).click();
    const input = page.locator(`#visit-field-${key}`);
    await expect(input).toBeVisible();
    await expect(input).toBeFocused();
    await expect(input).toHaveAttribute('aria-invalid', 'true');
    await expect(input).toHaveAttribute('aria-describedby', `error-${key}`);
    await expect(page.locator(`#error-${key}`)).toBeVisible();
  }
});

test('published multi-date content can be saved with retained historical sessions', async ({ page }) => {
  const state = await fixture(page);
  const past = { ...session, id: 'past-session', starts_at: '2025-11-01T14:00:00Z', ends_at: '2025-11-01T15:00:00Z' };
  state.groups = [{ ...group, sessions: [past, session] }];
  state.offers = [{ ...offer, status: 'published', sessions: [past, session] }];
  await page.goto('/dashboard/activities/activity-1/visit-offers?offer=offer-1');
  await page.getByLabel('Публично заглавие').fill('Поправено заглавие след първото посещение');
  await page.getByRole('button', { name: 'Запази промените' }).click();
  await expect(page.getByRole('status')).toHaveText('Предложението е запазено.');
  expect(state.writes[0]).toMatchObject({ version: 4, session_ids: ['past-session', 'session-1'] });
});

test('a trial on chosen dates shows its step-6 price and visit terms, and cannot be published without that price', async ({ page }) => {
  const state = await fixture(page);
  state.offers = [{ ...offer, type: 'trial', fee_type: 'free', amount_cents: 0, fee_reason: null }];
  await page.goto('/dashboard/activities/activity-1/visit-offers?offer=offer-1');
  await page.getByRole('button', { name: '4. Цена и условия', exact: true }).click();
  await expect(page.locator('#visit-step-3').getByText('Добавете цена „Пробно занимание“ в стъпка 6 „Цени и записване“.')).toBeVisible();
  await expect(page.getByLabel('Цена за деня')).toHaveCount(0);
  const priceStep = page.locator('#visit-step-3');
  await expect(priceStep.getByText('Условията на организацията — Стандартни')).toBeVisible();
  await expect(priceStep.getByText('Неявяване:')).toBeVisible();
  await expect(priceStep.getByText('Пробното е веднъж на дете за тази дейност', { exact: false })).toBeVisible();
  await page.getByRole('button', { name: '5. Преглед и публикуване', exact: true }).click();
  await expect(page.getByRole('alert').filter({ hasText: 'Поправете следното' })).toContainText('Добавете цена „Пробно занимание“');
  await expect(page.getByRole('button', { name: 'Публикувай предложението' })).toBeDisabled();

  state.prices = [{ enrollment_mode: 'trial', unit: 'session', amount_cents: 0, is_available: true }];
  await page.reload();
  await page.getByRole('button', { name: '4. Цена и условия', exact: true }).click();
  await expect(page.locator('#visit-step-3').getByText('Безплатно', { exact: true })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Промени цената' })).toHaveAttribute('href', '/dashboard/activities/activity-1?step=6');
  await page.getByRole('button', { name: 'Запази черновата' }).click();
  await expect(page.getByRole('status')).toHaveText('Предложението е запазено.');
  for (const field of ['fee_type', 'amount_cents', 'fee_reason', 'currency']) expect(state.writes[0]).not.toHaveProperty(field);
  await page.getByRole('button', { name: '5. Преглед и публикуване', exact: true }).click();
  await expect(page.getByRole('alert').filter({ hasText: 'Поправете следното' })).toHaveCount(0);
});
