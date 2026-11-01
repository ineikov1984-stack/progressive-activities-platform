import { expect, test, type Page } from '@playwright/test';
import type { ActivityDraft } from '../../src/features/catalog/admin/api';

const widths = [360, 390, 768, 1024, 1440];
const id = (n: number) => `00000000-0000-4000-8000-${String(n).padStart(12, '0')}`;
const activity: ActivityDraft = {
  id: id(2), slug: 'demo-chess', status: 'draft', version: 1, title: 'Шахмат и логически предизвикателства',
  category: 'Наука', summary: 'Демонстрационна дейност с измислени данни за проверка на подредбата.', age_label: '1.–4. клас',
  sections: { about: 'Играем и учим заедно.', benefits: ['Логическо мислене'], gallery: [], faq: [] },
  offering: { id: id(3), version: 1, location_id: null, lead_instructor_profile_id: null, price_options: [] },
};
const group = { id: id(4), name: 'Следобедна група за начинаещи', activity_id: activity.id, activity_title: activity.title,
  capacity_max: 12, enrolled_for_period: 8, waitlist_count: 5, status: 'published', enrollment_approval_mode: 'manual',
  schedule: { frequency: 'weekly', slots: [{ weekday: 2, starts_at: '16:00', duration_minutes: 60 }] }, location_name: 'Демонстрационна учебна зала' };
const applications = Array.from({ length: 5 }, (_, i) => ({
  id: id(20 + i), status: 'submitted', version: 1, scope: 'full_period', source: 'organization_catalog',
  child: { id: id(40 + i), name: `Демо Дете ${i + 1}`, class_label: '3 клас' },
  group: { id: group.id, name: group.name, capacity_max: 12, places_taken: 8 }, activity: { id: activity.id, title: activity.title },
  session_starts_at: null, visit_offer_title: null, price: { amount_cents: 4500, currency: 'EUR', unit: 'month' }, public_reason: null, submitted_at: '2026-11-01T10:00:00Z',
}));

async function fixture(page: Page) {
  const errors: string[] = [];
  page.on('pageerror', (error) => errors.push(error.message));
  page.on('console', (message) => { if (message.type() === 'error') errors.push(message.text()); });
  await page.clock.setFixedTime(new Date('2026-11-02T10:00:00Z'));
  await page.route('**/backend/**', async (route) => {
    const url = new URL(route.request().url()); const path = url.pathname;
    const permissions = ['catalog.view', 'catalog.manage', 'catalog.publish', 'enrollment.view', 'enrollment.manage', 'terms.view', 'team.view'];
    let json: unknown;
    if (path.endsWith('/me')) json = { user: { id: id(1), name: 'Демо Собственик', email: 'owner@example.test' } };
    else if (path.endsWith('/organizations')) json = { organizations: [{ id: id(5), name: 'Демонстрационна школа', slug: 'demo-school', status: 'active', role: 'organization_owner', permissions }] };
    else if (path.endsWith('/context/organization')) json = { access: { role: 'organization_owner', scope: 'all', abilities: [...permissions, 'enrollment.enroll'], groups: [], activities: [] } };
    else if (path.endsWith('/enrollment/applications')) json = { applications: applications.map((item) => ({ ...item, status: url.searchParams.get('status') ?? 'submitted' })), total: 5, has_more: false,
      facets: { activities: [{ id: activity.id, title: activity.title, count: 5 }], groups: [{ id: group.id, name: group.name, activity_id: activity.id, count: 5 }] } };
    else if (path.endsWith('/enrollment/groups')) json = { groups: [group] };
    else if (path.endsWith('/roster')) json = { group: { ...group, remaining: 4, waitlist_enabled: true },
      enrollments: applications.map((item, i) => ({ id: id(60 + i), child: item.child, scope: 'full_period', status: 'active', version: 1, starts_on: '2026-10-20', ends_on: null, paused_today: false, pauses: [], skipped_sessions: [] })),
      waitlist: applications.map((item) => ({ ...item, status: 'waitlisted' })), upcoming_sessions: [] };
    else if (path.endsWith('/catalog/activities')) json = { activities: [activity] };
    else if (path.endsWith(`/catalog/activities/${activity.id}`)) json = { activity };
    else if (path.endsWith(`/catalog/activities/${activity.id}/groups`)) json = { groups: [] };
    else if (path.endsWith('/catalog/profile')) json = { profile: { public_name: 'Демонстрационна школа', slug: 'demo-school' } };
    else if (path.endsWith('/catalog/locations')) json = { locations: [] };
    else if (path.endsWith('/catalog/instructors')) json = { instructors: [] };
    else if (path.endsWith('/team')) json = { members: [] };
    else if (path.endsWith('/payment-terms')) json = { payment_terms: [], billing_kinds: [], usage: {}, in_marketplace: false, can_manage: true, schema_version: 1 };
    else if (path.endsWith('/catalog/visit-offers')) json = { visit_offers: [] };
    else if (path.endsWith('/catalog/open-day-campaigns')) json = { campaigns: [] };
    else { errors.push(`Unhandled API request: ${route.request().method()} ${path}`); return route.fulfill({ status: 404, json: { message: 'Missing synthetic fixture' } }); }
    return route.fulfill({ json });
  });
  return errors;
}

