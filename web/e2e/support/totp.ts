import { createHmac } from 'node:crypto';

/*
 * An authenticator application in a few lines (RFC 6238: SHA-1, 6 digits, 30 seconds), so the
 * browser tests can answer the second step of signing in. Part of the acceptance harness.
 */

const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

function decode(secret: string): Buffer {
  let bits = '';
  for (const char of secret.toUpperCase().replace(/[\s=]/g, '')) {
    bits += ALPHABET.indexOf(char).toString(2).padStart(5, '0');
  }
  const bytes: number[] = [];
  for (let i = 0; i + 8 <= bits.length; i += 8) bytes.push(parseInt(bits.slice(i, i + 8), 2));
  return Buffer.from(bytes);
}

/**
 * The 6-digit code of the 30-second period `offset` periods from now. After a first code was
 * accepted (at confirmation), the next period (`1`) is the one a second sign-in may use.
 */
export function totp(secret: string, offset = 0, now = Date.now()): string {
  const step = Math.floor(now / 1000 / 30) + offset;
  const counter = Buffer.alloc(8);
  counter.writeBigUInt64BE(BigInt(step));
  const hash = createHmac('sha1', decode(secret)).update(counter).digest();
  const at = hash[19]! & 0x0f;
  const number = ((hash[at]! & 0x7f) << 24) | (hash[at + 1]! << 16) | (hash[at + 2]! << 8) | hash[at + 3]!;
  return String(number % 1_000_000).padStart(6, '0');
}

/** A 6-digit string that is not the code of any period around now. */
export function wrongCode(secret: string): string {
  const valid = new Set([-3, -2, -1, 0, 1, 2, 3].map((offset) => totp(secret, offset)));
  return ['000000', '123456', '654321', '111111'].find((code) => !valid.has(code)) ?? '999999';
}
