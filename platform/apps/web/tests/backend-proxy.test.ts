import { describe, expect, it } from 'vitest';
import {
  buildUpstreamUrl,
  upstreamRequestHeaders,
  upstreamResponseHeaders,
} from '../src/app/backend/[...path]/upstream';

const api = 'http://api:8080';

describe('backend proxy path allowlist', () => {
  it('forwards the versioned API and the CSRF cookie endpoint', () => {
    expect(buildUpstreamUrl(['api', 'v1', 'me'], '', api)?.href).toBe('http://api:8080/api/v1/me');
    expect(buildUpstreamUrl(['api', 'v1', 'catalog', 'activities'], '?page=2', api)?.href)
      .toBe('http://api:8080/api/v1/catalog/activities?page=2');
    expect(buildUpstreamUrl(['sanctum', 'csrf-cookie'], '', api)?.href).toBe('http://api:8080/sanctum/csrf-cookie');
  });

  it('keeps a path prefix configured in API_BASE_URL', () => {
    expect(buildUpstreamUrl(['api', 'v1', 'me'], '', 'https://edge.example/internal/')?.href)
      .toBe('https://edge.example/internal/api/v1/me');
    expect(buildUpstreamUrl(['api', 'v1', 'me'], '', 'https://edge.example/internal')?.href)
      .toBe('https://edge.example/internal/api/v1/me');
  });

  it('refuses everything outside the public API surface', () => {
    for (const path of [
      [],
      ['up'],
      ['api'],
      ['api', 'v1'],
      ['api', 'v2', 'me'],
      ['sanctum'],
      ['sanctum', 'csrf-cookie', 'extra'],
      ['storage', 'logs', 'laravel.log'],
      ['telescope'],
    ]) {
      expect(buildUpstreamUrl(path, '', api), path.join('/')).toBeNull();
    }
  });

  it('refuses traversal, separators, empty and control-character segments', () => {
    for (const path of [
      ['api', 'v1', '..', '..', 'up'],
      ['api', 'v1', '.', 'me'],
      ['api', 'v1', '', 'me'],
      ['api', 'v1', 'me\n'],
      ['api', 'v1', 'catalog', 'media', 'a/../../up'],
      ['api', 'v1', 'catalog', 'media', '..\\..\\up'],
      ['api', 'v1', 'http://evil.example/x'],
    ]) {
      expect(buildUpstreamUrl(path, '', api), JSON.stringify(path)).toBeNull();
    }
  });

  it('refuses malformed Unicode instead of throwing', () => {
    expect(buildUpstreamUrl(['api', 'v1', '\uD800'], '', api)).toBeNull();
  });

  it('keeps encoded dots, queries and fragments inside one segment on the API host', () => {
    for (const segment of ['%2e%2e', '?x=1', '#frag', '．．', 'evil.example:80']) {
      const url = buildUpstreamUrl(['api', 'v1', 'catalog', segment], '', api);
      expect(url?.origin, segment).toBe('http://api:8080');
      expect(url?.pathname.startsWith('/api/v1/catalog/'), segment).toBe(true);
      expect(url?.pathname.split('/')).toHaveLength(5);
      expect(url?.hash, segment).toBe('');
    }
  });
});

describe('backend proxy headers', () => {
  it('drops hop-by-hop and spoofable infrastructure headers from the browser request', () => {
    const headers = upstreamRequestHeaders(new Headers({
      cookie: 'session=abc',
      'x-xsrf-token': 'token',
      'x-organization-id': 'org-1',
      origin: 'http://localhost:3000',
      host: 'evil.example',
      connection: 'keep-alive, x-custom-hop',
      'x-custom-hop': 'drop me',
      'proxy-connection': 'keep-alive',
      'content-length': '12',
      'x-forwarded-for': '1.2.3.4',
      'x-forwarded-host': 'evil.example',
      'x-forwarded-proto': 'https',
      'x-forwarded-port': '443',
      'x-real-ip': '1.2.3.4',
      forwarded: 'for=1.2.3.4',
    }));

    expect(headers.get('cookie')).toBe('session=abc');
    expect(headers.get('x-xsrf-token')).toBe('token');
    expect(headers.get('x-organization-id')).toBe('org-1');
    expect(headers.get('origin')).toBe('http://localhost:3000');
    for (const name of [
      'host', 'connection', 'x-custom-hop', 'proxy-connection', 'content-length', 'x-forwarded-for',
      'x-forwarded-host', 'x-forwarded-proto', 'x-forwarded-port', 'x-real-ip', 'forwarded',
    ]) {
      expect(headers.has(name), name).toBe(false);
    }
  });

  it('forwards only the session and CSRF cookies, from any API response', () => {
    const upstream = new Headers({
      'content-type': 'application/json',
      'content-encoding': 'gzip',
      'content-length': '42',
      location: 'http://api:8080/internal',
    });
    upstream.append('set-cookie', 'laravel-session=s; path=/; httponly');
    upstream.append('set-cookie', 'XSRF-TOKEN=t; path=/');
    upstream.append('set-cookie', 'tracking=1; path=/');

    const headers = upstreamResponseHeaders(upstream);

    expect(headers.getSetCookie()).toEqual(['laravel-session=s; path=/; httponly', 'XSRF-TOKEN=t; path=/']);
    expect(headers.get('content-type')).toBe('application/json');
    for (const name of ['content-encoding', 'content-length', 'location']) {
      expect(headers.has(name), name).toBe(false);
    }
  });
});
