import { afterEach, describe, expect, it } from 'vitest';
import { forgetVoteLocale, rememberVoteLocale, VOTE_LOCALE_COOKIE } from './locale';

const ELECTION = '0b9d8a52-6f0c-4c1e-9d84-2f0a3a5f7c11';

function go(path: string) {
  window.history.replaceState(null, '', path);
}

afterEach(() => {
  go(`/vote/${ELECTION}`);
  forgetVoteLocale();
  go('/');
});

describe('the voter language cookie', () => {
  it('is kept for the election pages and read back', () => {
    go(`/vote/${ELECTION}/ballot/1`);
    rememberVoteLocale('en');

    expect(document.cookie).toContain(`${VOTE_LOCALE_COOKIE}=en`);
  });

  it('is forgotten, and says whether there was one', () => {
    go(`/vote/${ELECTION}`);
    rememberVoteLocale('en');

    expect(forgetVoteLocale()).toBe(true);
    expect(document.cookie).not.toContain(VOTE_LOCALE_COOKIE);
    expect(forgetVoteLocale()).toBe(false);
  });

  it('is not written outside a voting page', () => {
    go('/admin');
    rememberVoteLocale('en');

    expect(document.cookie).not.toContain(VOTE_LOCALE_COOKIE);
  });
});
