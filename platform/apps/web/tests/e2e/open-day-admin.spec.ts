import { expect, test, type Page } from '@playwright/test';
import type { OpenDayCampaign, VisitOffer } from '../../src/features/catalog/admin/api';

const session = { id: 'session-1', starts_at: '2035-11-03T14:00:00Z', ends_at: '2035-11-03T15:00:00Z', status: 'scheduled' };
const initialOffer: VisitOffer = { id: 'offer-1', activity_id: 'activity-1', group_id: 'group-1', campaign_id: null, type: 'open_day', title: 'Запознай се с шахмата', description: 'Първа среща', audience_age_label: '6–8 г.', audience_class_label: null, visit_limit: 3, fee_type: 'free', amount_cents: 0, currency: 'EUR', fee_reason: null, booking_deadline_hours: 12, cancellation_deadline_hours: 24, cancellation_policy: null, cancellation_outcome: 'non_refundable', terms_version: '1', trial_limit_per_academic_year: 1, single_visit_limit: 2, single_visit_window_days: 30, unlimited_single_visits: false, no_show_trial_limit: null, waitlist_enabled: true, status: 'published', version: 4, sessions: [session], published_at: '2026-10-28T12:00:00Z', closed_at: null, cancelled_at: null };

async function fixture(page: Page) {
  const state = { campaigns: [] as OpenDayCampaign[], offers: [{ ...initialOffer }], writes: [] as { path: string; body: Record<string, unknown> }[], conflict: false };
  await page.route('**/backend/**', async (route) => {
    const path = new URL(route.request().url()).pathname;
    const method = route.request().method();
    let body: unknown = {};
    if (path.endsWith('/me')) body = { user: { id: 'user-1', name: 'Организатор', email: 'owner@example.test' } };
    else if (path.endsWith('/organizations')) body = { organizations: [{ id: 'org-1', name: 'Школа', slug: 'school', status: 'active', role: 'organization_owner', permissions: ['catalog.view', 'catalog.manage', 'catalog.publish'] }] };
    else if (path.includes('/open-day-campaigns') && method !== 'GET') {
      const input = route.request().postDataJSON(); state.writes.push({ path, body: input });
      if (state.conflict) { await route.fulfill({ status: 409, json: { message: 'Campaign version conflict.' } }); return; }
      const old = state.campaigns.at(0);
      if (old && input.version !== old.version) { await route.fulfill({ status: 409, json: { message: 'Wrong version' } }); return; }
      const transition = path.split('/').at(-1);
      const status = transition === 'publish' ? 'published' : transition === 'close' ? 'closed' : transition === 'cancel' ? 'cancelled' : old?.status ?? 'draft';
      if (input.visit_offer_ids) {
        for (const offer of state.offers) {
          const campaignId = input.visit_offer_ids.includes(offer.id) ? 'campaign-1' : null;
          if (campaignId !== offer.campaign_id) {
            expect(input.visit_offer_versions[offer.id]).toBe(offer.version);
            offer.campaign_id = campaignId; offer.version++;
          }
        }
      }
      const saved: OpenDayCampaign = { id: 'campaign-1', title: '', slug: 'autumn', description: null, starts_on: null, ends_on: null, image_path: null, published_at: null, ...old, ...input, status, version: (old?.version ?? 0) + 1, visit_offers: state.offers.filter((offer) => offer.campaign_id === 'campaign-1').map(({ id, title, type, status }) => ({ id, title, type, status })) };
      state.campaigns = [saved]; body = { campaign: saved };
    } else if (path.endsWith('/open-day-campaigns')) body = { campaigns: state.campaigns };
    else if (path.includes('/visit-offers/') && method !== 'GET') {
      const input = route.request().postDataJSON(); state.writes.push({ path, body: input });
      expect(input.version).toBe(state.offers[0].version);
      state.offers[0] = { ...state.offers[0], status: path.endsWith('/close') ? 'closed' : 'cancelled', version: state.offers[0].version + 1 };
      body = { visit_offer: state.offers[0] };
    } else if (path.endsWith('/visit-offers')) body = { visit_offers: state.offers };
    else if (path.endsWith('/activities/activity-1')) body = { activity: { id: 'activity-1', title: 'Шахмат', age_label: '6–8 г.', slug: 'chess', status: 'published', version: 1, category: 'Шахмат', summary: 'Описание', sections: {}, offering: null } };
    else if (path.endsWith('/groups')) body = { groups: [{ id: 'group-1', offering_id: 'offering-1', version: 1, name: 'Начинаещи', age_label: '6–8 г.', status: 'published', capacity_min: 1, capacity_max: 12, instructor_ids: [], schedule_pattern: { timezone: 'Europe/Sofia', frequency: 'weekly' }, price_options: [], sessions: [session], conflicts: [] }] };
    await route.fulfill({ json: body });
  });
  return state;
}

