import { chromium, expect, test } from '@playwright/test';

test('login is accessible and returns a generic error', async () => {
  const cdpEndpoint = process.env.PLAYWRIGHT_CDP_ENDPOINT;
  const browser = cdpEndpoint
    ? await chromium.connectOverCDP(cdpEndpoint)
    : await chromium.launch({ channel: 'chrome' });
  const context = await browser.newContext();
  const page = await context.newPage();

  await page.goto(`${process.env.PLAYWRIGHT_BASE_URL ?? 'http://127.0.0.1:3000'}/login`);

  await expect(page.getByRole('heading', { name: /Вход в (платформата|системата)/ })).toBeVisible();
  await expect(page.getByLabel('Имейл')).toBeVisible();
  await expect(page.getByLabel('Парола')).toBeVisible();

  await page.keyboard.press('Tab');
  await expect(page.getByRole('link', { name: 'Progressive Activities — начало' })).toBeFocused();
  await page.keyboard.press('Tab');
  await expect(page.getByLabel('Имейл')).toBeFocused();

  await page.getByLabel('Имейл').fill('unknown@example.test');
  await page.getByLabel('Парола').fill('wrong-password');
  await page.getByRole('button', { name: 'Вход' }).click();

  await expect(page.locator('form [role="alert"]')).toHaveText('Грешен имейл или парола.');
  await expect(page.getByRole('button', { name: 'Вход' })).toBeEnabled();

  await page.close();
  await context.close();
  if (!cdpEndpoint) {
    await browser.close();
  }
});
