import { describe, expect, it } from 'vitest';
import { resolveLocale } from './locale';

describe('resolveLocale', () => {
  it('is French when nothing says otherwise', () => {
    expect(resolveLocale({})).toBe('fr');
    expect(resolveLocale({ cookie: null, acceptLanguage: null })).toBe('fr');
  });

  it('follows the locale cookie when it holds fr or en', () => {
    expect(resolveLocale({ cookie: 'en', acceptLanguage: 'fr-FR' })).toBe('en');
    expect(resolveLocale({ cookie: 'fr', acceptLanguage: 'en-US' })).toBe('fr');
  });

  it('ignores a cookie that holds anything else', () => {
    expect(resolveLocale({ cookie: 'de', acceptLanguage: 'en-US' })).toBe('en');
    expect(resolveLocale({ cookie: '', acceptLanguage: null })).toBe('fr');
  });

  it('follows the browser language, region and quality included', () => {
    expect(resolveLocale({ acceptLanguage: 'en-US,en;q=0.9' })).toBe('en');
    expect(resolveLocale({ acceptLanguage: 'fr-FR,fr;q=0.9,en;q=0.8' })).toBe('fr');
    expect(resolveLocale({ acceptLanguage: 'de;q=0.9,en;q=0.5' })).toBe('en');
    expect(resolveLocale({ acceptLanguage: 'en;q=0.4,fr;q=0.8' })).toBe('fr');
  });

  it('falls back to French for a language we do not have', () => {
    expect(resolveLocale({ acceptLanguage: 'de-DE,es;q=0.8' })).toBe('fr');
    expect(resolveLocale({ acceptLanguage: '*' })).toBe('fr');
  });

  it('skips a language refused with q=0 and survives nonsense', () => {
    expect(resolveLocale({ acceptLanguage: 'fr;q=0,en;q=0.5' })).toBe('en');
    expect(resolveLocale({ acceptLanguage: ';;;,,,q=' })).toBe('fr');
  });
});
