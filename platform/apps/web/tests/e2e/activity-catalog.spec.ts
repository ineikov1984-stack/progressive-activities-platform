import { chromium, expect, test, type BrowserContext, type Page } from '@playwright/test';

const baseUrl = process.env.PLAYWRIGHT_BASE_URL ?? 'http://127.0.0.1:3000';
test.setTimeout(120_000);
const catalogExpect = expect.configure({ timeout: 15_000 });

async function openPage(viewport: { width: number; height: number }): Promise<{ context: BrowserContext; page: Page; close: () => Promise<void> }> {
  const endpoint = process.env.PLAYWRIGHT_CDP_ENDPOINT;
  const browser = endpoint ? await chromium.connectOverCDP(endpoint) : await chromium.launch({ channel: 'chrome' });
  const context = await browser.newContext({ viewport });
  const page = await context.newPage();
  await page.setViewportSize(viewport);

  return { context, page, close: async () => { await page.close(); await context.close(); if (!endpoint) await browser.close(); } };
}

async function assertAdministrativeWrite(page: Page): Promise<void> {
  const draftTitle = 'E2E административна чернова';
  const persistedSummary = 'Проверена и запазена редакция през административния интерфейс.';
  let draftCard = page.getByRole('article').filter({ has: page.getByRole('heading', { name: draftTitle, exact: true }) });

  if (await draftCard.count() === 0) {
    await page.getByRole('button', { name: 'Създай нова дейност' }).first().click();
    await page.getByLabel('Име на дейността').fill(draftTitle);
    await Promise.all([
      page.waitForURL(/\/dashboard\/activities\/[^/]+$/),
      page.getByRole('button', { name: 'Продължи към детайлите' }).click(),
    ]);
  } else {
    await Promise.all([
      page.waitForURL(/\/dashboard\/activities\/[^/]+$/),
      draftCard.getByRole('link', { name: /Продължи|Редактирай/ }).click(),
    ]);
  }

  await catalogExpect(page.getByRole('heading', { level: 1, name: draftTitle })).toBeVisible();
  await page.getByLabel('Кратко описание').fill(persistedSummary);
  await catalogExpect(page.getByRole('status').first()).toContainText('Запазено');
  await page.reload();
  await catalogExpect(page.getByLabel('Кратко описание')).toHaveValue(persistedSummary);

  await page.goto(`${baseUrl}/dashboard/activities`);
  draftCard = page.getByRole('article').filter({ has: page.getByRole('heading', { name: draftTitle, exact: true }) });
  await catalogExpect(draftCard).toBeVisible();
}

async function assertPublicChessJourney(page: Page): Promise<void> {
  await page.goto(`${baseUrl}/o/progressive-academy-demo`);
  await catalogExpect(page.getByRole('heading', { name: 'Открийте занимание, което вдъхновява' })).toBeVisible();
  const chessCard = page.getByRole('article').filter({ has: page.getByRole('heading', { name: 'Шахмат' }) });
  await catalogExpect(chessCard.getByText(/15,00\s*€/)).toBeVisible();
  await Promise.all([
    page.waitForURL(/\/o\/progressive-academy-demo\/activities\/shahmat$/),
    chessCard.getByRole('link', { name: /Научи повече/ }).click(),
  ]);

  await catalogExpect(page).toHaveURL(/\/o\/progressive-academy-demo\/activities\/shahmat$/);
  await catalogExpect(page.getByRole('heading', { level: 1, name: 'Шахмат' })).toBeVisible();
  await catalogExpect(page.getByRole('heading', { name: 'Групи и график' })).toBeVisible();
  await catalogExpect(page.getByRole('heading', { name: 'Група 1 · 1.–2. клас' })).toBeVisible();
  await catalogExpect(page.getByRole('heading', { name: 'Група 2 · 3.–4. клас' })).toBeVisible();
  await catalogExpect(page.getByRole('heading', { name: 'Александър Петров' })).toBeVisible();
  await catalogExpect(page.getByRole('link', { name: /Навигация до мястото/ }).first()).toHaveAttribute('href', /google\.com\/maps/);
  await catalogExpect(page.getByText('Записването ще бъде добавено в следващ етап').last()).toBeVisible();
  await catalogExpect(page.getByText('На този етап бутонът не изпраща заявка.')).toBeVisible();
}

test('demo owner sees the published chess activity in administration and its public page', async () => {
  const browser = await openPage({ width: 1440, height: 1000 });
  try {
    await browser.page.goto(`${baseUrl}/login`);
    await browser.page.getByLabel('Имейл').fill('owner@demo.progressive.local');
    await browser.page.getByLabel('Парола').fill('progressive-demo-local-only');
    await browser.page.getByRole('button', { name: 'Вход' }).click();
    await catalogExpect(browser.page).toHaveURL(/\/dashboard$/);

    await Promise.all([
      browser.page.waitForURL(/\/dashboard\/activities$/),
      browser.page.getByRole('link', { name: /Дейности/ }).click(),
    ]);
    await catalogExpect(browser.page.getByRole('heading', { level: 1, name: 'Дейности' })).toBeVisible();
    const chessCard = browser.page.getByRole('article').filter({ has: browser.page.getByRole('heading', { name: 'Шахмат' }) });
    await catalogExpect(chessCard.getByText('Публикувана')).toBeVisible();
    await assertAdministrativeWrite(browser.page);
    await Promise.all([
      browser.page.waitForURL(/\/dashboard\/activities\/[^/]+$/),
      chessCard.getByRole('link', { name: /Редактирай/ }).click(),
    ]);
    await catalogExpect(browser.page.getByRole('heading', { level: 1, name: 'Шахмат' })).toBeVisible();
    await catalogExpect(browser.page.getByRole('heading', { name: 'Група 1 · 1.–2. клас' })).toBeVisible();
    await catalogExpect(browser.page.getByRole('heading', { name: 'Група 2 · 3.–4. клас' })).toBeVisible();
    await catalogExpect(browser.page.getByText(/7 създадени занятия/)).toHaveCount(2);

    await assertPublicChessJourney(browser.page);
  } finally {
    await browser.close();
  }
});

test('the public chess journey remains usable on a mobile viewport', async () => {
  const browser = await openPage({ width: 390, height: 844 });
  try {
    await assertPublicChessJourney(browser.page);
    await catalogExpect.poll(() => browser.page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);
  } finally {
    await browser.close();
  }
});
