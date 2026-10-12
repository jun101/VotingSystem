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

  // The platform admin's screen (slice 18) follows the stored language too.
  const area =
    pathname === '/admin' ||
    pathname.startsWith('/admin/') ||
    pathname === '/platform' ||
    pathname.startsWith('/platform/');

  headers.set('x-admin-area', area ? '1' : '0');

  // The voting pages follow the election's language when the voter has not chosen one
  // (docs/slices/12): the page needs to know which election the address names.
  const vote = /^\/vote\/([0-9a-f-]{36})(?:\/|$)/i.exec(pathname);
  headers.set('x-vote-election', vote?.[1] ?? '');

  // The public results page speaks the election's language (docs/slices/16): same idea.
  const results = /^\/results\/([0-9a-f-]{36})(?:\/|$)/i.exec(pathname);
  headers.set('x-public-election', results?.[1] ?? '');

  return NextResponse.next({ request: { headers } });
}

export const config = {
  // Every path, dots included, but not the files of the build and the API (answered by the proxy). A path
  // with a dot (`/results/x.y`) must pass here too: the internal headers above are set (overwritten) only
  // by this function, so a path that skipped it would carry the visitor's own values to `getI18n`.
  matcher: ['/((?!_next/|api/).*)'],
};
