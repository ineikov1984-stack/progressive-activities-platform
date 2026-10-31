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
  await expect(page.getByRole('heading', { name: 'Progressive Academy' })).toBeVisible();
}

test('administration paints its frame from the design tokens', async () => {
  test.setTimeout(60_000);
  const cdpEndpoint = process.env.PLAYWRIGHT_CDP_ENDPOINT;
  const browser = cdpEndpoint
    ? await chromium.connectOverCDP(cdpEndpoint)
    : await chromium.launch({ channel: 'chrome' });
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
  const page = await context.newPage();

  await openDashboard(page);

  const tokens = await page.evaluate(() => {
    const style = getComputedStyle(document.documentElement);
    return ['--nav', '--brand', '--action', '--ink', '--surface-page'].map((name) => style.getPropertyValue(name).trim().toLowerCase());
  });
  expect(tokens).toEqual(['#0f2a40', '#e3823f', '#b8561a', '#173f5f', '#f6f4ef']);

  const sidebar = page.getByRole('navigation', { name: 'Административна навигация' }).locator('xpath=..');
  await expect(sidebar).toHaveCSS('background-color', 'rgb(15, 42, 64)');
  await expect(page.getByRole('link', { name: 'Начало' })).toHaveCSS('box-shadow', /rgb\(227, 130, 63\)/);
  await expect(page.locator('[data-admin-shell]')).toHaveCount(1);

  await context.close();
  if (!cdpEndpoint) await browser.close();
});
