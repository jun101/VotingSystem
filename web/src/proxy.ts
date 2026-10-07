import { NextResponse, type NextRequest } from 'next/server';

/**
 * Tells the server components whether the page asked for is in the admin area, in the
 * request header `x-admin-area`, so the page can follow the user's stored language and not
 * the browser's (docs/slices/03). A value sent by the visitor is always overwritten.
 */
export function proxy(request: NextRequest) {
  const headers = new Headers(request.headers);
  const { pathname } = request.nextUrl;

  headers.set('x-admin-area', pathname === '/admin' || pathname.startsWith('/admin/') ? '1' : '0');

  return NextResponse.next({ request: { headers } });
}

export const config = {
  // Every page; not the files of the build, the images and the API (answered by the proxy).
  matcher: ['/((?!_next/|api/|.*\\..*).*)'],
};
