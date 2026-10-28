import { chromium, expect, test } from '@playwright/test';

test('demo owner can log in, view the administration and create an invitation', async () => {
  const cdpEndpoint = process.env.PLAYWRIGHT_CDP_ENDPOINT;
  const browser = cdpEndpoint
    ? await chromium.connectOverCDP(cdpEndpoint)
    : await chromium.launch({ channel: 'chrome' });
  const context = await browser.newContext();
  const page = await context.newPage();

  await page.goto(`${process.env.PLAYWRIGHT_BASE_URL ?? 'http://127.0.0.1:3000'}/login`);
  await page.getByLabel('Имейл').fill('owner@demo.progressive.local');
  await page.getByLabel('Парола').fill('progressive-demo-local-only');
  await page.getByRole('button', { name: 'Вход' }).click();

  await expect(page).toHaveURL(/\/dashboard$/);
  await expect(page.getByRole('heading', { name: 'Администрация' })).toBeVisible();
  await expect(page.getByText('Progressive Academy Demo')).toBeVisible();
  await expect(page.getByText('Собственик на организация')).toBeVisible();

  const invitationEmail = `teacher-dashboard+${Date.now()}@example.test`;
  await page.getByLabel('Имейл на нов член').fill(invitationEmail);
  await page.getByLabel('Роля').selectOption('instructor');
  await page.getByRole('button', { name: 'Изпрати покана' }).click();

  await expect(page.getByText(invitationEmail)).toBeVisible();
  await expect(page.getByText('Token за еднократно приемане')).toBeVisible();

  await page.close();
  await context.close();
  if (!cdpEndpoint) {
    await browser.close();
  }
});
