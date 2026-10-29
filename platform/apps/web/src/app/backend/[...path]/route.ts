import { NextRequest, NextResponse } from 'next/server';
import { buildUpstreamUrl, upstreamRequestHeaders, upstreamResponseHeaders } from './upstream';

type RouteContext = {
  params: Promise<{ path: string[] }>;
};

async function proxy(request: NextRequest, context: RouteContext) {
  const { path } = await context.params;
  const upstreamUrl = buildUpstreamUrl(path, request.nextUrl.search, process.env.API_BASE_URL);

  if (upstreamUrl === null) {
    return NextResponse.json({ message: 'Не е намерено.' }, { status: 404 });
  }

  const upstream = await fetch(upstreamUrl, {
    method: request.method,
    headers: upstreamRequestHeaders(request.headers),
    body: request.method === 'GET' || request.method === 'HEAD'
      ? undefined
      : await request.arrayBuffer(),
    cache: 'no-store',
    redirect: 'manual',
  });

  const responseHeaders = upstreamResponseHeaders(upstream.headers);
  const hasNoBody = request.method === 'HEAD' || upstream.status === 204 || upstream.status === 205 || upstream.status === 304;

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
