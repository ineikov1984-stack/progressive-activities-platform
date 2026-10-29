import { execSync } from 'node:child_process';
import { createDecipheriv } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { expect, request, test, type APIRequestContext } from '@playwright/test';

// Temporary diagnostic (not for merge): why the API session forgets writes.
const api = 'http://127.0.0.1:8080';
const front = { Origin: 'http://127.0.0.1:3000', Referer: 'http://127.0.0.1:3000/' };
const sql = (query: string) => execSync(`psql -h 127.0.0.1 -U progressive -d progressive -tAc "${query}"`, { env: { ...process.env, PGPASSWORD: 'progressive-local-only' } }).toString().trim().replace(/\n/g, ' | ');
const appKey = Buffer.from((readFileSync(resolve(process.cwd(), '../api/.env'), 'utf8').match(/^APP_KEY=base64:(.+)$/m) ?? ['', ''])[1], 'base64');

function decryptCookie(raw: string): string {
  try {
    const payload = JSON.parse(Buffer.from(decodeURIComponent(raw), 'base64').toString('utf8')) as { iv: string; value: string };
    const decipher = createDecipheriv('aes-256-cbc', appKey, Buffer.from(payload.iv, 'base64'));
    const plain = Buffer.concat([decipher.update(Buffer.from(payload.value, 'base64')), decipher.final()]).toString('utf8');
    return (plain.includes('|') ? plain.split('|')[1] : plain).slice(0, 6);
  } catch (error) { return `ERR(${error instanceof Error ? error.message.slice(0, 30) : 'x'})`; }
}

async function xsrf(context: APIRequestContext): Promise<string> {
  const cookie = (await context.storageState()).cookies.find((item) => item.name === 'XSRF-TOKEN');
  return cookie ? decodeURIComponent(cookie.value) : '';
}

test('E: decrypt session cookies per request', async () => {
  const log: string[] = [];
  const jar = await request.newContext({ baseURL: api, extraHTTPHeaders: { ...front, Accept: 'application/json' } });
  const step = async (label: string, run: () => Promise<{ status(): number; headersArray(): { name: string; value: string }[] }>) => {
    const sent = (await jar.storageState()).cookies.filter((c) => c.name === 'laravel-session').map((c) => decryptCookie(c.value)).join('&') || '-';
    const response = await run();
    const set = response.headersArray().filter((h) => h.name.toLowerCase() === 'set-cookie' && h.value.startsWith('laravel-session='))
      .map((h) => `${decryptCookie(h.value.slice('laravel-session='.length).split(';')[0])}{${h.value.split(';').slice(1).map((part) => part.trim().split('=')[0]).join(',')}}`).join('+') || '-';
    log.push(`${label}:${response.status()} sent=${sent} set=${set} rows=[${sql("select left(id,6)||':'||coalesce(left(user_id::text,4),'-') from sessions order by id")}]`);
  };
  await step('csrf', () => jar.get('/sanctum/csrf-cookie'));
  await step('login', async () => jar.post('/api/v1/auth/login', { headers: { 'X-XSRF-TOKEN': await xsrf(jar) }, data: { email: 'owner@demo.progressive.local', password: 'progressive-demo-local-only' } }));
  await step('me1', () => jar.get('/api/v1/me'));
  await step('me2', () => jar.get('/api/v1/me'));
  expect(log.join(' ;; ')).toBe('see message');
});
