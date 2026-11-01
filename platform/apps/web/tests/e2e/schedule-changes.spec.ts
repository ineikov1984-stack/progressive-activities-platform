import { expect, test, type Page } from '@playwright/test';
import { mkdir } from 'node:fs/promises';
import path from 'node:path';

const id = (n: number) => `00000000-0000-4000-8000-${String(n).padStart(12, '0')}`;
async function openSchedule(page: Page, options: { manage?: boolean; retry?: boolean; warning?: boolean; conflict?: boolean; cancelled?: boolean; previewConflict?: boolean; moved?: boolean } = {}) {
  const manage = options.manage !== false;
  const sessions = [18, 19].map((day, i) => ({ id: id(i + 10), starts_at: `2026-11-${day}T15:30:00+00:00`, ends_at: `2026-11-${day}T16:30:00+00:00`,
    version: 1, status: options.cancelled ? 'cancelled' : 'scheduled', moved: !!options.moved, moved_from: options.moved ? '2026-11-17T15:30:00+00:00' : null, extra: false, group_id: id(2), activity_id: id(3), location_id: id(4), instructors: [], capacity: 10, taken: 8, waiting: 0 }));
  const board = { sessions, groups: [{ id: id(2), name: 'Група 2', activity_id: id(3), age_label: '3–4 клас' }],
    activities: [{ id: id(3), title: 'Шахмат', color: 0 }], locations: [{ id: id(4), name: 'Зала „Слънце“' }], instructors: [], abilities: { [id(2)]: { manage, children: true } } };
  let previews = 0;
  const applies: { body: Record<string, unknown>; key: string | undefined }[] = [];
  const warning = (n: number) => ({ type: 'exclusive_location', session_id: sessions[0].id, other_session_id: id(n), needs_confirmation: true, message: `Залата е заета от друга група ${n}.` });
  await page.clock.setFixedTime(new Date('2026-11-16T08:00:00Z'));
  await page.context().addCookies([{ name: 'XSRF-TOKEN', value: 'synthetic-token', domain: '127.0.0.1', path: '/' }]);
  await page.route('**/backend/api/v1/**', async (route) => {
    const url = new URL(route.request().url()); const pathname = url.pathname;
    if (pathname.endsWith('/me')) return route.fulfill({ json: { user: { id: id(1), name: 'Иван Тестов', email: 'owner@example.test' } } });
    if (pathname.endsWith('/organizations')) return route.fulfill({ json: { organizations: [{ id: id(5), name: 'Школа Звезда', slug: 'school', status: 'active', role: manage ? 'organization_owner' : 'instructor', permissions: ['catalog.view', 'enrollment.view'] }] } });
    if (pathname.endsWith('/context/organization')) return route.fulfill({ json: { access: { role: manage ? 'organization_owner' : 'instructor', scope: manage ? 'organization' : 'own', abilities: manage ? ['schedule.manage', 'enrollment.view', 'catalog.view'] : ['enrollment.view_own'], groups: [], activities: [] } } });
    if (pathname.endsWith('/catalog/locations')) return route.fulfill({ json: { locations: board.locations } });
    if (pathname.endsWith('/schedule/changes/preview')) {
      const body = route.request().postDataJSON();
      previews++;
      if (options.previewConflict && previews === 1) { sessions[0].version = 2; return route.fulfill({ status: 409, json: { changed: [sessions[0].id] } }); }
      return route.fulfill({ json: { sessions: sessions.filter((s) => !body.sessions || body.sessions.some((v: { id: string }) => v.id === s.id)).map((s) => ({ ...s, children: 8 })),
        totals: { sessions: body.sessions?.length ?? 2, groups: 1, children: 8, families: 8, letters: 8, teachers: 1, without_verified_email: 0, visits: 1, visit_applications: 0, skips_dropped: 0, not_attending_after: 0, starts_changed: 0 },
        skipped: { started: 0, cancelled: 0, same_location: 0 }, warnings: options.warning ? [warning(30)] : [] } });
    }
    if (pathname.endsWith('/schedule/changes')) {
      const body = route.request().postDataJSON(); applies.push({ body, key: route.request().headers()['idempotency-key'] });
      if (options.retry && applies.length === 1) return route.fulfill({ status: 500, json: {} });
      if (options.warning && applies.length === 1) return route.fulfill({ status: 422, json: { warnings: [warning(31)], message: 'Има ново предупреждение.' } });
      if (options.conflict && applies.length === 1) { sessions[0].version = 2; return route.fulfill({ status: 409, json: { changed: [sessions[0].id] } }); }
      return route.fulfill({ status: 201, json: { change: { id: id(40), action: body.action, sessions: [{ id: sessions[0].id, version: 2 }] }, letters: { families: 8, teachers: 1, without_verified_email: 0 } } });
    }
    if (pathname.includes('/schedule/sessions/')) {
      const session = sessions.find((s) => pathname.endsWith(s.id)) ?? sessions[0];
      return route.fulfill({ json: { session: { ...session, children: [{ enrollment_id: id(50), name: 'Ани Тестова', class: '3 клас', state: 'active', scope: 'full_period' }] },
        group: board.groups[0], activity: board.activities[0], location: board.locations[0], abilities: { manage, children: true }, actions: manage ? options.cancelled ? ['restore'] : ['move', 'cancel'] : [], last_change: null } });
    }
    if (pathname.endsWith('/schedule')) return route.fulfill({ json: board });
    if (pathname.endsWith('/me/teaching')) return route.fulfill({ json: { groups: [] } });
    return route.fulfill({ json: { invitations: [], events: [] } });
  });
  await page.goto('/dashboard/schedule?view=cards&date=2026-11-18');
  await expect(page.getByRole('heading', { name: manage ? 'График' : 'Моят график', exact: true })).toBeVisible();
  await expect(page.locator('[data-session-id]').first()).toBeVisible();
  return { applies };
}

