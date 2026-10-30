import { expect, test, type Page } from '@playwright/test';
import type { PaymentTermsSet, PaymentTermsWorkspace, Rules } from '../../src/features/payment-terms/api';

// The server's ready-made sets for two ways of paying (app/Domain/PaymentTerms/Rules/Presets.php).
const bySessions = { mode: 'by_sessions', cutoff_day: null, kept_until_cutoff: null, kept_after_cutoff: null };
const makeup = { enabled: true, within_days: 14, notice_hours: 3, per_month: null, only_if_space: false };
const monthly: Record<'flexible' | 'standard' | 'strict', Rules> = {
  flexible: {
    start: bySessions, end: { ...bySessions, notice_days: 0 },
    pause: { notice_days: 3, kept_on_time: 0, kept_late: 50, free_days_per_school_year: 92, document_required: false, min_days: 0 },
    absences: { tiers: [{ up_to: null, kept: 100 }] }, makeup: { ...makeup, within_days: 30 }, refund: 'parent_choice',
  },
  standard: {
    start: bySessions, end: { mode: 'cutoff', cutoff_day: 15, kept_until_cutoff: 50, kept_after_cutoff: 100, notice_days: 0 },
    pause: { notice_days: 7, kept_on_time: 0, kept_late: 50, free_days_per_school_year: 61, document_required: false, min_days: 0 },
    absences: { tiers: [{ up_to: 3, kept: 100 }, { up_to: null, kept: 50 }] }, makeup, refund: 'parent_choice',
  },
  strict: {
    start: { mode: 'cutoff', cutoff_day: 15, kept_until_cutoff: 100, kept_after_cutoff: 50 },
    end: { mode: 'full_month', cutoff_day: null, kept_until_cutoff: null, kept_after_cutoff: null, notice_days: 0 },
    pause: { notice_days: 0, kept_on_time: 50, kept_late: 50, free_days_per_school_year: 183, document_required: true, min_days: 21 },
    absences: { tiers: [{ up_to: null, kept: 100 }] }, makeup: { ...makeup, within_days: 7, notice_hours: 0, only_if_space: true }, refund: 'parent_choice',
  },
};
const self = {
  flexible: { absence: true, pause_weeks: 4, end: true, cancel_visit: true },
  standard: { absence: true, pause_weeks: 2, end: false, cancel_visit: true },
  strict: { absence: true, pause_weeks: 0, end: false, cancel_visit: true },
};
const visit = (tiers: Array<[number, number]>): Rules => ({ cancellation: { tiers: tiers.map(([min_hours, kept]) => ({ min_hours, kept })) }, no_show_kept: 100, refund: 'parent_choice' });

function workspace(canManage: boolean, saved: PaymentTermsSet[] = []): PaymentTermsWorkspace {
  return {
    payment_terms: saved,
    billing_kinds: [
      { value: 'monthly', label: 'Месечна такса', presets: {
        flexible: { label: 'Гъвкави', rules: monthly.flexible, self_service: self.flexible },
        standard: { label: 'Стандартни', rules: monthly.standard, self_service: self.standard },
        strict: { label: 'Строги', rules: monthly.strict, self_service: self.strict },
      } },
      { value: 'visit', label: 'Пробно занимание или еднократно посещение', presets: {
        flexible: { label: 'Гъвкави', rules: visit([[12, 0], [0, 50]]), self_service: self.flexible },
        standard: { label: 'Стандартни', rules: visit([[24, 0], [12, 50], [0, 100]]), self_service: self.standard },
        strict: { label: 'Строги', rules: visit([[48, 0], [0, 100]]), self_service: self.strict },
      } },
    ],
    in_marketplace: false,
    usage: { monthly: ['Футбол (6–8 г.)'] },
    can_manage: canManage,
    schema_version: 1,
  };
}

async function fixture(page: Page, options: { canManage?: boolean; reject?: boolean } = {}) {
  const writes: Array<{ method: string; body: Record<string, unknown> }> = [];
  await page.route('**/backend/**', async (route) => {
    const path = new URL(route.request().url()).pathname;
    const method = route.request().method();
    if (path.endsWith('/me')) return route.fulfill({ json: { user: { id: 'user-1', name: 'Мария Петрова', email: 'owner@example.test' } } });
    if (path.endsWith('/organizations')) return route.fulfill({ json: { organizations: [{ id: 'org-1', name: 'Школа', slug: 'school', status: 'active', role: options.canManage === false ? 'viewer' : 'organization_owner', permissions: ['view', 'catalog.view'] }] } });
    if (path.endsWith('/payment-terms') && method === 'GET') return route.fulfill({ json: workspace(options.canManage !== false) });
    if (path.includes('/payment-terms') && method !== 'GET') {
      const body = route.request().postDataJSON() as Record<string, unknown>;
      writes.push({ method, body });
      if (options.reject) {
        return route.fulfill({ status: 422, json: { message: 'The given data was invalid.', errors: { 'rules.end.cutoff_day': ['„ден от месеца“ трябва да е от 1 до 28.'] } } });
      }
      const set: PaymentTermsSet = {
        id: 'terms-1', billing_kind: 'monthly', billing_kind_label: 'Месечна такса', name: String(body.name), preset: 'custom', preset_label: 'Собствени',
        is_default: true, status: 'active', version: 1, self_service: body.self_service as PaymentTermsSet['self_service'],
        current_version: { id: 'version-1', number: 1, schema_version: 1, rules: body.rules as Rules, created_at: '2026-10-30T12:00:00Z' },
        marketplace_violations: {},
      };
      return route.fulfill({ status: 201, json: { payment_terms: set } });
    }
    return route.fulfill({ json: {} });
  });
  return writes;
}

