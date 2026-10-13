import { act, fireEvent, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { renderIn } from '@/components/auth/testing';
import { ApiError } from '@/lib/api/errors';
import { ChangePasswordCard } from './ChangePasswordCard';

const calls = vi.hoisted(() => ({ change: vi.fn() }));

vi.mock('@/lib/api/browser', () => ({ changePassword: calls.change }));

beforeEach(() => vi.clearAllMocks());

function fill(testId: string, value: string) {
  fireEvent.change(screen.getByTestId(testId), { target: { value } });
}

describe('ChangePasswordCard', () => {
  it('sends the two passwords and says it is done', async () => {
    calls.change.mockResolvedValue(undefined);
    renderIn('en', <ChangePasswordCard twoFactorEnabled={false} />);

    fill('change-password-current', 'the old one');
    fill('change-password-new', 'a brand new password');
    await act(async () => {
      fireEvent.click(screen.getByTestId('change-password-submit'));
    });

    expect(calls.change).toHaveBeenCalledWith('the old one', 'a brand new password', undefined);
    await waitFor(() => expect(screen.getByTestId('change-password-done')).toBeInTheDocument());
  });

  it('tells a wrong current password under its field (fr)', async () => {
    calls.change.mockRejectedValue(
      new ApiError(422, 'validation_failed', { current_password: ['incorrect'] }),
    );
    renderIn('fr', <ChangePasswordCard twoFactorEnabled={false} />);

    fill('change-password-current', 'wrong');
    fill('change-password-new', 'a brand new password');
    await act(async () => {
      fireEvent.click(screen.getByTestId('change-password-submit'));
    });

    await waitFor(() =>
      expect(screen.getByTestId('change-password-current-error')).toHaveTextContent(
        'Ce n’est pas votre mot de passe actuel',
      ),
    );
  });

  it('asks for the second factor only when the user has one', () => {
    renderIn('en', <ChangePasswordCard twoFactorEnabled />);

    expect(screen.getByTestId('change-password-code')).toBeInTheDocument();
  });
});
