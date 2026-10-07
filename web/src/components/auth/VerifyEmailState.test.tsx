import { screen, waitFor } from '@testing-library/react';
import { StrictMode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/lib/api/errors';
import { VerifyEmailState } from './VerifyEmailState';
import { renderIn } from './testing';

// A plain function, not a spy: a spy that returns a rejected promise is reported by the test
// runner as an unhandled error, whatever the code under test does with it.
let answer: (token: string) => Promise<void> = () => Promise.resolve();
const verifyEmail = vi.fn((token: string) => answer(token));

vi.mock('@/lib/api/browser', () => ({ verifyEmail: (token: string) => verifyEmail(token) }));

beforeEach(() => {
  verifyEmail.mockClear();
  answer = () => Promise.resolve();
});

let counter = 0;
const token = () => `token-${++counter}`;

describe('VerifyEmailState', () => {
  it('is invalid at once without a token, and calls nothing', () => {
    renderIn('en', <VerifyEmailState token={null} />);

    expect(screen.getByTestId('verify-state')).toHaveAttribute('data-state', 'invalid');
    expect(verifyEmail).not.toHaveBeenCalled();
  });

  it('is pending, then success', async () => {
    renderIn('en', <VerifyEmailState token={token()} />);

    expect(screen.getByTestId('verify-state')).toHaveAttribute('data-state', 'pending');
    await waitFor(() =>
      expect(screen.getByTestId('verify-state')).toHaveAttribute('data-state', 'success'),
    );
  });

  it('calls the API once even when the component mounts twice (strict mode)', async () => {
    renderIn(
      'en',
      <StrictMode>
        <VerifyEmailState token={token()} />
      </StrictMode>,
    );

    await waitFor(() =>
      expect(screen.getByTestId('verify-state')).toHaveAttribute('data-state', 'success'),
    );
    expect(verifyEmail).toHaveBeenCalledTimes(1);
  });

  it.each([
    ['expired', () => new ApiError(410, 'expired'), 'expired'],
    [
      'an unknown token',
      () => new ApiError(422, 'validation_failed', { token: ['invalid'] }),
      'invalid',
    ],
    ['a rate limit', () => new ApiError(429, 'too_many_attempts'), 'error'],
    ['a network failure', () => new ApiError(0, 'network'), 'error'],
  ])('shows %s', async (_name, makeError, state) => {
    answer = () => Promise.reject(makeError());
    renderIn('fr', <VerifyEmailState token={token()} />);

    await waitFor(() =>
      expect(screen.getByTestId('verify-state')).toHaveAttribute('data-state', state),
    );
  });
});
