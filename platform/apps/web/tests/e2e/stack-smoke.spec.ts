import { expect, request, test, type BrowserContext, type Page } from '@playwright/test';

// Runs in CI against the real Laravel API, PostgreSQL with RLS and the seeded demo
// organization (job "e2e-stack"). Values are local demo seed data, never real accounts.
const demo = {
  email: process.env.DEMO_USER_EMAIL ?? 'owner@demo.progressive.local',
  password: process.env.DEMO_USER_PASSWORD ?? 'progressive-demo-local-only',
  organizationId: '00000000-0000-4000-8000-000000000101',
  // LocalDemoSeeder::ACTIVITY_ID — the published "Шахмат" activity.
  publishedActivityId: '00000000-0000-4000-8000-000000000201',
};

// E2eForeignTenantSeeder: an organization the demo owner does not belong to.
const foreign = {
  organizationId: '00000000-0000-4000-8000-000000000103',
  activityId: '00000000-0000-4000-8000-000000000301',
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

type ApiCall = { method?: string; body?: unknown; organizationId?: string };

async function api(page: Page, path: string, call: ApiCall = {}): Promise<{ status: number; body: string }> {
  return page.evaluate(async ({ path, call }) => {
    const xsrf = document.cookie.split('; ').find((item) => item.startsWith('XSRF-TOKEN='))?.slice('XSRF-TOKEN='.length);
    const response = await fetch(`/backend/api/v1/${path}`, {
      method: call.method ?? 'GET',
      credentials: 'include',
      headers: {
        Accept: 'application/json',
        ...(call.body ? { 'Content-Type': 'application/json' } : {}),
        ...(xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) } : {}),
        ...(call.organizationId ? { 'X-Organization-Id': call.organizationId } : {}),
      },
      body: call.body ? JSON.stringify(call.body) : undefined,
    });
    const text = await response.text();
    let body = text;
    // Laravel escapes Cyrillic in JSON (\u0418…); compare against readable text.
    try { body = JSON.stringify(JSON.parse(text)); } catch { /* not JSON */ }
    return { status: response.status, body };
  }, { path, call });
}

const apiStatus = async (page: Page, path: string, call?: ApiCall) => (await api(page, path, call)).status;

// One sign-in for the whole suite: the API allows 5 login attempts per minute per
// email and IP, and every test here runs from the same IP.
test.describe.configure({ mode: 'serial' });
test.setTimeout(90_000);

let context: BrowserContext;
let page: Page;

test.beforeAll(async ({ browser }) => {
  context = await browser.newContext();
  page = await context.newPage();
});

test.afterAll(async () => {
  await context.close();
});

test('sign in leads to the home screen with tasks', async () => {
  await signIn(page);
  await slow(page.getByRole('heading', { level: 1, name: 'Progressive Academy Demo' })).toBeVisible();
  await slow(page.getByRole('heading', { name: 'Какво чака вас' })).toBeVisible();
  await slow(page.getByRole('link', { name: 'Създай нова дейност' })).toBeVisible();
  await expect(page.getByText(/catalog\.|tenant|organization_id/)).toHaveCount(0);
});

test('an owner invites a colleague from the home screen', async () => {
  // Wait until the team list has loaded, so it cannot replace the new invitation afterwards.
  const invitationsLoaded = page.waitForResponse((response) => response.url().includes('/backend/api/v1/invitations') && response.request().method() === 'GET');
  await page.goto('/dashboard');
  await invitationsLoaded;
  const email = `teacher-e2e+${Date.now()}@example.test`;
  await slow(page.getByLabel('Имейл')).toBeVisible();
  await page.getByLabel('Имейл').fill(email);
  await page.getByLabel('Роля').selectOption('instructor');
  await page.getByRole('button', { name: 'Изпрати покана' }).click();
  await slow(page.getByRole('status')).toContainText('Поканата е създадена');
  await expect(page.getByText(email)).toBeVisible();
});

test('the session survives navigation, reloads and many API calls', async () => {
  for (const path of ['/dashboard/activities', '/dashboard', '/dashboard/open-days', '/dashboard/activities']) {
    await page.goto(path);
    await slow(page.getByRole('navigation').first()).toBeVisible();
    expect(await apiStatus(page, 'me')).toBe(200);
  }
  await page.reload();
  for (let index = 0; index < 10; index += 1) {
    expect(await apiStatus(page, 'catalog/activities', { organizationId: demo.organizationId })).toBe(200);
  }
  expect(await apiStatus(page, 'me')).toBe(200);
  await page.goto('/dashboard');
  await expect(page).toHaveURL(/\/dashboard$/);
});

test('the organization can be selected', async () => {
  expect(await apiStatus(page, 'context/organization', { method: 'POST', body: { organization_id: demo.organizationId } })).toBe(200);
});

test('the selected organization is kept in the session', async () => {
  expect(await apiStatus(page, 'context/organization')).toBe(200);
});

