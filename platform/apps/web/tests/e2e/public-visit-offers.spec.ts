import { expect, test } from '@playwright/test';
import { createServer, type Server } from 'node:http';
import { spawn, type ChildProcess } from 'node:child_process';
import { mkdir } from 'node:fs/promises';
import path from 'node:path';
import type { PublicActivityResponse, PublicVisitOffer } from '../../src/features/catalog/public/api';

// A dedicated API boundary exercises real SSR routes without touching seeded or user data.
const origin = 'http://127.0.0.1:3107';
const organization = { slug: 'school', public_name: 'Академия за открития', accent_color: '#2f8b6d', intro: 'Малки открития. Големи възможности.' };
const offer: PublicVisitOffer = {
  visit_offer_id: 'offer-1', session_id: 'session-1', campaign_slug: null, type: 'trial', title: 'Първа среща с шахмата',
  description: 'Открийте играта с нашия екип.', audience: '6–8 г. · I–II клас',
  starts_at: '2035-11-03T23:30:00Z', ends_at: '2035-11-04T00:30:00Z', timezone: 'Asia/Tokyo', fee_type: 'free', amount_cents: 0,
  currency: 'EUR', fee_reason: null, booking_deadline_hours: 12,
  payment_terms: { version: 'visit:standard:1', billing_kind: 'visit', billing_kind_label: 'Посещение', name: 'Стандартни', preset: 'standard', preset_label: 'Стандартни', schema_version: 1, rules: { cancellation: { tiers: [{ min_hours: 24, kept: 0 }, { min_hours: 0, kept: 100 }] }, no_show_kept: 100, refund: 'money' } },
  availability: { status: 'last_place', remaining_total_places: 8, remaining_offer_places: 1, waitlist_available: false }, action: 'apply',
};

function activity(mode: string): PublicActivityResponse {
  const rich = mode === 'rich';
  const minimal = mode === 'minimal';
  return { organization, activity: {
    slug: mode, title: 'Шахмат — мисли с въображение', category: 'Шахмат', age_label: '6–8 г.', audience: offer.audience,
    summary: minimal ? '  ' : 'Всяка партия е ново приключение. Учим се да планираме, да откриваме решения и да вярваме в собствените си идеи.',
    hero_image_url: rich ? '/api/v1/public/organizations/school/media/real.svg' : mode === 'balanced' ? '/api/v1/public/organizations/school/media/broken.jpg' : null,
    price: { label: minimal ? 'Безплатно' : 'От 0,00 € / посещение', from: !minimal, amount_cents: 0, currency: 'EUR', unit: 'session' },
    availability: { status: minimal ? 'full' : 'last_place', remaining_places: minimal ? 0 : 1 }, special_offer: 'trial', special_offer_url: null,
    sections: minimal ? { about: ' ', benefits: [' '], methodology: '\n', participation_terms: ' ', instructor_intro: ' ', faq: [{ question: ' ', answer: ' ' }], gallery: [{ url: ' ', alt: ' ' }] } : {
      about: 'Спокойна среда за смели идеи и първи самостоятелни решения.', benefits: ['Концентрация', 'Логическо мислене', 'Увереност'],
      ...(rich ? { methodology: 'Учим чрез игра, разговор и задачи с постепенно нарастваща трудност.', participation_terms: 'Не е необходим предишен опит.', instructor_intro: 'Хората зад първите победи', faq: [{ question: 'Нужен ли е собствен шах?', answer: 'Всички материали са осигурени.' }], gallery: [{ url: '/api/v1/public/organizations/school/media/real.svg', alt: 'Шахматни фигури' }] } : {}),
    },
    groups: [{ id: 'group-1', name: 'Изследователи', age_label: '6–8 г.', capacity_min: 1, capacity_max: 12, availability: { status: 'available', remaining_places: 8 }, schedule: { timezone: 'Asia/Tokyo', frequency: 'weekly', slots: [{ weekday: 4, starts_at: '08:30', duration_minutes: 60 }] }, location: minimal ? null : { name: 'Зала Слънце', type: 'physical', address: 'ул. „Открития“ 8', navigation_url: 'https://maps.example/safe' }, prices: [], sessions: [{ id: 'session-1', starts_at: offer.starts_at, ends_at: offer.ends_at, status: 'scheduled' }] }],
    terms: minimal ? [{ title: ' ', terms: ' ', cancellation_policy: ' ' }] : [],
    instructors: rich ? [{ public_name: 'Александра Петрова', role_title: 'Преподавател по шахмат', bio: 'Помага на децата да откриват радостта от играта.' }] : [{ public_name: ' ', bio: ' ' }],
    visit_offers: minimal ? [{ ...offer, action: 'closed', availability: { ...offer.availability, status: 'full', waitlist_available: false } }] : [offer, { ...offer, visit_offer_id: 'offer-2', session_id: 'unmatched-session', title: 'Следваща среща', fee_type: 'from_price', amount_cents: 1250, fee_reason: 'Материали', action: 'waitlist', availability: { ...offer.availability, status: 'full', remaining_offer_places: 0, waitlist_available: true } }],
  } };
}

