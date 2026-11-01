import { expect, test, type Page } from '@playwright/test';
import { fixture, layout } from './attendance-fixture';
const id = (n: number) => `00000000-0000-4000-8000-${String(n).padStart(12, '0')}`;

async function setup(page: Page, options: { teacher?: boolean; failRoster?: boolean } = {}) {
  const f = await fixture(page, { office: !options.teacher });
  const groups = [
    { id: id(4), name: 'Група 1 · Начинаещи', activity_id: id(3), activity_title: 'Шахмат', activity_category: 'Логика и игри', status: 'published', capacity_max: 16, enrolled_for_period: 12, waitlist_count: 1, enrollment_approval_mode: 'manual', location_name: 'Зала „Слънце“', schedule: { frequency: 'weekly', slots: [{ weekday: 1, starts_at: '16:00', duration_minutes: 60 }] } },
    { id: id(5), name: 'Група 2 · Творци', activity_id: id(6), activity_title: 'Рисуване', activity_category: 'Изкуства', status: 'published', capacity_max: 8, enrolled_for_period: 2, waitlist_count: 0, enrollment_approval_mode: 'automatic', location_name: 'Ателие', schedule: { frequency: 'weekly', slots: [{ weekday: 3, starts_at: '17:00', duration_minutes: 60 }] } },
  ];
  let submitted = true, failRoster = !!options.failRoster;
  const waiting = true;
  const writes: string[] = [];
  const child = (n: number, name: string) => ({ id: id(n), child: { id: id(n + 100), name, class_label: '3 клас' }, scope: 'full_period', status: 'active', version: 1, starts_on: '2026-10-01', ends_on: null, pauses: [], skipped_sessions: [], moves_to: null });
  const children = [child(20, 'Ана Демова'), ...Array.from({ length: 11 }, (_, i) => child(21 + i, `Демо Дете ${i + 2}`))];
  const application = (status = 'submitted') => ({ id: id(70), status, version: 1, scope: 'full_period', child: { id: id(170), name: status === 'waitlisted' ? 'Зоя Демова' : 'Ново Демо', class_label: '3 клас' }, group: { id: id(4), name: groups[0].name, capacity_max: 16, places_taken: groups[0].enrolled_for_period }, activity: { id: id(3), title: 'Шахмат' }, submitted_at: '2026-11-01T10:00:00Z', price: { amount_cents: 4500, currency: 'EUR', unit: 'month' }, session_starts_at: null });
  await page.route('**/backend/api/v1/**', async route => {
    const url = new URL(route.request().url()), path = url.pathname;
    let json: unknown;
    if (path.endsWith('/context/organization')) json = { access: { role: options.teacher ? 'instructor' : 'organization_owner', scope: options.teacher ? 'own' : 'all', abilities: options.teacher ? ['view', 'enrollment.view_own'] : ['view', 'enrollment.view', 'enrollment.enroll', 'catalog.manage'], groups: [], activities: [] } };
    else if (path.endsWith('/enrollment/applications')) {
      const status = url.searchParams.get('status') ?? 'submitted';
      const apps = (status === 'submitted' ? submitted : waiting) ? [application(status)] : [];
      json = { applications: apps, total: apps.length, has_more: false };
    } else if (path.endsWith(`/enrollment/applications/${id(70)}/review`)) {
      writes.push('review'); submitted = false; groups[0].enrolled_for_period++; children.push(child(70, 'Ново Демо')); json = { application: { ...application(), status: 'approved', version: 2 } };
    } else if (path.endsWith('/enrollment/groups')) json = { groups: options.teacher ? groups.map(({ id, name, activity_id, activity_title, schedule, location_name }) => ({ id, name, activity_id, activity_title, schedule, location_name })) : groups };
    else if (path.endsWith('/roster')) {
      if (failRoster) { return route.fulfill({ status: 503, json: { message: 'Списъкът не се зареди.' } }); }
      const group = groups.find(g => path.includes(g.id))!;
      const rosterChildren = group.id === id(4) ? children : [child(50, 'Елена Творческа'), child(51, 'Борис Творчески')];
      json = { group: options.teacher ? { id: group.id, name: group.name } : { ...group, remaining: group.capacity_max - group.enrolled_for_period }, enrollments: rosterChildren, ...(!options.teacher ? { waitlist: group.id === id(4) && waiting ? [application('waitlisted')] : [] } : {}), upcoming_sessions: [] };
    } else if (path.endsWith('/approval')) {
      writes.push('approval'); groups[0].enrollment_approval_mode = route.request().postDataJSON().enrollment_approval_mode; json = { group: groups[0] };
    } else if (path.endsWith(`/enrollment/enrollments/${id(20)}`)) json = { kind: 'enrollment', child: { id: id(120), name: 'Ана Демова', class_label: '3 клас', age: 9 }, contact_status: 'not_allowed', contact: null, parent_note: null, records: [], history: null, enrollment: { ...children[0], activity: { id: id(3), title: 'Шахмат' }, group: { id: id(4), name: groups[0].name }, session_starts_at: null, price: { amount_cents: 4500, currency: 'EUR', unit: 'month' }, next_session_at: null, paused_today: false, transferred_from: null } };
    else return route.fallback();
    return route.fulfill({ json });
  });
  return { ...f, writes, allowRoster: () => { failRoster = false; } };
}