test('every API response keeps the browser signed in (session cookie stays valid)', async () => {
  // Regression for 29.10.2026: the API started the session twice, so each response's cookie
  // pointed to a new, empty session. Talk to the API directly with a normal cookie jar.
  const jar = await request.newContext({
    baseURL: 'http://127.0.0.1:8080',
    extraHTTPHeaders: { Origin: 'http://127.0.0.1:3000', Referer: 'http://127.0.0.1:3000/', Accept: 'application/json' },
  });
  const token = async () => decodeURIComponent((await jar.storageState()).cookies.find((item) => item.name === 'XSRF-TOKEN')?.value ?? '');
  await jar.get('/sanctum/csrf-cookie');
  expect((await jar.post('/api/v1/auth/login', { headers: { 'X-XSRF-TOKEN': await token() }, data: { email: demo.email, password: demo.password } })).status()).toBe(200);
  for (let index = 0; index < 3; index += 1) {
    expect((await jar.get('/api/v1/me')).status(), `request ${index + 1} after sign-in`).toBe(200);
  }
  expect((await jar.post('/api/v1/context/organization', { headers: { 'X-XSRF-TOKEN': await token() }, data: { organization_id: demo.organizationId } })).status()).toBe(200);
  expect((await jar.get('/api/v1/context/organization')).status()).toBe(200);
  expect((await jar.get('/api/v1/me')).status()).toBe(200);
  await jar.dispose();
});

test('another organization is not reachable through the header', async () => {
  const response = await api(page, 'catalog/activities', { organizationId: foreign.organizationId });
  expect(response.status).toBe(404);
  expect(response.body).toContain('Организацията не е намерена.');
  expect(response.body).not.toContain(foreign.activityId);
});

test('another organization\'s activity is not reachable from our own organization', async () => {
  const single = await api(page, `catalog/activities/${foreign.activityId}`, { organizationId: demo.organizationId });
  expect(single.status).toBe(404);
  expect(single.body).not.toContain('Чужда дейност');
  const list = await api(page, 'catalog/activities', { organizationId: demo.organizationId });
  expect(list.status).toBe(200);
  expect(list.body).not.toContain(foreign.activityId);
});

test('an owner creates, fills in and publishes an activity with the wizard', async () => {
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

  // The wizard saves on its own and keeps the text after a reload.
  const summary = 'Децата сглобяват малки роботи и ги програмират стъпка по стъпка.';
  await page.getByLabel('Кратко описание').fill(summary);
  await slow(page.getByRole('status').first()).toContainText('Запазено');
  await page.getByRole('button', { name: /Напред: Аудитория/ }).click();
  await expect(page).toHaveURL(/step=2$/);
  await page.getByRole('button', { name: '7–10 г.' }).click();
  await slow(page.getByRole('status').first()).toContainText('Запазено');
  await page.reload();
  await slow(page.getByLabel('За кого е подходяща')).toHaveValue('7–10 г.');
  await page.getByRole('navigation', { name: 'Стъпки' }).getByRole('button', { name: /Основна информация/ }).click();
  await slow(page.getByLabel('Кратко описание')).toHaveValue(summary);

  // Step 6: a price is saved automatically in euro.
  await page.getByRole('navigation', { name: 'Стъпки' }).getByRole('button', { name: /Цени и записване/ }).click();
  await slow(page.getByRole('heading', { level: 2, name: 'Цени и записване' })).toBeVisible();
  if (await page.getByLabel('Цена', { exact: true }).count() === 0) await page.getByRole('button', { name: 'Добави начин на записване' }).click();
  await page.getByLabel('Цена', { exact: true }).first().fill('25');
  await expect(page.getByText(/Родителите виждат:.*25,00/)).toBeVisible();
  await slow(page.getByRole('status').first()).toContainText('Запазено');
  await page.reload();
  await slow(page.getByLabel('Цена', { exact: true }).first()).toHaveValue('25,00');

  // Step 7: a weekly group creates its sessions.
  await page.getByRole('navigation', { name: 'Стъпки' }).getByRole('button', { name: /Групи и график/ }).click();
  await page.getByLabel('От дата').fill('2027-03-01');
  await page.getByLabel('До дата').fill('2027-03-29');
  await page.getByRole('button', { name: 'Създай групата' }).click();
  await slow(page.getByText(/е създадена с \d+ занятия/)).toBeVisible();

  // Step 9: publish once everything required is in place.
  await page.getByRole('navigation', { name: 'Стъпки' }).getByRole('button', { name: /Преглед и публикуване/ }).click();
  await page.getByRole('button', { name: 'Публикувай дейността' }).click();
  await slow(page.getByText('Дейността е публикувана.', { exact: false })).toBeVisible();

  await page.goto('/dashboard/activities');
  const card = page.getByRole('article').filter({ has: page.getByRole('heading', { name: title }) });
  await slow(card).toBeVisible();
  await expect(card.getByText('Публикувана', { exact: true })).toBeVisible();
  await expect(card.getByRole('link', { name: 'Преглед като родител' })).toBeVisible();
});

test('the team opens enrollments: inbox and group rosters', async () => {
  await page.goto('/dashboard/enrollments');
  await slow(page.getByRole('heading', { level: 1, name: 'Записвания' })).toBeVisible();
  await expect(page.getByRole('button', { name: /Чакат решение/ })).toHaveAttribute('aria-pressed', 'true');
  await slow(page.getByRole('region', { name: 'Чакат решение' })).toHaveAttribute('aria-busy', 'false');
  await page.getByRole('button', { name: 'Групи', exact: true }).click();
  const firstGroup = page.getByRole('button', { name: /Група/ }).first();
  await slow(firstGroup).toBeVisible();
  await firstGroup.click();
  await slow(page.getByText(/записани за периода/)).toBeVisible();
});

test('a published activity is locked in the editor', async () => {
  await page.goto(`/dashboard/activities/${demo.publishedActivityId}`);
  await slow(page.getByRole('note')).toContainText('публикувана');
  await expect(page.getByLabel('Кратко описание')).toBeDisabled();
});
