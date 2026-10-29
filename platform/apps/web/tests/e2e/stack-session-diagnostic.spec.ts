import { execSync } from 'node:child_process';
import { createDecipheriv } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { expect, request, test, type APIRequestContext } from '@playwright/test';

// Temporary diagnostic (not for merge): why the API session forgets writes.
const api = 'http://127.0.0.1:8080';
const front = { Origin: 'http://127.0.0.1:3000', Referer: 'http://127.0.0.1:3000/' };
const sql = (query: string) => execSync(`psql -h 127.0.0.1 -U progressive -d progressive -tAc "${query}"`, { env: { ...process.env, PGPASSWORD: 'progressive-local-only' } }).toString().trim().replace(/\n/g, ' | ');

const appKey = Buffer.from((readFileSync(resolve(__dirname, '../../../api/.env'), 'utf8').match(/^APP_KEY=base64:(.+)$/m) ?? ['', ''])[1], 'base64');
function decryptCookie(raw: string): string {
  try {
    const payload = JSON.parse(Buffer.from(decodeURIComponent(raw), 'base64').toString('utf8')) as { iv: string; value: string };
    const decipher = createDecipheriv('aes-256-cbc', appKey, Buffer.from(payload.iv, 'base64'));
    const plain = Buffer.concat([decipher.update(Buffer.from(payload.value, 'base64')), decipher.final()]).toString('utf8');
    const id = plain.includes('|') ? plain.split('|')[1] : plain;
    return id.slice(0, 6);
  } catch (error) { return `ERR(${error instanceof Error ? error.message.slice(0, 30) : 'x'})`; }
}

async function xsrf(context: APIRequestContext): Promise<string> {
  const cookie = (await context.storageState()).cookies.find((item) => item.name === 'XSRF-TOKEN');
  return cookie ? decodeURIComponent(cookie.value) : '';
}

test('C: which requests start a new session', async () => {
  const log: string[] = [];
  const jar = await request.newContext({ baseURL: api, extraHTTPHeaders: { ...front, Accept: 'application/json' } });
  const step = async (label: string, run: () => Promise<{ status(): number; headersArray(): { name: string; value: string }[] }>) => {
    const response = await run();
    const sessionHeaders = response.headersArray().filter((h) => h.name.toLowerCase() === 'set-cookie' && h.value.startsWith('laravel-session='));
    const cookies = sessionHeaders.map((h) => `${decryptCookie(h.value.slice('laravel-session='.length).split(';')[0])}{${h.value.split(';').slice(1).map((part) => part.trim().split('=')[0]).join(',')}}`).join('+') || '-';
    const jarState = (await jar.storageState()).cookies.filter((c) => c.name === 'laravel-session').map((c) => `${decryptCookie(c.value)}@${c.domain}${c.path}`).join('&');
    log.push(`${label}:${response.status()} set=${cookies} jar=${jarState} rows=[${sql("select left(id,6)||':'||coalesce(left(user_id::text,4),'-')||':'||length(payload)||':'||(extract(epoch from now())::int - last_activity) from sessions order by id")}]`);
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
