import { chromium, expect, test } from '@playwright/test';

test('home shell identifies the platform', async () => {
  const cdpEndpoint = process.env.PLAYWRIGHT_CDP_ENDPOINT;
  const browser = cdpEndpoint
    ? await chromium.connectOverCDP(cdpEndpoint)
    : await chromium.launch({ channel: 'chrome' });
  const context = await browser.newContext();
  const page = await context.newPage();

  await page.goto(process.env.PLAYWRIGHT_BASE_URL ?? 'http://127.0.0.1:3000');

  await expect(
    page.getByRole('heading', { name: 'Progressive Activities' }),
  ).toBeVisible();

  await page.close();
  await context.close();
  if (!cdpEndpoint) {
    await browser.close();
  }
});
