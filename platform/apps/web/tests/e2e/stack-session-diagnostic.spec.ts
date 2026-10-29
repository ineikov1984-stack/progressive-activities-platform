import { expect, request, test, type APIRequestContext } from '@playwright/test';

// Temporary diagnostic (not for merge): why the API session forgets writes.
const api = 'http://127.0.0.1:8080';
const front = { Origin: 'http://127.0.0.1:3000', Referer: 'http://127.0.0.1:3000/' };

async function xsrf(context: APIRequestContext): Promise<string> {
  const state = await context.storageState();
  const cookie = state.cookies.find((item) => item.name === 'XSRF-TOKEN');
  return cookie ? decodeURIComponent(cookie.value) : '';
}

async function cookieHeader(context: APIRequestContext): Promise<string> {
  const state = await context.storageState();
  return state.cookies.map((item) => `${item.name}=${item.value}`).join('; ');
}

test('A: direct API with a normal cookie jar keeps the selected organization', async () => {
  const context = await request.newContext({ baseURL: api, extraHTTPHeaders: front });
  await context.get('/sanctum/csrf-cookie');
  const login = await context.post('/api/v1/auth/login', { headers: { 'X-XSRF-TOKEN': await xsrf(context), Accept: 'application/json' }, data: { email: 'owner@demo.progressive.local', password: 'progressive-demo-local-only' } });
  expect(login.status(), `login: ${await login.text()}`).toBe(200);
  const names = (await context.storageState()).cookies.map((c) => `${c.name}(exp ${Math.round(c.expires - Date.now() / 1000)}s)`).join(', ');
  const select = await context.post('/api/v1/context/organization', { headers: { 'X-XSRF-TOKEN': await xsrf(context), Accept: 'application/json' }, data: { organization_id: '00000000-0000-4000-8000-000000000101' } });
  const setCookies = select.headersArray().filter((h) => h.name.toLowerCase() === 'set-cookie').map((h) => h.value.split('=')[0]).join(',');
  expect(select.status(), `select: ${await select.text()}`).toBe(200);
  const current = await context.get('/api/v1/context/organization', { headers: { Accept: 'application/json' } });
  expect(current.status(), `A after select → ${current.status()} ${await current.text()} | cookies after login: ${names} | select Set-Cookie: ${setCookies}`).toBe(200);
});

test('B: direct API with cookies frozen after login (like the proxy) keeps the selected organization', async () => {
  const jar = await request.newContext({ baseURL: api, extraHTTPHeaders: front });
  await jar.get('/sanctum/csrf-cookie');
  const login = await jar.post('/api/v1/auth/login', { headers: { 'X-XSRF-TOKEN': await xsrf(jar), Accept: 'application/json' }, data: { email: 'owner@demo.progressive.local', password: 'progressive-demo-local-only' } });
  expect(login.status()).toBe(200);
  const frozen = await cookieHeader(jar);
  const token = await xsrf(jar);
  const bare = await request.newContext({ baseURL: api, extraHTTPHeaders: { ...front, Cookie: frozen } });
  const me = await bare.get('/api/v1/me', { headers: { Accept: 'application/json' } });
  const select = await bare.post('/api/v1/context/organization', { headers: { 'X-XSRF-TOKEN': token, Accept: 'application/json' }, data: { organization_id: '00000000-0000-4000-8000-000000000101' } });
  const current = await bare.get('/api/v1/context/organization', { headers: { Accept: 'application/json' } });
  const me2 = await bare.get('/api/v1/me', { headers: { Accept: 'application/json' } });
  expect(`${me.status()}/${select.status()}/${current.status()}/${me2.status()}`, `B: me/select/current/me2 = ${me.status()}/${select.status()}/${current.status()}/${me2.status()}; current body ${await current.text()}`).toBe('200/200/200/200');
});
