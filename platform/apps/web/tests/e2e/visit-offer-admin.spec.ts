import { expect, test } from '@playwright/test';
import type { VisitOffer } from '../../src/features/catalog/admin/api';

test('five-step editing preserves inputs, saves an open day fee in exact cents, restores after reload and blocks publication errors', async ({ page }) => {
  let saved: VisitOffer | undefined;
  let publishCalls = 0;
  let conflict = false;
  let rejectPublication = true;
  const writes: Record<string, unknown>[] = [];
  const session = { id: 'session-1', starts_at: '2035-11-01T14:00:00Z', ends_at: '2035-11-01T15:00:00Z', status: 'scheduled' };
  await page.route('**/backend/**', async (route) => {
    const path = new URL(route.request().url()).pathname;
    let body: unknown = {};
    if (path.endsWith('/me')) body = { user: { id: 'user-1', name: 'Организатор', email: 'owner@example.test' } };
    else if (path.endsWith('/organizations')) body = { organizations: [{ id: 'org-1', name: 'Школа', slug: 'school', status: 'active', role: 'organization_owner', permissions: ['catalog.manage', 'catalog.publish'] }] };
    else if (path.endsWith('/invitations')) body = { invitations: [] };
    else if (path.endsWith('/audit-events')) body = { events: [] };
    else if (path.endsWith('/open-day-campaigns')) body = { campaigns: [] };
    else if (path.endsWith('/groups')) body = { groups: [{ id: 'group-1', offering_id: 'offering-1', version: 1, name: 'Начинаещи', age_label: '6–8 г.', status: 'published', capacity_min: 1, capacity_max: 12, instructor_ids: [], schedule_pattern: { timezone: 'Europe/Sofia', frequency: 'weekly' }, price_options: [], sessions: [session], conflicts: [] }] };
    else if (path.endsWith('/activities/activity-1')) body = { activity: { id: 'activity-1', title: 'Шахмат', age_label: '6–8 г.', slug: 'chess', status: 'published', version: 1, category: 'Шахмат', summary: 'Описание', sections: {}, offering: null } };
    else if (path.endsWith('/publish')) {
      publishCalls++;
      if (rejectPublication) { await route.fulfill({ status: 422, json: { message: 'Невалидни данни', errors: { session_ids: ['Има конфликт в графика.'] } } }); return; }
      expect(route.request().postDataJSON().version).toBe(saved?.version);
      saved = { ...saved!, status: 'published', version: saved!.version + 1 }; body = { visit_offer: saved };
    }
    else if (path.includes('/visit-offers') && route.request().method() !== 'GET') {
      if (conflict) { await route.fulfill({ status: 409, json: { message: 'Visit offer version conflict.' } }); return; }
      const input = route.request().postDataJSON(); writes.push(input);
      saved = { ...input, id: 'offer-1', version: (saved?.version ?? 0) + 1, status: 'draft', campaign_id: null, sessions: [session], published_at: null, closed_at: null, cancelled_at: null };
      body = { visit_offer: saved };
    } else if (path.endsWith('/visit-offers')) body = { visit_offers: saved ? [saved] : [] };
    await route.fulfill({ json: body });
  });
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('/dashboard/activities/activity-1/visit-offers');
  await expect(page.getByRole('heading', { level: 1, name: 'Шахмат' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Генерирай с AI' })).toBeDisabled();
  // Only an open day has a fee of its own; a trial or single visit is priced from step 6 (docs/product/visits.md, т. 8a).
  await page.getByLabel('Вид посещение').selectOption('open_day');
  await page.getByLabel('Публично заглавие').fill('Първи ходове');
  await page.getByLabel('Описание (по желание)').fill('Запазено описание');
  await expect(page.getByText('Например: Децата ще изпробват', { exact: false })).toBeVisible();
  await page.getByRole('button', { name: 'Напред', exact: true }).click();
  await page.getByRole('combobox', { name: 'Група', exact: true }).selectOption('group-1');
  await page.getByRole('checkbox').check();
  await page.getByRole('button', { name: '4. Цена и условия', exact: true }).click();
  await page.getByLabel('Цена за деня').selectOption('paid');
  await page.getByLabel('Цена в евро').fill('19,99');
  await page.getByRole('button', { name: 'Запази черновата' }).click();
  await expect(page.locator('#error-fee_reason')).toBeVisible();
  expect(writes).toHaveLength(0);
  await page.getByLabel('Публично основание за таксата').fill('Материали');
  await page.getByRole('button', { name: '1. Вид и съдържание', exact: true }).click();
  await expect(page.getByLabel('Описание (по желание)')).toHaveValue('Запазено описание');
  await page.getByRole('button', { name: '3. Места и срок', exact: true }).click();
  await expect(page.getByLabel('Записване до колко часа преди началото')).toHaveValue('12');
  await expect(page.getByLabel('Отказ до колко часа преди началото')).toHaveCount(0);
  await page.getByRole('button', { name: 'Запази черновата' }).click();
  await expect(page.getByRole('status')).toHaveText('Предложението е запазено.');
  expect(writes[0]).toMatchObject({ type: 'open_day', amount_cents: 1999, description: 'Запазено описание', session_ids: ['session-1'], fee_reason: 'Материали', booking_deadline_hours: 12 });
  for (const retired of ['terms_version', 'cancellation_deadline_hours', 'cancellation_outcome', 'trial_limit_per_academic_year']) expect(writes[0]).not.toHaveProperty(retired);
  await page.reload();
  await expect(page.getByLabel('Публично заглавие')).toHaveValue('Първи ходове');
  await expect(page.getByLabel('Описание (по желание)')).toHaveValue('Запазено описание');
  await page.getByRole('button', { name: '2. Група и занятия', exact: true }).click();
  await expect(page.getByRole('checkbox')).toBeChecked();
  await page.getByRole('button', { name: '4. Цена и условия', exact: true }).click();
  await expect(page.getByLabel('Цена в евро')).toHaveValue('19.99');
  await page.getByRole('button', { name: '5. Преглед и публикуване', exact: true }).click();
  await page.getByRole('button', { name: 'Публикувай предложението' }).click();
  await expect(page.getByRole('alert').filter({ hasText: 'Поправете следното' })).toContainText('Има конфликт в графика.');
  await expect(page.getByRole('button', { name: 'Публикувай предложението' })).toBeDisabled();
  expect(publishCalls).toBe(1); expect(writes[1].version).toBe(1);
  await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);
  await page.getByRole('button', { name: '1. Вид и съдържание', exact: true }).click();
  await page.getByLabel('Публично заглавие').fill('Моята незапазена промяна');
  saved = { ...saved!, title: 'Промяна от колега', version: saved!.version + 1 }; conflict = true;
  await page.getByRole('button', { name: 'Запази черновата' }).click();
  await expect(page.getByRole('main').getByRole('alert')).toContainText('променени от друг служител');
  await page.getByRole('button', { name: 'Зареди актуалните данни' }).click();
  await expect(page.getByRole('heading', { name: 'Последно запазено предложение' })).toBeVisible();
  await expect(page.getByLabel('Публично заглавие')).toHaveValue('Моята незапазена промяна');
  await page.getByRole('button', { name: 'Замени формата с актуалното предложение' }).click();
  await expect(page.getByLabel('Публично заглавие')).toHaveValue('Промяна от колега');
  conflict = false; rejectPublication = false;
  await page.getByRole('button', { name: '5. Преглед и публикуване', exact: true }).click();
  await page.getByRole('button', { name: 'Публикувай предложението' }).click();
  await expect(page.getByRole('status')).toHaveText('Предложението е публикувано.');
  await expect(page.getByRole('button', { name: 'Публикувай предложението' })).toBeDisabled();
  expect(saved?.status).toBe('published');
  await page.screenshot({ path: test.info().outputPath('visit-offer-mobile.png'), fullPage: true });
  await page.setViewportSize({ width: 1440, height: 1000 });
  await page.getByRole('button', { name: '1. Вид и съдържание', exact: true }).click();
  await page.screenshot({ path: test.info().outputPath('visit-offer-desktop.png'), fullPage: true });
});