test('move keeps its key on retry and Escape returns focus to its opener', async ({ page }) => {
  const { applies } = await openSchedule(page, { retry: true });
  await page.locator('[data-session-id]').first().click();
  const opener = page.getByRole('button', { name: 'Премести', exact: true });
  await opener.click();
  await expect(page.getByRole('dialog', { name: 'Премести заниманието' })).toBeVisible();
  await page.keyboard.press('Escape'); await expect(opener).toBeFocused();
  await opener.click();
  const dialog = page.getByRole('dialog', { name: 'Премести заниманието' });
  await dialog.getByLabel('Причина', { exact: true }).fill('Залата е заета.');
  await dialog.getByLabel('Нов ден').fill('2026-11-20');
  await dialog.getByRole('button', { name: 'Премести', exact: true }).click();
  await expect(dialog.getByRole('alert')).toContainText('Промяната не се запази');
  await dialog.getByRole('button', { name: 'Премести', exact: true }).click();
  await expect(dialog).not.toBeVisible();
  expect(applies).toHaveLength(2); expect(applies[0].key).toBeTruthy(); expect(applies[1].key).toBe(applies[0].key);
  expect(applies[1].body.date).toBe('2026-11-20');
});

test('new warnings require a new explicit confirmation', async ({ page }) => {
  const { applies } = await openSchedule(page, { warning: true });
  await page.locator('[data-session-id]').first().click(); await page.getByRole('button', { name: 'Премести', exact: true }).click();
  const dialog = page.getByRole('dialog', { name: 'Премести заниманието' });
  await dialog.getByLabel('Причина', { exact: true }).fill('Смяна на часа.');
  const confirm = dialog.getByLabel('Знам, премести въпреки това');
  await confirm.check(); await dialog.getByRole('button', { name: 'Премести', exact: true }).click();
  await expect(confirm).not.toBeChecked(); await expect(dialog.getByRole('button', { name: 'Премести', exact: true })).toBeDisabled();
  await expect(dialog).toContainText('друга група 31');
  await confirm.check(); await dialog.getByRole('button', { name: 'Премести', exact: true }).click();
  await expect(dialog).not.toBeVisible();
  expect(applies[1].body.confirmed_warnings).toEqual([{ type: 'exclusive_location', session_id: id(10), other_session_id: id(31) }]);
});

test('selection persists across weeks and Escape leaves selection mode', async ({ page }) => {
  await openSchedule(page); await page.getByRole('button', { name: 'Избери', exact: true }).click();
  await page.getByRole('checkbox').first().click();
  await expect(page.getByRole('region', { name: 'Избрани занимания' })).toContainText('Избрани 1 занимания · 8 деца');
  await page.getByRole('button', { name: 'Следваща седмица' }).click();
  await expect(page.getByRole('region', { name: 'Избрани занимания' })).toContainText('Избрани 1');
  await page.keyboard.press('Escape'); await expect(page.getByRole('region', { name: 'Избрани занимания' })).not.toBeVisible();
});