let api: Server;
let web: ChildProcess;
let logs = '';
test.describe.configure({ mode: 'default' });
test.setTimeout(90_000);
test.beforeAll(async () => {
  api = createServer((request, response) => {
    const pathname = new URL(request.url!, 'http://fixture').pathname;
    if (pathname.endsWith('real.svg')) {
      response.setHeader('Content-Type', 'image/svg+xml');
      response.end('<svg xmlns="http://www.w3.org/2000/svg" width="900" height="700"><rect width="900" height="700" fill="#e6eee9"/><circle cx="650" cy="170" r="100" fill="#e3823f"/><path d="M220 560h470l-80-80H300zM330 470h250l-70-180H400z" fill="#183a5a"/><circle cx="455" cy="230" r="65" fill="#183a5a"/></svg>'); return;
    }
    if (pathname.endsWith('broken.jpg') || pathname.endsWith('/inactive')) { response.statusCode = 404; response.end('{}'); return; }
    if (pathname.endsWith('/unavailable')) { response.statusCode = 503; response.end('{}'); return; }
    let body: unknown;
    if (pathname.endsWith('/catalog')) body = { organization, activities: ['rich', 'balanced', 'minimal'].map((mode) => ({ ...activity(mode).activity, special_offer_url: mode === 'rich' ? '/o/school/open-days/discovery' : null })) };
    else if (pathname.includes('/open-days/')) body = { organization, campaign: { slug: 'discovery', title: 'Дни на откритията', description: 'Запознайте се с нашите дейности.', starts_on: '2035-11-03', ends_on: '2035-11-05', image_url: null, days: [
      { date: '2035-11-04', activities: [{ activity: { slug: 'rich', title: 'Шахмат', category: 'Шахмат', summary: '', hero_image_url: null }, visit_offers: [offer, { ...offer, visit_offer_id: 'paid', session_id: 'paid-session', fee_type: 'paid', amount_cents: 1250, title: 'Работилница', fee_reason: 'Материали' }] }] },
      { date: '2035-11-05', activities: [{ activity: { slug: 'music', title: 'Музика', category: 'Музика', summary: '', hero_image_url: null }, visit_offers: [{ ...offer, visit_offer_id: 'music', session_id: 'music-session', starts_at: '2035-11-04T23:30:00Z', ends_at: '2035-11-05T00:30:00Z' }] }] },
    ] } };
    else body = activity(pathname.split('/').at(-1)!);
    response.setHeader('Content-Type', 'application/json'); response.end(JSON.stringify(body));
  });
  await new Promise<void>((resolve) => api.listen(18089, '127.0.0.1', resolve));
  web = spawn(process.execPath, ['node_modules/next/dist/bin/next', 'start', '-p', '3107'], { env: { ...process.env, API_BASE_URL: 'http://127.0.0.1:18089' }, stdio: 'pipe' });
  web.stdout?.on('data', (chunk) => { logs += chunk; }); web.stderr?.on('data', (chunk) => { logs += chunk; });
  await expect.poll(async () => { if (web.exitCode !== null) throw new Error(logs); try { return (await fetch(origin)).ok; } catch { return false; } }, { timeout: 60_000 }).toBe(true);
});
test.afterAll(async () => {
  if (web && web.exitCode === null) { const stopped = new Promise((resolve) => web.once('exit', resolve)); web.kill('SIGTERM'); await stopped; }
  if (api) await new Promise<void>((resolve) => api.close(() => resolve()));
});

test('catalog uses server labels, separate audience, two badges and campaign targets', async ({ page }) => {
  await page.goto(`${origin}/o/school`);
  const cards = page.getByRole('region', { name: 'Дейности' }).getByRole('article');
  await expect(cards).toHaveCount(3);
  await expect(cards.first().getByText('6–8 г. · I–II клас', { exact: true })).toBeVisible();
  await expect(cards.first().locator('[data-status-badges] > *')).toHaveCount(2);
  await expect(cards.first().getByText('От 0,00 € / посещение', { exact: true })).toBeVisible();
  await expect(cards.first().getByRole('link', { name: /Към деня на отворените врати/ })).toHaveAttribute('href', '/o/school/open-days/discovery');
});

test('multiple offers require a choice, preserve server states and use only the matching session location', async ({ page }) => {
  await page.goto(`${origin}/o/school/activities/balanced`);
  await page.getByRole('link', { name: 'Изберете посещение', exact: true }).click();
  await expect(page.locator('input[type="radio"]:checked')).toHaveCount(0);
  await page.getByRole('radio', { name: /Следваща среща/ }).check();
  await expect(page.getByRole('status')).toContainText('Следваща среща');
  const offers = page.getByRole('region', { name: 'Пробни занимания и посещения' });
  await expect(offers.getByText('Местата са заети — има списък на чакащите', { exact: true })).toBeVisible();
  await expect(offers.getByText('От 12,50 € / посещение', { exact: true })).toBeVisible();
  await expect(offers.getByRole('article').nth(1).getByRole('link', { name: /Как да стигнете/ })).toHaveCount(0);
  await expect(offers.getByRole('article').first().getByRole('link', { name: /Как да стигнете/ })).toHaveAttribute('href', 'https://maps.example/safe');
  await expect(page.getByRole('link', { name: /Запиши|Вход/ })).toHaveCount(0);
  await expect(page.locator('[data-category-art="chess"]').first()).toBeVisible();
  await expect(page.getByRole('img', { name: /Шахмат —/ })).toHaveCount(0);
  await page.getByRole('radio').first().focus();
  await page.keyboard.press('ArrowDown');
  await expect(page.getByRole('radio', { name: /Следваща среща/ })).toBeChecked();
});