async function layout(page: Page) {
  try {
    await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(1);
  } catch {
    const outside = await page.locator('body *').evaluateAll((elements) => elements.flatMap((el) => {
      const r = el.getBoundingClientRect();
      return el.getRootNode() === document && r.right > innerWidth + 1 && r.width > 0
        ? [{ tag: el.tagName, class: el.className, right: r.right, width: r.width, text: el.textContent?.trim().slice(0, 60) }] : [];
    }));
    throw new Error(`Horizontal overflow: ${JSON.stringify(outside.slice(-14))}`);
  }
  const smallTargets = await page.locator('button, a[href], input:not([type="hidden"]), select, textarea').evaluateAll((elements) => elements.flatMap((el) => {
    const rect = el.getBoundingClientRect();
    if (el.getRootNode() !== document || !el.checkVisibility() || rect.right <= 0 || rect.left >= innerWidth || el.closest('[inert]')) return [];
    // Native radio, checkbox and file inputs use the surrounding clickable label as their target.
    const label = el instanceof HTMLInputElement && ['checkbox', 'radio', 'file'].includes(el.type) ? el.labels?.[0] : null;
    const target = label?.getBoundingClientRect() ?? rect;
    return target.width > 0 && (target.width < 43.5 || target.height < 43.5)
      ? [{ text: el.getAttribute('aria-label') ?? el.textContent?.trim().slice(0, 60), width: target.width, height: target.height }] : [];
  }));
  expect(smallTargets, 'Every visible control needs a 44 × 44 target').toEqual([]);
}

async function keyboardFocus(page: Page) {
  await page.locator('body').click({ position: { x: 1, y: 1 } });
  for (let index = 0; index < 3; index++) {
    await page.keyboard.press('Tab');
    expect(await page.evaluate(() => {
      const el = document.activeElement;
      if (!(el instanceof HTMLElement) || el === document.body) return null;
      const style = getComputedStyle(el);
      return { keyboard: el.matches(':focus-visible'), outlined: style.outlineStyle !== 'none' && parseFloat(style.outlineWidth) > 0 && style.outlineColor !== 'rgba(0, 0, 0, 0)' };
    })).toEqual({ keyboard: true, outlined: true });
  }
}

