// Rules for the same-origin `/backend/*` proxy to the Laravel API.
// Only the public API surface may be reached; everything else on the API host
// (health checks, framework routes, future internal endpoints) stays private.

const DEFAULT_API_BASE_URL = 'http://127.0.0.1:8080';

const hopByHopHeaders = new Set([
  'connection',
  'keep-alive',
  'proxy-authenticate',
  'proxy-authorization',
  'proxy-connection',
  'te',
  'trailer',
  'transfer-encoding',
  'upgrade',
]);

// Set by infrastructure, never by the browser. Dropping them prevents a client
// from spoofing its address or host once the API starts trusting the proxy.
const clientControlledInfrastructureHeaders = new Set([
  'host',
  'content-length',
  'forwarded',
  'x-forwarded-for',
  'x-forwarded-host',
  'x-forwarded-port',
  'x-forwarded-proto',
  'x-forwarded-prefix',
  'x-real-ip',
]);

// fetch() already decoded the body, so the original encoding and length no longer apply.
// Location could reveal the internal API host; the API answers JSON, not redirects.
const droppedResponseHeaders = new Set(['content-encoding', 'content-length', 'location']);

// Session and CSRF cookies reach the browser only from the endpoints that
// create, rotate or end a session, and only under those two names. Forwarding
// them from every response logged users out right after sign-in (29.10.2026);
// renewing an active session is tracked in docs/process/WORKBOARD.md.
const forwardedCookieName = /^(XSRF-TOKEN|[a-z0-9-]+[-_]session)$/;

const sessionCookiePaths = new Set([
  'sanctum/csrf-cookie',
  'api/v1/auth/login',
  'api/v1/auth/logout',
]);

export function forwardsSessionCookies(segments: readonly string[]): boolean {
  return sessionCookiePaths.has(segments.join('/'));
}

function isAllowedPath(segments: readonly string[]): boolean {
  if (segments.length === 2 && segments[0] === 'sanctum' && segments[1] === 'csrf-cookie') {
    return true;
  }

  return segments.length >= 3 && segments[0] === 'api' && segments[1] === 'v1';
}

function isSafeSegment(segment: string): boolean {
  if (segment === '' || segment === '.' || segment === '..') {
    return false;
  }

  // A segment is one path component: no separators, no control characters.
  return [...segment].every((character) => {
    const code = character.charCodeAt(0);
    return code > 0x1f && code !== 0x7f && character !== '/' && character !== '\\';
  });
}

function encodeSegments(segments: readonly string[]): string[] | null {
  try {
    return segments.map(encodeURIComponent);
  } catch {
    // Malformed Unicode (a lone surrogate) cannot be encoded.
    return null;
  }
}

/**
 * Returns the API URL for a proxied path, or null when the path must not be proxied.
 * Segments arrive decoded from the router and are re-encoded one by one, so no
 * input can change the upstream host or leave `/api/v1` and `/sanctum/csrf-cookie`.
 * A path prefix in API_BASE_URL (for example `https://host/internal/`) is kept.
 */
export function buildUpstreamUrl(segments: readonly string[], search: string, apiBaseUrl = DEFAULT_API_BASE_URL): URL | null {
  if (!segments.every(isSafeSegment) || !isAllowedPath(segments)) {
    return null;
  }

  const encoded = encodeSegments(segments);

  if (encoded === null) {
    return null;
  }

  const base = new URL(apiBaseUrl);
  const basePath = base.pathname.endsWith('/') ? base.pathname : `${base.pathname}/`;
  const url = new URL(base.origin);
  url.pathname = `${basePath}${encoded.join('/')}`;
  url.search = search;

  return url;
}

export function upstreamRequestHeaders(incoming: Headers): Headers {
  const headers = new Headers(incoming);
  const listedInConnection = (incoming.get('connection') ?? '')
    .split(',')
    .map((name) => name.trim().toLowerCase())
    .filter(Boolean);

  for (const name of [...headers.keys()]) {
    if (hopByHopHeaders.has(name) || clientControlledInfrastructureHeaders.has(name) || listedInConnection.includes(name)) {
      headers.delete(name);
    }
  }

  return headers;
}

function cookieName(setCookie: string): string {
  return setCookie.slice(0, setCookie.indexOf('=')).trim();
}

export function upstreamResponseHeaders(upstream: Headers, forwardCookies: boolean): Headers {
  const headers = new Headers();

  upstream.forEach((value, name) => {
    if (!hopByHopHeaders.has(name) && !droppedResponseHeaders.has(name) && name !== 'set-cookie') {
      headers.set(name, value);
    }
  });

  if (!forwardCookies) {
    return headers;
  }

  for (const cookie of upstream.getSetCookie()) {
    if (forwardedCookieName.test(cookieName(cookie))) {
      headers.append('set-cookie', cookie);
    }
  }

  return headers;
}
