/** What an API error tells (docs/api/README.md section 3). */
export class ApiError extends Error {
  constructor(
    readonly status: number,
    /** `error.code`, or `network` when the API could not be reached. */
    readonly code: string,
    /** Field name → codes of the rules that failed (`error.fields`). */
    readonly fields: Record<string, string[]> = {},
    /** The `reference` of a 500 or a 503: the request id, to quote to support. */
    readonly reference: string | null = null,
    /** Seconds to wait (`Retry-After`) after a 429. */
    readonly retryAfter: number | null = null,
  ) {
    super(code);
    this.name = 'ApiError';
  }
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

/** Reads an error answer, whatever it holds: an unreadable body is an `unknown` error. */
export function parseApiError(status: number, body: unknown, retryAfter: string | null): ApiError {
  const error = isRecord(body) && isRecord(body.error) ? body.error : {};
  const fields: Record<string, string[]> = {};

  if (isRecord(error.fields)) {
    for (const [field, codes] of Object.entries(error.fields)) {
      if (Array.isArray(codes)) fields[field] = codes.filter((c) => typeof c === 'string');
    }
  }

  const seconds = retryAfter === null ? Number.NaN : Number.parseInt(retryAfter, 10);

  return new ApiError(
    status,
    typeof error.code === 'string' ? error.code : 'unknown',
    fields,
    typeof error.reference === 'string' ? error.reference : null,
    Number.isFinite(seconds) ? seconds : null,
  );
}

type FindMessage = (key: string, params?: Record<string, string | number>) => string | null;

/**
 * The text for an error that belongs to no field. The code is looked up in the message
 * files; a code that has no message gives the generic one, and the reference of a server
 * error is added so the person can quote it.
 */
export function errorText(error: ApiError, find: FindMessage): string {
  const text = find(`errors.${error.code}`) ?? find('errors.unknown') ?? error.code;
  const reference = error.reference
    ? find('errors.reference', { reference: error.reference })
    : null;

  return reference ? `${text} ${reference}` : text;
}

/**
 * The text for one rule that failed on one field: the message of that rule on that field
 * if there is one, else the message of the rule, else "not valid".
 */
export function fieldText(field: string, code: string, find: FindMessage): string {
  return (
    find(`validation.${field}.${code}`) ??
    find(`validation.${code}`) ??
    find('validation.invalid') ??
    code
  );
}
