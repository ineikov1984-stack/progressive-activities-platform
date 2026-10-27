import { NextRequest, NextResponse } from 'next/server';

const hopByHopHeaders = new Set([
  'connection',
  'content-length',
  'host',
  'keep-alive',
  'proxy-authenticate',
  'proxy-authorization',
  'te',
  'trailer',
  'transfer-encoding',
  'upgrade',
]);

type RouteContext = {
  params: Promise<{ path: string[] }>;
};

async function proxy(request: NextRequest, context: RouteContext) {
  const { path } = await context.params;
  const apiBaseUrl = process.env.API_BASE_URL ?? 'http://127.0.0.1:8080';
  const upstreamPath = path.join('/');
  const upstreamUrl = new URL(upstreamPath, `${apiBaseUrl}/`);
  upstreamUrl.search = request.nextUrl.search;

  const requestHeaders = new Headers(request.headers);
  for (const header of hopByHopHeaders) {
    requestHeaders.delete(header);
  }

  const upstream = await fetch(upstreamUrl, {
    method: request.method,
    headers: requestHeaders,
    body: request.method === 'GET' || request.method === 'HEAD'
      ? undefined
      : await request.arrayBuffer(),
    cache: 'no-store',
    redirect: 'manual',
  });

  const responseHeaders = new Headers();
  upstream.headers.forEach((value, name) => {
    if (!hopByHopHeaders.has(name) && name !== 'set-cookie') {
      responseHeaders.set(name, value);
    }
  });

  const forwardsAuthenticationCookies = upstreamPath === 'sanctum/csrf-cookie'
    || upstreamPath === 'api/v1/auth/login'
    || upstreamPath === 'api/v1/auth/logout';

  if (forwardsAuthenticationCookies) {
    for (const cookie of upstream.headers.getSetCookie()) {
      responseHeaders.append('set-cookie', cookie);
    }
  }

  const hasNoBody = upstream.status === 204 || upstream.status === 205 || upstream.status === 304;

  return new NextResponse(hasNoBody ? null : await upstream.arrayBuffer(), {
    status: upstream.status,
    headers: responseHeaders,
  });
}

export const dynamic = 'force-dynamic';

export const GET = proxy;
export const POST = proxy;
export const PUT = proxy;
export const PATCH = proxy;
export const DELETE = proxy;
export const OPTIONS = proxy;
