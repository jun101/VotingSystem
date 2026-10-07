import { describe, expect, it } from 'vitest';
import en from '@/lib/i18n/messages/en.json';
import fr from '@/lib/i18n/messages/fr.json';
import { translateIfAny } from '@/lib/i18n/messages';
import { ApiError, errorText, fieldText, parseApiError } from './errors';

const finder = (messages: unknown) => (key: string, params?: Record<string, string | number>) =>
  translateIfAny(messages, key, params);

/*
 * Every error code and every rule code of the endpoint files of docs/api/auth/ has a
 * message in both languages (NFR-UX-01).
 */
const ERROR_CODES = [
  'validation_failed',
  'invalid_credentials',
  'institution_suspended',
  'unauthenticated',
  'csrf_mismatch',
  'too_many_attempts',
  'expired',
  'already_verified',
  'malformed_request',
  'method_not_allowed',
  'server_error',
  'dependency_unavailable',
  'network',
];

const RULE_CODES = ['required', 'invalid', 'taken', 'min', 'max', 'same_as_email'];

describe('error messages', () => {
  for (const [name, messages] of [
    ['French', fr],
    ['English', en],
  ] as const) {
    it(`${name} has a message for every error code`, () => {
      for (const code of ERROR_CODES) {
        expect(translateIfAny(messages, `errors.${code}`), code).not.toBeNull();
      }
    });

    it(`${name} has a message for every rule code`, () => {
      for (const code of RULE_CODES) {
        expect(translateIfAny(messages, `validation.${code}`), code).not.toBeNull();
      }
    });
  }

  it('keeps the two languages apart', () => {
    for (const code of ERROR_CODES) {
      expect(translateIfAny(fr, `errors.${code}`)).not.toBe(translateIfAny(en, `errors.${code}`));
    }
  });
});

describe('errorText', () => {
  it('gives the message of the code', () => {
    expect(errorText(new ApiError(401, 'invalid_credentials'), finder(en))).toBe(
      'Email or password is incorrect.',
    );
  });

  it('gives the generic message for a code it does not know', () => {
    expect(errorText(new ApiError(418, 'teapot'), finder(en))).toBe(
      'An unexpected error occurred.',
    );
  });

  it('adds the reference of a server error', () => {
    const text = errorText(new ApiError(500, 'server_error', {}, 'abc-123'), finder(en));

    expect(text).toContain('A server error occurred.');
    expect(text).toContain('abc-123');
  });
});

describe('fieldText', () => {
  it('prefers the message of the rule on that field', () => {
    expect(fieldText('password', 'min', finder(en))).toBe(
      'The password must have at least 12 characters.',
    );
    expect(fieldText('email', 'taken', finder(en))).toContain('already has an account');
  });

  it('falls back to the message of the rule, then to "not valid"', () => {
    expect(fieldText('institution_name', 'required', finder(en))).toBe('This field is required.');
    expect(fieldText('name', 'strange', finder(en))).toBe('This value is not valid.');
  });
});

describe('parseApiError', () => {
  it('reads the shared error shape', () => {
    const error = parseApiError(
      422,
      { error: { code: 'validation_failed', message: 'x', fields: { email: ['taken'] } } },
      null,
    );

    expect(error).toMatchObject({
      status: 422,
      code: 'validation_failed',
      fields: { email: ['taken'] },
    });
  });

  it('reads the reference and the retry delay', () => {
    const error = parseApiError(
      429,
      { error: { code: 'too_many_attempts', reference: 'r1' } },
      '42',
    );

    expect(error.reference).toBe('r1');
    expect(error.retryAfter).toBe(42);
  });

  it('turns anything unreadable into an unknown error', () => {
    for (const body of [undefined, null, 'oops', [], { error: 'x' }, { error: { fields: 3 } }]) {
      const error = parseApiError(502, body, 'soon');

      expect(error).toMatchObject({ status: 502, code: 'unknown', fields: {}, retryAfter: null });
    }
  });
});