test('the owner starts from "Стандартни", changes a number and saves the organization\'s terms', async ({ page }) => {
  const writes = await fixture(page);
  await page.goto('/dashboard/settings/payment-terms');

  await expect(page.getByRole('heading', { name: 'Такси, отсъствия и отказ', level: 1 })).toBeVisible();
  await expect(page.getByRole('radio', { name: /^Стандартни/ })).toBeChecked();
  await expect(page.getByText('Ако последният ден на детето в групата е до 15-о число включително, за този месец плащате половината от месечната такса. Ако е след 15-о число, плащате цялата такса.')).toBeVisible();
  await expect(page.getByText('Ползва се при: Футбол (6–8 г.)')).toBeVisible();
  await expect(page.getByText('Организацията още не е запазила свои условия за този начин на плащане — важат „Стандартни“.', { exact: false })).toBeVisible();

  await page.getByLabel('Ден от месеца').first().fill('10');
  await expect(page.getByRole('radio', { name: /^Собствени/ })).toBeChecked();
  await expect(page.getByText('е до 10-о число включително', { exact: false })).toBeVisible();

  await page.getByRole('radio', { name: /^Строги/ }).check();
  await expect(page.getByText('Ако прекратите записването, за месеца, в който е последният ден на детето, плащате цялата месечна такса.')).toBeVisible();
  await page.getByRole('radio', { name: /^Стандартни/ }).check();
  await page.getByLabel('Дни предизвестие за пауза').fill('10');

  await page.getByRole('button', { name: 'Запази условията' }).click();
  await expect(page.getByText('Условията за „Месечна такса“ са запазени.')).toBeVisible();
  expect(writes).toHaveLength(1);
  expect(writes[0].method).toBe('POST');
  expect(writes[0].body).toMatchObject({ billing_kind: 'monthly', name: 'Условията на организацията', is_default: true, self_service: self.standard });
  expect((writes[0].body.rules as { pause: { notice_days: number } }).pause.notice_days).toBe(10);
});

test('a refused number is shown under its rule', async ({ page }) => {
  await fixture(page, { reject: true });
  await page.goto('/dashboard/settings/payment-terms');
  await page.getByLabel('Ден от месеца').first().fill('31');
  await page.getByRole('button', { name: 'Запази условията' }).click();

  await expect(page.getByText('Проверете отбелязаните полета.')).toBeVisible();
  await expect(page.getByRole('group', { name: 'Ако детето спре по средата на месеца' })).toContainText('„ден от месеца“ трябва да е от 1 до 28.');
});

test('a member who does not manage terms reads them without changing them', async ({ page }) => {
  await fixture(page, { canManage: false });
  await page.goto('/dashboard/settings/payment-terms');

  await expect(page.getByText('Само собственикът и администраторът могат да променят условията.', { exact: false })).toBeVisible();
  await expect(page.getByLabel('Ден от месеца').first()).toBeDisabled();
  await expect(page.getByRole('button', { name: 'Запази условията' })).toHaveCount(0);
});

for (const width of [390, 768, 1440]) {
  test(`fits ${width} px without sideways scrolling`, async ({ page }) => {
    await fixture(page);
    await page.setViewportSize({ width, height: 900 });
    await page.goto('/dashboard/settings/payment-terms');
    await expect(page.getByRole('heading', { name: 'Такси, отсъствия и отказ', level: 1 })).toBeVisible();

    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(0);
    await expect(page.getByRole('button', { name: 'Запази условията' })).toBeVisible();
    // Every number field on screen is big enough to tap (folded ones have no size).
    const small = await page.locator('input[type="number"]').evaluateAll((inputs) => inputs.filter((input) => {
      const height = input.getBoundingClientRect().height;
      return height > 0 && height < 44;
    }).length);
    expect(small).toBe(0);
  });
}
