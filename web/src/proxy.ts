import { NextResponse, type NextRequest } from 'next/server';

/**
 * Tells the server components whether the page asked for is in the admin area, in the
 * request header `x-admin-area`, so the page can follow the user's stored language and not
 * the browser's (docs/slices/03). The same for `x-vote-election`, the election of a voting page.
 * A value sent by the visitor is always overwritten.
 */
export function proxy(request: NextRequest) {
  const nonce = btoa(crypto.randomUUID());
  const policy = contentSecurityPolicy(nonce);
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

  // Next.js reads the nonce from the request's policy and puts it on its own scripts and styles; `x-nonce`
  // is for the server components that render an inline element of their own.
  headers.set('x-nonce', nonce);
  headers.set('Content-Security-Policy', policy);

  const response = NextResponse.next({ request: { headers } });
  response.headers.set('Content-Security-Policy', policy);

  return response;
}

/**
 * The policy of every page (docs/slices/19 "Web CSP"): scripts only from the site and from what a nonce'd
 * script loads, nothing from another origin, no frame, no `<base>`, no plug-in.
 *
 * `style-src-attr` lets React's `style` attributes through (a bar's width, a token's value); the styles
 * written as elements still need the nonce, and scripts never get `'unsafe-inline'`.
 * The development server evaluates code for its error overlay: `'unsafe-eval'` is added only when
 * NODE_ENV is not production and `CSP_DEV_UNSAFE_EVAL=1` (set by `.env`; `make e2e` empties it, so the
 * browser tests see the same policy as production).
 */
export function contentSecurityPolicy(nonce: string, env: NodeJS.ProcessEnv = process.env): string {
  const devEval =
    env.NODE_ENV !== 'production' && env.CSP_DEV_UNSAFE_EVAL === '1' ? " 'unsafe-eval'" : '';

  return [
    "default-src 'self'",
    `script-src 'self' 'nonce-${nonce}' 'strict-dynamic'${devEval}`,
    `style-src 'self' 'nonce-${nonce}'`,
    "style-src-attr 'unsafe-inline'",
    "img-src 'self' data: blob:",
    "font-src 'self'",
    "connect-src 'self'",
    "frame-ancestors 'none'",
    "base-uri 'none'",
    "form-action 'self'",
    "object-src 'none'",
  ].join('; ');
}

export const config = {
  // Every path, dots included, but not the files of the build and the API (answered by the proxy). A path
  // with a dot (`/results/x.y`) must pass here too: the internal headers above are set (overwritten) only
  // by this function, so a path that skipped it would carry the visitor's own values to `getI18n`.
  matcher: ['/((?!_next/|api/).*)'],
};
