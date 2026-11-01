import { describe, expect, it } from 'vitest';
import { NextRequest } from 'next/server';
import { unstable_doesMiddlewareMatch } from 'next/experimental/testing/server';
import { config, proxy } from '../src/proxy';

describe('per-request script nonce', () => {
  it.each(['/login', '/dashboard/enrollments', '/o/demo-school', '/missing-page'])(
    'adds a nonce to page requests: %s', (url) => {
      expect(unstable_doesMiddlewareMatch({ config, nextConfig: {}, url })).toBe(true);
    },
  );
  it.each(['/backend', '/backend/api/v1/me', '/_next/static/chunk.js', '/_next/image?url=test', '/email-icons/calendar.png', '/favicon.ico'])(
    'keeps JSON and static resources outside the nonce proxy: %s', (url) => {
      expect(unstable_doesMiddlewareMatch({ config, nextConfig: {}, url })).toBe(false);
    },
  );
  it('uses a fresh unpredictable value and supplies the same policy to rendering and the browser', () => {
    const first = proxy(new NextRequest('https://example.test/login'));
    const second = proxy(new NextRequest('https://example.test/login'));
    const policy = first.headers.get('Content-Security-Policy');
    const nonce = first.headers.get('x-middleware-request-x-nonce');

    expect(nonce).toMatch(/^[A-Za-z0-9+/]{22}==$/);
    expect(Buffer.from(nonce!, 'base64')).toHaveLength(16);
    expect(policy).toContain(`'nonce-${nonce}'`);
    expect(first.headers.get('x-middleware-request-content-security-policy')).toBe(policy);
    expect(second.headers.get('x-middleware-request-x-nonce')).not.toBe(nonce);
    expect(first.headers.get('Cache-Control')).toContain('no-store');
  });

  it('overwrites a client-supplied nonce and policy without exposing request override headers as response values', () => {
    const response = proxy(new NextRequest('https://example.test/login', {
      headers: { 'x-nonce': 'attacker-controlled', 'Content-Security-Policy': "script-src 'unsafe-inline'", cookie: 'demo_session=synthetic' },
    }));
    const nonce = response.headers.get('x-middleware-request-x-nonce');
    expect(nonce).not.toBe('attacker-controlled');
    expect(response.headers.get('Content-Security-Policy')).toContain(`'nonce-${nonce}'`);
    expect(response.headers.get('x-middleware-request-cookie')).toBe('demo_session=synthetic');
    expect(response.headers.get('x-nonce')).toBeNull();
    expect(response.headers.get('cookie')).toBeNull();
  });
});