for (const width of [390, 768, 1440]) {
  test(`holiday and move dialogs fit at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 1000 }); await openSchedule(page);
    await page.getByRole('button', { name: 'Почивен ден', exact: true }).click();
    const holiday = page.getByRole('dialog', { name: 'Почивен ден' });
    await holiday.getByLabel('Добави ден', { exact: true }).fill('2026-11-18');
    await holiday.getByRole('button', { name: '+ Добави ден', exact: true }).click();
    await holiday.getByLabel('Причина', { exact: true }).fill('Коледни празници');
    await expect(holiday.getByRole('button', { name: /Отмени .* занимания/ })).toBeEnabled();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    const bounds = await holiday.boundingBox(); expect(bounds?.width).toBeLessThanOrEqual(width);
    const controls = await holiday.locator('button:visible, select:visible, input:not([type=checkbox]):not([type=radio]):visible').evaluateAll((elements) => elements.map((e) => e.getBoundingClientRect().height));
    expect(controls.every((height) => height >= 44)).toBe(true);
    await mkdir(path.resolve('../../../outputs/screenshots'), { recursive: true });
    await page.screenshot({ path: path.resolve(`../../../outputs/screenshots/schedule-holiday-${width}.png`) });
    await page.keyboard.press('Escape');
    await page.locator('[data-session-id]').first().click(); await page.getByRole('button', { name: 'Премести', exact: true }).click();
    const move = page.getByRole('dialog', { name: 'Премести заниманието' });
    await move.getByLabel('Причина', { exact: true }).fill('Залата е заета за родителска среща.');
    await expect(move.getByRole('button', { name: 'Премести', exact: true })).toBeEnabled();
    await page.screenshot({ path: path.resolve(`../../../outputs/screenshots/schedule-move-${width}.png`) });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
  });
}

test('a teacher has no schedule change or selection controls', async ({ page }) => {
  await openSchedule(page, { manage: false });
  await expect(page.getByRole('button', { name: 'Почивен ден', exact: true })).toHaveCount(0);
  await expect(page.getByRole('button', { name: 'Избери', exact: true })).toHaveCount(0);
  await page.locator('[data-session-id]').first().click();
  await expect(page.getByRole('button', { name: 'Премести', exact: true })).toHaveCount(0);
});


test('conflict refreshes the selected version before the next attempt', async ({ page }) => {
  const { applies } = await openSchedule(page, { conflict: true });
  await page.locator('[data-session-id]').first().click();
  await page.getByRole('button', { name: 'Премести', exact: true }).click();
  const dialog = page.getByRole('dialog', { name: 'Премести заниманието' });
  await dialog.getByLabel('Причина', { exact: true }).fill('Смяна на часа.');
  await dialog.getByRole('button', { name: 'Премести', exact: true }).click();
  await expect(dialog.getByRole('alert')).toContainText('Графикът е променен междувременно');
  await dialog.getByRole('button', { name: 'Премести', exact: true }).click();
  await expect(dialog).not.toBeVisible();
  expect(applies[1].body.sessions).toEqual([{ id: id(10), version: 2 }]);
  expect(applies[1].key).toBe(applies[0].key);
});

for (const action of ['Отмени избраните', 'Смени залата']) {
  test(`bulk ${action} sends both selected sessions`, async ({ page }) => {
    const { applies } = await openSchedule(page);
    await page.getByRole('button', { name: 'Избери', exact: true }).click();
    await page.getByRole('checkbox').nth(0).click(); await page.getByRole('checkbox').nth(1).click();
    await page.getByRole('region', { name: 'Избрани занимания' }).getByRole('button', { name: action, exact: true }).click();
    const dialog = page.getByRole('dialog', { name: action });
    await dialog.getByLabel('Причина', { exact: true }).fill('Обновяване на графика.');
    if (action === 'Смени залата') await dialog.getByRole('combobox', { name: 'Място', exact: true }).selectOption('');
    await dialog.getByRole('button', { name: action, exact: true }).click();
    await expect(dialog).not.toBeVisible();
    expect(applies[0].body.sessions).toEqual([{ id: id(10), version: 1 }, { id: id(11), version: 1 }]);
    if (action === 'Смени залата') expect(applies[0].body.location_id).toBeNull();
    await expect(page.getByRole('region', { name: 'Избрани занимания' })).not.toBeVisible();
    await expect(page.getByRole('heading', { name: 'График', exact: true })).toBeFocused();
  });
}

test('holiday applies exactly the sessions from its preview', async ({ page }) => {
  const { applies } = await openSchedule(page);
  await page.getByRole('button', { name: 'Почивен ден', exact: true }).click();
  const dialog = page.getByRole('dialog', { name: 'Почивен ден' });
  await dialog.getByRole('button', { name: '+ Добави ден', exact: true }).click();
  await dialog.getByLabel('Причина', { exact: true }).fill('Празник.');
  await dialog.getByRole('button', { name: 'Отмени 2 занимания', exact: true }).click();
  await expect(dialog).not.toBeVisible();
  expect(applies[0].body.action).toBe('holiday');
  expect(applies[0].body.sessions).toEqual([{ id: id(10), version: 1 }, { id: id(11), version: 1 }]);
  expect(applies[0].body.days).toEqual(['2026-11-18']);
});

test('extra session inherits the group venue unless a venue is explicitly chosen', async ({ page }) => {
  const { applies } = await openSchedule(page);
  await page.getByRole('button', { name: '+ Допълнително занимание', exact: true }).click();
  const dialog = page.getByRole('dialog', { name: 'Допълнително занимание' });
  await dialog.getByRole('button', { name: 'Добави заниманието', exact: true }).click();
  await expect(dialog).not.toBeVisible();
  expect(applies[0].body.action).toBe('add'); expect(applies[0].body.group_id).toBe(id(2));
  expect(applies[0].body).not.toHaveProperty('location_id');
});

test('a cancelled session can be restored from its panel', async ({ page }) => {
  const { applies } = await openSchedule(page, { cancelled: true });
  await page.locator('[data-session-id]').first().click();
  await page.getByRole('button', { name: 'Възстанови', exact: true }).click();
  const dialog = page.getByRole('dialog', { name: 'Върни заниманието' });
  await dialog.getByRole('button', { name: 'Върни заниманието', exact: true }).click();
  await expect(dialog).not.toBeVisible(); expect(applies[0].body.action).toBe('restore');
});


test('a stale selection is refreshed when preview returns a conflict', async ({ page }) => {
  const { applies } = await openSchedule(page, { previewConflict: true });
  await page.locator('[data-session-id]').first().click();
  await page.getByRole('button', { name: 'Премести', exact: true }).click();
  const dialog = page.getByRole('dialog', { name: 'Премести заниманието' });
  await dialog.getByLabel('Причина', { exact: true }).fill('Смяна на часа.');
  await expect(dialog.getByRole('alert')).toContainText('Графикът е променен междувременно');
  await dialog.getByRole('button', { name: 'Премести', exact: true }).click();
  await expect(dialog).not.toBeVisible();
  expect(applies[0].body.sessions).toEqual([{ id: id(10), version: 2 }]);
});


test('invalid end time explains what to fix', async ({ page }) => {
  await openSchedule(page); await page.locator('[data-session-id]').first().click();
  await page.getByRole('button', { name: 'Премести', exact: true }).click();
  const dialog = page.getByRole('dialog', { name: 'Премести заниманието' });
  await dialog.getByLabel('До', { exact: true }).fill('10:00');
  await expect(dialog.getByRole('alert')).toContainText('Крайният час трябва да е след началния.');
  await expect(dialog.getByLabel('До', { exact: true })).toHaveAttribute('aria-invalid', 'true');
});


test('repeated moves keep the original time and returning to it clears the marker', async ({ page }) => {
  await openSchedule(page, { moved: true });
  await expect(page.locator('[data-session-id]').first()).toContainText('Преместено от вторник, 17 ноември · 17:30');
  await page.locator('[data-session-id]').first().click();
  await page.getByRole('button', { name: 'Премести', exact: true }).click();
  const dialog = page.getByRole('dialog', { name: 'Премести заниманието' });
  await dialog.getByLabel('Причина', { exact: true }).fill('Промяна на часа.');
  await expect(dialog).toContainText('В графика ще пише „Преместено от вторник, 17 ноември · 17:30“');
  await dialog.getByLabel('Нов ден').fill('2026-11-17');
  await expect(dialog).toContainText('Заниманието ще е в първоначалния си час, без знак за преместване.');
});

test('phone bulk actions sit above the persistent navigation', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await openSchedule(page);
  await page.getByRole('button', { name: 'Избери', exact: true }).click();
  await page.getByRole('checkbox').first().click();
  const actions = page.getByRole('region', { name: 'Избрани занимания' });
  await expect(actions).toBeVisible();
  const nav = page.getByRole('navigation', { name: 'Основно меню на телефон' });
  const barBox = await actions.boundingBox(), navBox = await nav.boundingBox();
  expect(barBox).not.toBeNull(); expect(navBox).not.toBeNull();
  expect(barBox!.y + barBox!.height).toBeLessThanOrEqual(navBox!.y + 1);
});
