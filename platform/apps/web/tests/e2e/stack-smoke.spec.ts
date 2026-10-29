import { expect, test, type Page } from '@playwright/test';

// Runs in CI against the real Laravel API, PostgreSQL with RLS and the seeded demo
// organization (job "e2e-stack"). Values are local demo seed data, never real accounts.
const demo = {
  email: process.env.DEMO_USER_EMAIL ?? 'owner@demo.progressive.local',
  password: process.env.DEMO_USER_PASSWORD ?? 'progressive-demo-local-only',
  organizationId: '00000000-0000-4000-8000-000000000101',
};

const slow = expect.configure({ timeout: 20_000 });

async function signIn(page: Page): Promise<void> {
  await page.goto('/login');
  await page.getByLabel('Имейл').fill(demo.email);
  await page.getByLabel('Парола').fill(demo.password);
  await Promise.all([
    page.waitForURL(/\/dashboard$/),
    page.getByRole('button', { name: 'Вход' }).click(),
  ]);
}

async function apiStatus(page: Page, path: string, init?: { method?: string; body?: unknown; organization?: boolean }): Promise<number> {
  return page.evaluate(async ({ path, init, organizationId }) => {
    const xsrf = document.cookie.split('; ').find((item) => item.startsWith('XSRF-TOKEN='))?.slice('XSRF-TOKEN='.length);
    const response = await fetch(`/backend/api/v1/${path}`, {
      method: init?.method ?? 'GET',
      credentials: 'include',
      headers: {
        Accept: 'application/json',
        ...(init?.body ? { 'Content-Type': 'application/json' } : {}),
        ...(xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) } : {}),
        ...(init?.organization ? { 'X-Organization-Id': organizationId } : {}),
      },
      body: init?.body ? JSON.stringify(init.body) : undefined,
    });
    return response.status;
  }, { path, init, organizationId: demo.organizationId });
}

test.describe.configure({ mode: 'serial' });
test.setTimeout(90_000);

test('sign in leads to the home screen with tasks', async ({ page }) => {
  await signIn(page);
  await slow(page.getByRole('heading', { level: 1, name: 'Progressive Academy Demo' })).toBeVisible();
  await slow(page.getByRole('heading', { name: 'Какво чака вас' })).toBeVisible();
  await slow(page.getByRole('link', { name: 'Създай нова дейност' })).toBeVisible();
  await expect(page.getByText(/catalog\.|tenant|organization_id/)).toHaveCount(0);
});

test('the session survives navigation, reloads and many API calls', async ({ page }) => {
  await signIn(page);
  for (const path of ['/dashboard/activities', '/dashboard', '/dashboard/open-days', '/dashboard/activities']) {
    await page.goto(path);
    await slow(page.getByRole('navigation').first()).toBeVisible();
    expect(await apiStatus(page, 'me')).toBe(200);
  }
  await page.reload();
  for (let index = 0; index < 10; index += 1) {
    expect(await apiStatus(page, 'catalog/activities', { organization: true })).toBe(200);
  }
  expect(await apiStatus(page, 'me')).toBe(200);
  await page.goto('/dashboard');
  await expect(page).toHaveURL(/\/dashboard$/);
});

test('the selected organization is kept in the session', async ({ page }) => {
  // Known issue (docs/process/WORKBOARD.md): the API answers 200 but the next request
  // still has no organization. Marked as expected to fail until the API is fixed;
  // when it starts passing, Playwright reports it so the marker can be removed.
  test.fail();
  await signIn(page);
  expect(await apiStatus(page, 'context/organization', { method: 'POST', body: { organization_id: demo.organizationId } })).toBe(200);
  expect(await apiStatus(page, 'context/organization')).toBe(200);
});

test('another organization is not reachable', async ({ page }) => {
  await signIn(page);
  const status = await page.evaluate(async () => {
    const response = await fetch('/backend/api/v1/catalog/activities', {
      credentials: 'include',
      headers: { Accept: 'application/json', 'X-Organization-Id': '00000000-0000-4000-8000-0000000009ff' },
    });
    return response.status;
  });
  expect(status).toBe(404);
});

test('an owner creates a draft activity and continues in the editor', async ({ page }) => {
  await signIn(page);
  await page.goto('/dashboard/activities');
  await slow(page.getByRole('heading', { level: 1, name: 'Дейности' })).toBeVisible();
  await page.getByRole('button', { name: 'Създай нова дейност' }).first().click();
  const title = `Смоук тест ${Date.now()}`;
  await page.getByLabel('Име на дейността').fill(title);
  await page.getByText('Наука и технологии', { exact: true }).click();
  await Promise.all([
    page.waitForURL(/\/dashboard\/activities\/[^/]+$/),
    page.getByRole('button', { name: 'Продължи към детайлите' }).click(),
  ]);
  await slow(page.getByRole('heading', { level: 1, name: title })).toBeVisible();

  await page.goto('/dashboard/activities');
  const card = page.getByRole('article').filter({ has: page.getByRole('heading', { name: title }) });
  await slow(card).toBeVisible();
  await expect(card.getByText('Чернова', { exact: true })).toBeVisible();
  await expect(card.getByText(/от 7 стъпки/)).toBeVisible();
});

test('a published activity is locked in the editor', async ({ page }) => {
  await signIn(page);
  await page.goto('/dashboard/activities/00000000-0000-4000-8000-000000000201');
  await slow(page.getByRole('note')).toContainText('публикувана');
  await expect(page.getByLabel('Кратко описание')).toBeDisabled();
});
