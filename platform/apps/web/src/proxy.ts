import { randomBytes } from 'node:crypto';
import { NextRequest, NextResponse } from 'next/server';
import { contentSecurityPolicy } from '../security-headers';

export function proxy(request: NextRequest) {
  const nonce = randomBytes(16).toString('base64');
  const policy = contentSecurityPolicy(process.env.NODE_ENV === 'development', nonce);
  const requestHeaders = new Headers(request.headers);
  // Always replace client values; Next reads the policy to nonce its own bootstrap scripts.
  requestHeaders.set('x-nonce', nonce);
  requestHeaders.set('Content-Security-Policy', policy);
  const response = NextResponse.next({ request: { headers: requestHeaders } });
  response.headers.set('Content-Security-Policy', policy);
  response.headers.set('Cache-Control', 'private, no-cache, no-store, max-age=0, must-revalidate');
  return response;
}

export const config = {
  // JSON proxy requests and static assets do not render a page or need a nonce.
  matcher: ['/((?!backend(?:/|$)|_next(?:/|$)|email-icons(?:/|$)|favicon\\.ico$).*)'],
};
