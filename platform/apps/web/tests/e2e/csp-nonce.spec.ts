import { expect, test } from '@playwright/test';

const scriptPolicy = (policy: string) => policy.split('; ').find((part) => part.startsWith('script-src ')) ?? '';
const nonceIn = (policy: string) => scriptPolicy(policy).match(/'nonce-([^']+)'/)?.[1];

test('HTML has a fresh nonce on every request, including 404 pages, without shared caching', async ({ request }) => {
  const nonces = new Set<string>();
  for (const path of ['/login', '/login', '/dashboard/enrollments', '/missing-page-csp-check']) {
    const response = await request.get(path, {
      headers: { 'x-nonce': 'attacker-controlled', 'Content-Security-Policy': "script-src 'unsafe-inline'" },
    });
    expect(response.status()).toBe(path.startsWith('/missing-') ? 404 : 200);
    const policy = response.headers()['content-security-policy'];
    const nonce = nonceIn(policy);
    expect(nonce).toMatch(/^[A-Za-z0-9+/]{22}==$/);
    expect(scriptPolicy(policy)).not.toContain('unsafe-inline');
    expect(nonce).not.toBe('attacker-controlled');
    expect(response.headers()['cache-control']).toContain('no-store');
    expect(response.headers()['x-nonce']).toBeUndefined();
    expect(nonces.has(nonce!)).toBe(false);
    nonces.add(nonce!);
    const scripts = [...(await response.text()).matchAll(/<script\b([^>]*)>/g)];
    expect(scripts.length).toBeGreaterThan(0);
    for (const [, attributes] of scripts) expect(attributes).toContain(`nonce="${nonce}"`);
  }
});

test('the browser hydrates the login page but blocks an added inline script without its nonce', async ({ page }) => {
  const errors: string[] = [];
  page.on('pageerror', (error) => errors.push(error.message));
  await page.route('**/backend/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204 }));
  await page.route('**/backend/api/v1/auth/login', (route) => route.fulfill({ status: 422, json: { message: 'Synthetic rejection' } }));
  const response = await page.goto('/login');
  const nonce = nonceIn(response!.headers()['content-security-policy']);
  await expect(page.getByRole('button', { name: 'Вход', exact: true })).toBeVisible();
  await page.getByLabel('Имейл').fill('owner@example.test');
  await expect(page.getByLabel('Имейл')).toHaveValue('owner@example.test');
  await page.getByLabel('Парола').fill('synthetic-password');
  await page.getByRole('button', { name: 'Вход', exact: true }).click();
  await expect(page.locator('form').getByRole('alert')).toHaveText('Грешен имейл или парола.');
  const scripts = await page.locator('script').evaluateAll((elements) => elements.map((element) => {
    const script = element as HTMLScriptElement;
    return { nonce: script.nonce, src: script.src, code: script.textContent?.trim() };
  }));
  expect(scripts.length).toBeGreaterThan(0);
  for (const script of scripts) {
    if (script.src && !script.nonce) {
      // Dev HMR can insert same-origin files; 'self' allows them without a nonce.
      expect(new URL(script.src).origin).toBe(new URL(page.url()).origin);
    } else if (script.code || script.nonce) {
      expect(script.nonce).toBe(nonce);
    }
  }

  await page.evaluate(() => {
    document.addEventListener('securitypolicyviolation', (event) => {
      if (event.effectiveDirective === 'script-src-elem' && event.blockedURI === 'inline') {
        document.documentElement.dataset.cspBlocked = 'yes';
      }
    });
    const script = document.createElement('script');
    script.textContent = "document.documentElement.dataset.cspExecuted = 'yes'";
    document.body.append(script);
  });
  await expect.poll(() => page.evaluate(() => document.documentElement.dataset.cspBlocked)).toBe('yes');
  expect(await page.evaluate(() => document.documentElement.dataset.cspExecuted)).toBeUndefined();
  expect(errors).toEqual([]);
});