for (const width of widths) {
  test(`enrollments keep their layout, targets and keyboard focus at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 1000 }); const errors = await fixture(page);
    await page.goto('/dashboard/enrollments');
    await expect(page.getByRole('heading', { name: 'Записвания', exact: true })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Демо Дете 1 Подробности', exact: true })).toBeVisible();
    await layout(page); await keyboardFocus(page);
    const filters = page.getByRole('button', { name: 'Търсене и филтри', exact: true });
    if (await filters.isVisible()) await filters.click();
    await layout(page);
    await page.getByRole('button', { name: 'Откажи Демо Дете 1', exact: true }).click(); await layout(page);
    await page.getByRole('button', { name: 'Назад', exact: true }).click();
    await page.getByRole('button', { name: 'Чакащи 5', exact: true }).click(); await layout(page);
    await page.getByRole('button', { name: 'Групи', exact: true }).click();
    await page.getByRole('button', { name: /Следобедна група за начинаещи/ }).click();
    await expect(page.getByText('Демо Дете 1', { exact: true }).first()).toBeVisible(); await layout(page);
    if (width < 720) {
      await page.getByRole('button', { name: 'Отвори менюто', exact: true }).click();
      await expect(page.getByRole('navigation', { name: 'Административна навигация' })).toBeVisible();
      await expect(page.locator('aside')).toBeInViewport({ ratio: 1 });
      await layout(page);
      if (width === 390) await page.screenshot({ path: test.info().outputPath('mobile-navigation-390.png'), fullPage: true });
      await page.keyboard.press('Escape');
    }
    expect(errors).toEqual([]);
  });
  test(`all activity editor steps keep their layout and targets at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 1000 }); const errors = await fixture(page);
    await page.goto(`/dashboard/activities/${activity.id}`);
    await expect(page.getByRole('heading', { level: 1, name: activity.title })).toBeVisible();
    const steps = page.getByRole('navigation', { name: 'Стъпки', exact: true }).getByRole('button',{includeHidden:true});
    await expect(steps).toHaveCount(9);
    for (let index = 0; index < 9; index++) {
      const choice=page.getByRole('combobox',{name:'Избери стъпка',exact:true});
      if(await choice.isVisible()){await choice.selectOption(String(index+1));await expect(choice).toHaveValue(String(index+1));}
      else {await steps.nth(index).click();await expect(steps.nth(index)).toHaveAttribute('aria-current','step');}
      await layout(page);
      if(index===5){const help=page.locator('summary').filter({hasText:'Кой имейл да посоча?'});await help.focus();await help.press('Enter');await expect(page.getByText('Попълнен е имейлът на организацията. Сменете го, ако за тази дейност отговаря друг човек.',{exact:true})).toBeVisible();await layout(page);if([390,768,1440].includes(width))await page.screenshot({path:test.info().outputPath(`editor-help-${width}.png`),fullPage:true});await help.press('Space');}
      if ([390, 1440].includes(width) && [0, 7].includes(index)) await page.screenshot({ path: test.info().outputPath(`editor-${index + 1}-${width}.png`), fullPage: true });
    }
    await keyboardFocus(page); expect(errors).toEqual([]);
  });
}

for (const width of [390, 768, 1440]) test(`activity category stays readable when selected at ${width}px`, async ({ page }) => {
  await page.setViewportSize({ width, height: 900 }); const errors = await fixture(page);
  await page.goto('/dashboard/activities'); await page.getByRole('button', { name: 'Създай нова дейност', exact: true }).click();
  const radios = page.getByRole('group', { name: 'Категория', exact: true }).getByRole('radio'); await expect(radios).toHaveCount(7);
  for (let i = 0; i < await radios.count(); i++) {
    const radio = radios.nth(i); await radio.locator('..').click(); await expect(radio).toBeChecked();
    const appearance = await radio.evaluate(input => { const label = (input as HTMLInputElement).labels![0]; const style = getComputedStyle(label); return { color: style.color, background: style.backgroundColor, height: label.getBoundingClientRect().height, padding: parseFloat(style.paddingLeft) }; });
    expect(appearance.color).not.toBe(appearance.background); expect(appearance.height).toBeGreaterThanOrEqual(44); expect(appearance.padding).toBeGreaterThan(0);
  }
  await layout(page); await page.screenshot({ path: test.info().outputPath(`categories-${width}.png`), fullPage: true }); expect(errors).toEqual([]);
});
