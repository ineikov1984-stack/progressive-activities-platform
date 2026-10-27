import { chromium, expect, test } from '@playwright/test';

test('platform operator creates and opens an organization application', async () => {
  const cdpEndpoint = process.env.PLAYWRIGHT_CDP_ENDPOINT;
  const browser = cdpEndpoint ? await chromium.connectOverCDP(cdpEndpoint) : await chromium.launch({ channel: 'chrome' });
  const context = browser.contexts()[0] ?? await browser.newContext();
  const page = await context.newPage();

  await page.goto(`${process.env.PLAYWRIGHT_BASE_URL ?? 'http://127.0.0.1:3000'}/login`);
  await page.getByLabel('Имейл').fill('owner@demo.progressive.local');
  await page.getByLabel('Парола').fill('progressive-demo-local-only');
  await page.getByRole('button', { name: 'Вход' }).click();
  await expect(page).toHaveURL(/\/dashboard$/);
  await page.getByRole('link', { name: 'Кандидатури' }).click();
  await expect(page.getByRole('heading', { name: 'Кандидатури на организации' })).toBeVisible();

  const suffix = Date.now();
  const name = `Демо клуб ${suffix}`;
  await page.getByLabel('Име на организацията').fill(name);
  await page.getByLabel('Адрес в системата').fill(`demo-club-${suffix}`);
  await page.getByLabel('Имейл на първия собственик').fill(`owner+${suffix}@example.test`);
  await page.getByRole('button', { name: 'Създай и генерирай покана' }).click();

  await expect(page.getByText('Еднократен код')).toBeVisible();
  await expect(page.getByRole('heading', { name })).toBeVisible();
  page.once('dialog', (dialog) => dialog.accept());
  await page.getByRole('button', { name: 'Запиши решение' }).click();
  await expect(page.getByText('Статусът е променен на „За преглед“.')).toBeVisible();

  await page.close();
  if (!cdpEndpoint) await browser.close();
});