for (const width of [390, 768, 1440]) test(`group workspace keeps context and child search at ${width}px`, async ({ page }) => {
  await page.setViewportSize({ width, height: 1000 }); const f = await setup(page);
  await page.goto('/dashboard/enrollments?view=groups');
  await expect(page.getByRole('button', { name: 'Виж децата — Шахмат · Група 1 · Начинаещи', exact: true })).toBeVisible();
  await layout(page); await page.screenshot({ path: test.info().outputPath(`groups-${width}.png`), fullPage: true });
  await page.getByRole('button', { name: 'Виж децата — Шахмат · Група 1 · Начинаещи', exact: true }).click();
  await expect(page).toHaveURL(new RegExp(`group=${id(4)}`));
  await expect(page.getByRole('heading', { name: groupsName(), exact: true })).toBeFocused();
  await expect(page.getByRole('button', { name: 'Ана Демова Подробности', exact: true })).toBeVisible();
  await expect(page.getByRole('radio', { name: /Ръчно от екипа/ })).toHaveCount(0);
  await page.getByLabel('Търси дете в групата').fill('Ана');
  const child = page.getByRole('button', { name: 'Ана Демова Подробности', exact: true });
  await child.click(); await expect(page.getByRole('dialog')).toBeVisible();
  await expect(page.getByRole('dialog').getByRole('heading', { name: 'Ана Демова', exact: true })).toBeVisible();
  await page.keyboard.press('Escape'); await expect(child).toBeFocused();
  await expect(page.getByLabel('Търси дете в групата')).toHaveValue('Ана');
  await page.getByLabel('Търси дете в групата').fill(''); await layout(page);
  await page.evaluate(() => window.scrollTo(0, 0));
  await page.screenshot({ path: test.info().outputPath(`selected-group-${width}.png`), fullPage: false });
  if (width === 390) {
    await page.getByRole('button', { name: 'Демо Дете 10 Подробности', exact: true }).scrollIntoViewIfNeeded();
    const choice = page.getByLabel('Избрана група', { exact: true });
    await expect(choice).toBeInViewport();
    await page.screenshot({ path: test.info().outputPath('selected-group-scroll-390.png') });
    await page.evaluate(() => window.scrollTo(0, 0));
  }
  await page.getByRole('button', { name: 'Чакащи · 1', exact: true }).click();
  await expect(page.getByRole('button', { name: 'Зоя Демова Подробности', exact: true })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Ана Демова Подробности', exact: true })).toHaveCount(0);
  await page.getByRole('button', { name: 'Настройки', exact: true }).click();
  await page.getByRole('radio', { name: /Автоматично, ако има място/ }).check();
  await expect(page.getByText(/^Записано. Новите заявки се одобряват сами/)).toBeVisible();
  expect(f.writes).toContain('approval');
  await page.getByRole('button', { name: 'Деца · 12', exact: true }).click();
  await page.getByRole('button', { name: 'Настройки', exact: true }).click();
  await expect(page.getByRole('radio', { name: /Автоматично, ако има място/ })).toBeChecked();
  await page.getByRole('button', { name: 'Деца · 12', exact: true }).click();
  if (width < 1000) {
    await page.getByLabel('Избрана група', { exact: true }).selectOption(id(5));
    await expect(page.getByRole('heading', { name: 'Група 2 · Творци', exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Ана Демова Подробности', exact: true })).toHaveCount(0);
  }
  await page.getByRole('button', { name: '← Всички групи', exact: true }).click();
  await page.getByLabel('Дейност, група или място').fill('шахмат');
  await expect(page.getByRole('button', { name: /Виж децата — Рисуване/ })).toHaveCount(0);
  await page.getByRole('button', { name: /^Виж децата — Шахмат/ }).click();
  await expect(page).toHaveURL(new RegExp(`group=${id(4)}`));
  await page.goBack();
  await expect(page.getByLabel('Дейност, група или място')).toHaveValue('шахмат');
  expect(f.errors).toEqual([]);
});
function groupsName() { return 'Група 1 · Начинаещи'; }

test('teacher keeps the own-group list without waiting, settings, prices or decisions', async ({ page }) => {
  const f = await setup(page, { teacher: true }); await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(`/dashboard/enrollments?view=groups&group=${id(4)}`);
  await expect(page.getByRole('heading', { name: 'Моите групи', exact: true })).toBeVisible();
  await expect(page.getByRole('heading', { name: groupsName(), exact: true })).toBeVisible();
  await expect(page.getByRole('button', { name: /Чакащи|Настройки|Промени|Одобри/ })).toHaveCount(0);
  await expect(page.getByText(/undefined|NaN|45,00/)).toHaveCount(0); await layout(page); expect(f.errors).toEqual([]);
});

test('retry roster and refresh cached counts after approving an application', async ({ page }) => {
  const f = await setup(page, { failRoster: true });
  await page.goto(`/dashboard/enrollments?view=groups&group=${id(4)}`);
  await expect(page.getByRole('alert').filter({ hasText: 'Списъкът не се зареди.' })).toBeVisible();
  f.allowRoster();
  await page.getByRole('button', { name: 'Опитай отново', exact: true }).click();
  await expect(page.getByRole('button', { name: 'Ана Демова Подробности', exact: true })).toBeVisible();
  await page.getByRole('button', { name: /^Заявки/ }).click();
  await page.getByRole('button', { name: 'Одобри Ново Демо', exact: true }).click();
  await expect(page.getByRole('status').filter({ hasText: 'Ново Демо е записано' })).toBeVisible();
  await page.getByRole('button', { name: 'Групи', exact: true }).click();
  await expect(page.getByText('13 от 16 места', { exact: true })).toBeVisible();
  expect(f.errors).toEqual([]);
});