test('organization starts with zero campaigns, creates, edits, publishes, closes and cancels, then manages its offer', async ({ page }) => {
  const state = await fixture(page);
  await page.goto('/dashboard/open-days');
  await expect(page.getByText('Все още няма кампании. Създайте първата по-долу.')).toBeVisible();
  await page.getByLabel('Заглавие на кампанията').fill('Есенни отворени врати');
  await page.getByLabel('Начална дата').fill('2035-11-01');
  await page.getByLabel('Крайна дата').fill('2035-11-05');
  await page.getByLabel('Запознай се с шахмата').check();
  await page.getByRole('button', { name: 'Създай кампания' }).click();
  await expect(page.getByRole('status')).toContainText('Кампанията е запазена');
  expect(state.writes[0].body).toMatchObject({ title: 'Есенни отворени врати', visit_offer_ids: ['offer-1'], visit_offer_versions: { 'offer-1': 4 } });
  expect(state.writes[0].body).not.toHaveProperty('version');
  await page.getByLabel('Описание на кампанията').fill('Елате да се запознаем.');
  await page.getByRole('button', { name: 'Запази кампанията' }).click();
  await expect(page.getByRole('status')).toContainText('Кампанията е запазена');
  expect(state.writes[1].body.version).toBe(1);
  await page.getByRole('button', { name: 'Публикувай кампанията' }).click();
  await expect(page.getByRole('status')).toContainText('Кампанията е публикувана');
  await page.reload();
  await page.getByLabel('Кампания за редакция').selectOption('campaign-1');
  await expect(page.getByLabel('Описание на кампанията')).toHaveValue('Елате да се запознаем.');
  for (const width of [390, 1440]) {
    await page.setViewportSize({ width, height: 1000 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    await page.screenshot({ path: test.info().outputPath(`campaign-editor-${width}.png`), fullPage: true });
  }
  await expect(page.getByText(/Потвърдените посещения се запазват/)).toBeVisible();
  page.once('dialog', (dialog) => dialog.accept());
  await page.getByRole('button', { name: 'Приключи кампанията' }).click();
  await expect(page.getByRole('status')).toContainText('Кампанията е приключена');
  page.once('dialog', (dialog) => dialog.accept());
  await page.getByRole('button', { name: 'Отмени кампанията' }).click();
  await expect(page.getByRole('status')).toContainText('Кампанията е отменена');
  await expect(page.getByLabel('Заглавие на кампанията')).toBeDisabled();
  expect(state.offers[0].status).toBe('published');
  await page.getByRole('link', { name: 'Редактирай предложение: Запознай се с шахмата' }).click();
  await expect(page.getByRole('button', { name: 'Приключи предложението' })).toBeVisible();
  page.once('dialog', (dialog) => dialog.accept());
  await page.getByRole('button', { name: 'Приключи предложението' }).click();
  await expect(page.getByRole('status')).toContainText('Предложението е приключено');
  page.once('dialog', (dialog) => dialog.accept());
  await page.getByRole('button', { name: 'Отмени предложението' }).click();
  await expect(page.getByRole('status')).toContainText('Предложението е отменено');
  expect(state.writes.slice(-2).map((write) => write.body.version)).toEqual([5, 6]);
});

test('campaign conflict keeps typed content and requires reloading before retry', async ({ page }) => {
  const state = await fixture(page);
  state.campaigns = [{ id: 'campaign-1', title: 'Original', slug: 'original', description: null, starts_on: null, ends_on: null, image_path: null, status: 'draft', version: 2, published_at: null, visit_offers: [] }];
  await page.goto('/dashboard/open-days');
  await page.getByLabel('Кампания за редакция').selectOption('campaign-1');
  await page.getByLabel('Заглавие на кампанията').fill('Моята редакция');
  state.conflict = true;
  await page.getByRole('button', { name: 'Запази кампанията' }).click();
  await expect(page.getByRole('alert').filter({ hasText: 'променени' })).toBeVisible();
  await expect(page.getByLabel('Заглавие на кампанията')).toHaveValue('Моята редакция');
  await expect(page.getByRole('button', { name: 'Запази кампанията' })).toBeDisabled();
  state.conflict = false;
  await page.getByRole('button', { name: 'Зареди актуалната кампания' }).click();
  await expect(page.getByLabel('Заглавие на кампанията')).toHaveValue('Original');
});
