import { expect, test } from '@playwright/test';
import { fixture, layout } from './attendance-fixture';
import type { Page } from '@playwright/test';

async function richOffice(page: Page) {
  const f = await fixture(page, { office: true });
  const permissions = ['view','invite','catalog.view','catalog.manage','enrollment.view','enrollment.manage','attendance.view_month','attendance.mark','team.view','terms.view'];
  await page.route('**/backend/api/v1/**', async route => {
    const path = new URL(route.request().url()).pathname;
    let json: unknown;
    if (path.endsWith('/organizations')) json = { organizations: [{ id: '00000000-0000-4000-8000-000000000002', name: 'Школа „Звезда“', slug: 'demo', status: 'active', role: 'organization_owner', permissions }] };
    else if (path.endsWith('/context/organization')) json = { access: { role: 'organization_owner', scope: 'all', abilities: [...permissions,'schedule.manage','team.assign'], groups: [], activities: [] } };
    else if (path.endsWith('/invitations')) json = { invitations: [{ id: 'demo-invitation', status: 'pending' }] };
    else if (path.endsWith('/audit-events')) json = { events: [] };
    else if (path.endsWith('/catalog/activities')) json = { activities: [] };
    else if (path.endsWith('/catalog/profile')) json = { profile: null };
    else if (path.endsWith('/catalog/locations')) json = { locations: [] };
    else if (path.endsWith('/catalog/instructors')) json = { instructors: [] };
    else if (path.endsWith('/enrollment/groups')) json = { groups: [] };
    else return route.fallback();
    return route.fulfill({ json });
  });
  return f;
}

for (const width of [390, 768, 1440]) {
  test(`workspace separates organization and personal work at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 1000 });
    const f = await richOffice(page);
    await page.goto('/dashboard');
    await expect(page.getByRole('heading', { name: 'Занимания днес', exact: true })).toBeVisible();
    await expect(page.getByText('4 от 10 места', { exact: true })).toBeVisible();
    await expect(page.getByRole('link', { name: /Създайте първата си дейност/ })).toHaveCount(0);
    await expect(page.getByRole('heading', { name: 'Моите групи', exact: true })).toHaveCount(0);
    await layout(page);
    await page.screenshot({ path: test.info().outputPath(`home-office-${width}.png`), fullPage: true });
    await page.getByRole('button', { name: 'Моите занимания', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Моите групи', exact: true })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Днес', exact: true })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Занимания днес', exact: true })).toHaveCount(0);
    await expect(page.getByRole('link', { name: 'Присъствия за месеца →', exact: true })).toHaveCount(1);
    await layout(page);
    await page.screenshot({ path: test.info().outputPath(`home-personal-${width}.png`), fullPage: true });
    expect(f.errors).toEqual([]);
  });
}

test('phone menu traps focus, closes with Escape and keeps all other sections reachable', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await richOffice(page);
  await page.goto('/dashboard');
  const bottom = page.getByRole('navigation', { name: 'Основно меню на телефон' });
  await expect(bottom.getByRole('link', { name: 'Записвания', exact: true })).toBeVisible();
  await expect(bottom.getByRole('link', { name: 'Начало', exact: true })).toHaveAttribute('aria-current', 'page');
  const opener = page.getByRole('button', { name: 'Отвори менюто', exact: true });
  await opener.click();
  const dialog = page.getByRole('dialog', { name: 'Всички раздели', exact: true });
  const close = dialog.getByRole('button', { name: 'Затвори менюто', exact: true });
  await expect(close).toBeFocused();
  await expect(dialog.getByRole('link', { name: 'Дейности', exact: true })).toBeVisible();
  await expect(dialog.getByRole('link', { name: 'Екип', exact: true })).toBeVisible();
  await page.keyboard.press('Shift+Tab');
  await expect(dialog.getByRole('button', { name: 'Изход', exact: true })).toBeFocused();
  await page.keyboard.press('Tab');
  await expect(close).toBeFocused();
  await page.keyboard.press('Escape');
  await expect(dialog).toHaveCount(0);
  await expect(opener).toBeFocused();
  await bottom.getByRole('link', { name: 'Присъствия', exact: true }).click();
  await expect(page).toHaveURL(/dashboard\/attendance/);
  await expect(bottom.getByRole('link', { name: 'Присъствия', exact: true })).toHaveAttribute('aria-current', 'page');
});

test('teacher phone navigation keeps its own scope', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await fixture(page);
  await page.goto('/dashboard');
  const nav = page.getByRole('navigation', { name: 'Основно меню на телефон' });
  await expect(nav.getByRole('link', { name: 'Моят график', exact: true })).toBeVisible();
  await expect(nav.getByRole('link', { name: 'Записвания', exact: true })).toHaveCount(0);
  await expect(page.getByRole('group', { name: 'Обхват на таблото' })).toHaveCount(0);
  await expect(page.getByRole('heading', { name: 'Моите групи', exact: true })).toBeVisible();
  await layout(page);
});

test('organization schedule has an honest empty state and retry after failure', async ({ page }) => {
  await fixture(page, { office: true });
  let fail = true;
  await page.route('**/backend/api/v1/schedule?**', route => route.fulfill(fail ? { status: 503, json: {} } : { json: { sessions: [] } }));
  await page.goto('/dashboard');
  await expect(page.getByText('Днешният график не се зареди.', { exact: true })).toBeVisible();
  fail = false;
  await page.getByRole('button', { name: 'Опитай пак', exact: true }).click();
  await expect(page.getByText('Днес няма занимания', { exact: true })).toBeVisible();
});
