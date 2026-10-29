import { execSync } from 'node:child_process';
import { expect, request, test, type APIRequestContext } from '@playwright/test';

// Temporary diagnostic (not for merge): why the API session forgets writes.
const api = 'http://127.0.0.1:8080';
const front = { Origin: 'http://127.0.0.1:3000', Referer: 'http://127.0.0.1:3000/' };
const sql = (query: string) => execSync(`psql -h 127.0.0.1 -U progressive -d progressive -tAc "${query}"`, { env: { ...process.env, PGPASSWORD: 'progressive-local-only' } }).toString().trim().replace(/\n/g, ' | ');

async function xsrf(context: APIRequestContext): Promise<string> {
  const cookie = (await context.storageState()).cookies.find((item) => item.name === 'XSRF-TOKEN');
  return cookie ? decodeURIComponent(cookie.value) : '';
}

test('C: which requests start a new session', async () => {
  const log: string[] = [];
  const jar = await request.newContext({ baseURL: api, extraHTTPHeaders: { ...front, Accept: 'application/json' } });
  const step = async (label: string, run: () => Promise<{ status(): number; headersArray(): { name: string; value: string }[] }>) => {
    const response = await run();
    const cookies = response.headersArray().filter((h) => h.name.toLowerCase() === 'set-cookie').map((h) => h.value.split('=')[0]).join('+') || '-';
    log.push(`${label}:${response.status()} set=${cookies} rows=${sql('select count(*) from sessions')} authed=${sql('select count(*) from sessions where user_id is not null')}`);
  };
  log.push(`start rows=${sql('select count(*) from sessions')}`);
  await step('csrf', () => jar.get('/sanctum/csrf-cookie'));
  await step('login', async () => jar.post('/api/v1/auth/login', { headers: { 'X-XSRF-TOKEN': await xsrf(jar) }, data: { email: 'owner@demo.progressive.local', password: 'progressive-demo-local-only' } }));
  await step('me1', () => jar.get('/api/v1/me'));
  await step('me2', () => jar.get('/api/v1/me'));
  await step('orgs', () => jar.get('/api/v1/organizations'));
  await step('me3', () => jar.get('/api/v1/me'));
  await step('select', async () => jar.post('/api/v1/context/organization', { headers: { 'X-XSRF-TOKEN': await xsrf(jar) }, data: { organization_id: '00000000-0000-4000-8000-000000000101' } }));
  await step('me4', () => jar.get('/api/v1/me'));
  log.push(`payload keys of newest: ${sql("select length(payload) from sessions order by last_activity desc limit 3")}`);
  log.push(`config: ${sql("select 1")}`);
  expect(log.join(' ;; ')).toBe('see message');
});
