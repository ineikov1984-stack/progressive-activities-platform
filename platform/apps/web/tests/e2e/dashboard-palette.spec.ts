import { chromium, expect, test } from '@playwright/test';

async function openDashboard(page: import('@playwright/test').Page) {
  await page.route('**/backend/api/v1/**', async (route) => {
    const path = new URL(route.request().url()).pathname;
    if (path.endsWith('/me')) return route.fulfill({ json: { user: { id: 'user-1', name: 'Иван Николов', email: 'owner@example.test' } } });
    if (path.endsWith('/organizations')) return route.fulfill({ json: { organizations: [{ id: 'org-1', name: 'Progressive Academy', slug: 'progressive-academy', status: 'active', role: 'organization_owner', permissions: ['create', 'update', 'invite', 'finance', 'view'] }] } });
    if (path.endsWith('/invitations')) return route.fulfill({ json: { invitations: [] } });
    if (path.endsWith('/audit-events')) return route.fulfill({ json: { events: [] } });
    return route.fulfill({ status: 404, json: { message: 'Not mocked' } });
  });
  await page.goto(`${process.env.PLAYWRIGHT_BASE_URL ?? 'http://127.0.0.1:3000'}/dashboard`);
  await expect(page.getByRole('heading', { name: 'Progressive Academy' })).toBeVisible();
}

test('administration exposes and applies the approved fixed palette', async () => {
  test.setTimeout(60_000);
  const cdpEndpoint = process.env.PLAYWRIGHT_CDP_ENDPOINT;
  const browser = cdpEndpoint
    ? await chromium.connectOverCDP(cdpEndpoint)
    : await chromium.launch({ channel: 'chrome' });
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
  const page = await context.newPage();

  await openDashboard(page);

  const shell = page.locator('[data-admin-shell]');
  await expect(shell).toHaveCount(1, { timeout: 5_000 });
  const palette = await shell.evaluate((element) => {
    const style = getComputedStyle(element);
    return {
      navigation: style.getPropertyValue('--admin-navigation').trim(),
      navigationDeep: style.getPropertyValue('--admin-navigation-deep').trim(),
      brand: style.getPropertyValue('--admin-brand').trim(),
      brandDeep: style.getPropertyValue('--admin-brand-deep').trim(),
      action: style.getPropertyValue('--admin-action').trim(),
      accent: style.getPropertyValue('--admin-accent').trim(),
      positive: style.getPropertyValue('--admin-positive').trim(),
      canvasWarm: style.getPropertyValue('--admin-canvas-warm').trim(),
      canvas: style.getPropertyValue('--admin-canvas').trim(),
      surface: style.getPropertyValue('--admin-surface').trim(),
      border: style.getPropertyValue('--admin-border').trim(),
      textMuted: style.getPropertyValue('--admin-text-muted').trim(),
    };
  });

  expect(palette).toEqual({
    navigation: '#173654',
    navigationDeep: '#0d253a',
    brand: '#205e4d',
    brandDeep: '#173f35',
    action: '#176a54',
    accent: '#ee8436',
    positive: '#b9e986',
    canvasWarm: '#f6f3ed',
    canvas: '#f3f6f4',
    surface: '#ffffff',
    border: '#dce6e1',
    textMuted: '#667a73',
  });

  await expect(page.getByRole('navigation', { name: 'Административна навигация' }).locator('xpath=..')).toHaveCSS('background-color', 'rgb(23, 54, 84)');
  await expect(page.getByRole('link', { name: 'Начало' })).toHaveCSS('box-shadow', /rgb\(238, 132, 54\)/);

  await context.close();
  if (!cdpEndpoint) await browser.close();
});
