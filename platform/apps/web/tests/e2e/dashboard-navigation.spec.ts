import { chromium, expect, test } from '@playwright/test';

async function openDashboard(page: import('@playwright/test').Page) {
  await page.route('**/backend/api/v1/**', async (route) => {
    const path = new URL(route.request().url()).pathname;
    if (path.endsWith('/me')) return route.fulfill({ json: { user: { id: 'user-1', name: 'Иван Николов', email: 'owner@example.test' } } });
    if (path.endsWith('/organizations')) return route.fulfill({ json: { organizations: [{ id: 'org-1', name: 'Progressive Academy', slug: 'progressive-academy', status: 'active', role: 'organization_owner', permissions: ['create', 'update', 'invite', 'finance', 'view', 'catalog.view', 'catalog.manage', 'terms.view'] }] } });
    if (path.endsWith('/invitations')) return route.fulfill({ json: { invitations: [] } });
    if (path.endsWith('/audit-events')) return route.fulfill({ json: { events: [] } });
    return route.fulfill({ status: 404, json: { message: 'Not mocked' } });
  });
  await page.goto(`${process.env.PLAYWRIGHT_BASE_URL ?? 'http://127.0.0.1:3000'}/dashboard`);
  await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
}

test('dashboard uses grouped accessible navigation and remembers its desktop width', async () => {
  const cdpEndpoint = process.env.PLAYWRIGHT_CDP_ENDPOINT;
  const browser = cdpEndpoint
    ? await chromium.connectOverCDP(cdpEndpoint)
    : await chromium.launch({ channel: 'chrome' });
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
  const page = await context.newPage();

  await openDashboard(page);

  const navigation = page.getByRole('navigation', { name: 'Административна навигация' });
  await expect(navigation.getByText('Управление', { exact: true })).toBeVisible();
  await expect(navigation.getByText('Организация', { exact: true })).toBeVisible();
  await expect(navigation.getByRole('link', { name: 'Дейности' })).toHaveAttribute('href', '/dashboard/activities');
  await expect(navigation.getByText('Финанси')).toHaveCount(0);
  await expect(navigation.locator('[aria-current="page"]')).toHaveCount(1);

  await page.getByRole('button', { name: 'Свий менюто' }).click();
  await expect(page.getByRole('button', { name: 'Разгъни менюто' })).toBeVisible();
  await page.reload();
  await expect(page.getByRole('button', { name: 'Разгъни менюто' })).toBeVisible();

  await page.close();
  await context.close();
  if (!cdpEndpoint) await browser.close();
});

test('mobile dashboard navigation opens as a drawer', async () => {
  const cdpEndpoint = process.env.PLAYWRIGHT_CDP_ENDPOINT;
  const browser = cdpEndpoint
    ? await chromium.connectOverCDP(cdpEndpoint)
    : await chromium.launch({ channel: 'chrome' });
  const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
  const page = await context.newPage();

  await openDashboard(page);
  const openMenu = page.getByRole('button', { name: 'Отвори менюто' });
  const closeMenu = page.getByRole('button', { name: 'Затвори менюто' }).last();
  const sidebar = page.getByRole('navigation', { name: 'Административна навигация', includeHidden: true }).locator('xpath=..');
  await expect(openMenu).toBeVisible();
  await expect.poll(async () => (await sidebar.boundingBox())?.x ?? 0).toBeLessThan(0);
  await openMenu.click();
  await expect.poll(async () => (await sidebar.boundingBox())?.x ?? -1).toBeGreaterThanOrEqual(0);
  await closeMenu.click();
  await expect.poll(async () => (await sidebar.boundingBox())?.x ?? 0).toBeLessThan(0);

  await context.close();
  if (!cdpEndpoint) await browser.close();
});

test('dashboard navigation has safe defaults without horizontal overflow at target widths', async () => {
  const cdpEndpoint = process.env.PLAYWRIGHT_CDP_ENDPOINT;
  const browser = cdpEndpoint
    ? await chromium.connectOverCDP(cdpEndpoint)
    : await chromium.launch({ channel: 'chrome' });

  for (const width of [768, 1024, 1440]) {
    const context = await browser.newContext({ viewport: { width, height: 1000 } });
    const page = await context.newPage();
    await openDashboard(page);
    await expect(page.getByRole('button', { name: width < 1180 ? 'Разгъни менюто' : 'Свий менюто' })).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    await context.close();
  }

  if (!cdpEndpoint) await browser.close();
});
