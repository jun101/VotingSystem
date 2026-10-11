import { NextResponse, type NextRequest } from 'next/server';

/**
 * Tells the server components whether the page asked for is in the admin area, in the
 * request header `x-admin-area`, so the page can follow the user's stored language and not
 * the browser's (docs/slices/03). The same for `x-vote-election`, the election of a voting page.
 * A value sent by the visitor is always overwritten.
 */
export function proxy(request: NextRequest) {
  const headers = new Headers(request.headers);
  const { pathname } = request.nextUrl;

  headers.set('x-admin-area', pathname === '/admin' || pathname.startsWith('/admin/') ? '1' : '0');

  // The voting pages follow the election's language when the voter has not chosen one
  // (docs/slices/12): the page needs to know which election the address names.
  const vote = /^\/vote\/([0-9a-f-]{36})(?:\/|$)/i.exec(pathname);
  headers.set('x-vote-election', vote?.[1] ?? '');

  return NextResponse.next({ request: { headers } });
}

export const config = {
  // Every page; not the files of the build, the images and the API (answered by the proxy).
  matcher: ['/((?!_next/|api/|.*\\..*).*)'],
};
