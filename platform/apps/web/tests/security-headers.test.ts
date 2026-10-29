import { describe, expect, it } from 'vitest';
import { contentSecurityPolicy, securityHeaders } from '../security-headers';

const headerMap = (isDevelopment: boolean) =>
  new Map(securityHeaders(isDevelopment).map(({ key, value }) => [key, value]));

describe('security headers', () => {
  it('forbids framing, sniffing and unneeded device APIs in production', () => {
    const headers = headerMap(false);

    expect(headers.get('X-Frame-Options')).toBe('DENY');
    expect(headers.get('X-Content-Type-Options')).toBe('nosniff');
    expect(headers.get('Referrer-Policy')).toBe('strict-origin-when-cross-origin');
    expect(headers.get('Permissions-Policy')).toContain('camera=()');
    expect(headers.get('Permissions-Policy')).toContain('geolocation=()');
    expect(headers.get('Strict-Transport-Security')).toContain('max-age=');
  });

  it('keeps the production CSP same-origin without eval', () => {
    const csp = contentSecurityPolicy(false);

    expect(csp).toContain("default-src 'self'");
    expect(csp).toContain("frame-ancestors 'none'");
    expect(csp).toContain("object-src 'none'");
    expect(csp).toContain("connect-src 'self'");
    expect(csp).not.toContain('unsafe-eval');
    expect(csp).not.toMatch(/https?:/);
  });

  it('relaxes only what the development server needs', () => {
    const csp = contentSecurityPolicy(true);

    expect(csp).toContain("'unsafe-eval'");
    expect(csp).toContain('ws:');
    expect(headerMap(true).has('Strict-Transport-Security')).toBe(false);
  });
});