test('catalog and campaign remain balanced at phone, tablet and desktop sizes', async ({ page }, testInfo) => {
  for (const [name, route] of [['catalog', '/o/school'], ['campaign', '/o/school/open-days/discovery']]) {
    for (const width of [390, 768, 820, 1024, 1440]) {
      await page.setViewportSize({ width, height: 1000 });
      await page.goto(`${origin}${route}`);
      await expect(page.getByRole('heading', { level: 1, name: name === 'catalog' ? 'Открийте занимание, което ще вдъхнови детето ви' : 'Дни на откритията' })).toBeVisible();
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
      if (name === 'catalog' && width >= 768 && width <= 1024) {
        const cards = page.getByRole('region', { name: 'Дейности' }).getByRole('article');
        expect((await cards.last().boundingBox())!.width).toBeGreaterThan((await cards.first().boundingBox())!.width * 1.8);
      }
      await page.screenshot({ path: testInfo.outputPath(`${name}-${width}.png`), fullPage: true });
    }
  }
});

test('minimal content has no empty optional section or actionable closed offer', async ({ page }) => {
  await page.goto(`${origin}/o/school/activities/minimal`);
  await expect(page.getByRole('heading', { level: 1, name: 'Шахмат — мисли с въображение' })).toBeVisible();
  await expect(page.getByRole('heading', { name: /За дейността|Какво развива|Как протичат|Условия за участие|Нашият екип|Моменти|Често задавани/ })).toHaveCount(0);
  await expect(page.getByRole('link', { name: /Как да стигнете/ })).toHaveCount(0);
  await expect(page.getByRole('region', { name: 'Пробни занимания и посещения' }).getByText('Записването е затворено')).toBeVisible();
  await expect(page.getByRole('radio')).toHaveCount(0);
});

test('campaign keeps actual local day/activity groups and mixed prices without inventing location', async ({ page }) => {
  await page.goto(`${origin}/o/school/open-days/discovery`);
  await expect(page.getByRole('heading', { level: 1, name: 'Дни на откритията' })).toBeVisible();
  await expect(page.getByRole('heading', { level: 2, name: /4 ноември 2035/ })).toBeVisible();
  await expect(page.getByRole('heading', { level: 2, name: /5 ноември 2035/ })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Музика', exact: true })).toBeVisible();
  await expect(page.getByText('Безплатно', { exact: true })).toHaveCount(2);
  await expect(page.getByText('12,50 € / посещение', { exact: true })).toBeVisible();
  await expect(page.locator('article time').filter({ hasText: '08:30–09:30' })).toHaveCount(3);
  await expect(page.getByRole('link', { name: /Как да стигнете/ })).toHaveCount(0);
  await expect(page.getByText(/МЯСТО|Мястото ще бъде уточнено|ул\. „Открития“/)).toHaveCount(0);
  await page.goto(`${origin}/o/school/open-days/inactive`);
  await expect(page.getByRole('heading', { name: 'Денят на отворените врати вече приключи' })).toBeVisible();
  await expect(page.getByRole('link', { name: /Всички дейности/ })).toBeVisible();
});

test('public service failure provides an understandable retry state', async ({ page }) => {
  await page.goto(`${origin}/o/school/open-days/unavailable`);
  await expect(page.getByRole('heading', { name: 'Не успяхме да заредим страницата' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Опитайте отново' })).toBeVisible();
});

for (const mode of ['rich', 'balanced', 'minimal']) {
  test(`${mode} responsive composition and keyboard access at all approved sizes`, async ({ page }, testInfo) => {
    const evidence = path.join(testInfo.outputDir, 'visual-qa'); await mkdir(evidence, { recursive: true });
    for (const width of [390, 768, 820, 1024, 1440]) {
      await page.setViewportSize({ width, height: 1000 });
      await page.goto(`${origin}/o/school/activities/${mode}`);
      await expect(page.getByRole('heading', { level: 1, name: 'Шахмат — мисли с въображение' })).toBeVisible();
      if (mode === 'balanced') await expect(page.locator('[data-category-art="chess"]').first()).toBeVisible();
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
      expect(await page.locator('main a, main button, main summary, main label').evaluateAll((elements) => elements.filter((el) => { const r = el.getBoundingClientRect(); return r.width > 0 && (r.width < 44 || r.height < 44); }).map((el) => el.textContent))).toEqual([]);
      await page.keyboard.press('Tab');
      await expect(page.getByRole('link', { name: /Всички дейности/ })).toBeFocused();
      await page.screenshot({ path: path.join(evidence, `${mode}-${width}.png`), fullPage: true });
    }
  });
}
