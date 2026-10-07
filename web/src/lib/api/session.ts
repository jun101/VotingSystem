/**
 * Name of the API's session cookie (SESSION_COOKIE in the API's environment). The pages that
 * run on the server look for it before asking the API who is signed in, so a visitor with
 * no session never makes the API open one.
 */
export const SESSION_COOKIE = process.env.SESSION_COOKIE ?? 'new_voting_system_session';

/** The CSRF cookie the API sets for the page's script to copy into `X-XSRF-TOKEN`. */
export const XSRF_COOKIE = 'XSRF-TOKEN';
