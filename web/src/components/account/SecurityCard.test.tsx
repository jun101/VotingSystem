import { act, fireEvent, screen } from '@testing-library/react';
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import { renderIn } from '@/components/auth/testing';
import { SecurityCard } from './SecurityCard';

const calls = vi.hoisted(() => ({
  start: vi.fn(),
  confirm: vi.fn(),
  disable: vi.fn(),
  renew: vi.fn(),
}));

vi.mock('next/navigation', () => ({
  useRouter: () => ({ push: vi.fn(), refresh: vi.fn() }),
}));
vi.mock('@/lib/api/browser', () => ({
  startTwoFactorSetup: calls.start,
  confirmTwoFactor: calls.confirm,
  disableTwoFactor: calls.disable,
  renewRecoveryCodes: calls.renew,
}));
vi.mock('./QrCode', () => ({ QrCode: () => null }));

beforeAll(() => {
  // jsdom has no modal dialog.
  HTMLDialogElement.prototype.showModal = function showModal() {
    this.setAttribute('open', '');
  };
  HTMLDialogElement.prototype.close = function close() {
    this.removeAttribute('open');
  };
});

beforeEach(() => vi.clearAllMocks());

const OFF = { enabled: false, setup_started: false, recovery_codes_left: null };
const ON = { enabled: true, setup_started: false, recovery_codes_left: 8 };

function type(testId: string, value: string) {
  fireEvent.change(screen.getByTestId(testId), { target: { value } });
}

describe('SecurityCard double submit', () => {
  it('sends one setup request for two submits of the password step', async () => {
    calls.start.mockReturnValue(new Promise(() => {}));
    renderIn('fr', <SecurityCard initial={OFF} />);
    fireEvent.click(screen.getByTestId('two-factor-setup-open'));
    type('two-factor-password', 'secret-value');

    const form = screen.getByTestId('two-factor-password').closest('form')!;
    fireEvent.submit(form);
    fireEvent.submit(form);

    expect(calls.start).toHaveBeenCalledTimes(1);
  });

  it('sends one confirmation for two submits of the code step', async () => {
    calls.start.mockResolvedValue({ secret: 'JBSWY3DPEHPK3PXP', otpauth_url: 'otpauth://x' });
    calls.confirm.mockReturnValue(new Promise(() => {}));
    renderIn('fr', <SecurityCard initial={OFF} />);
    fireEvent.click(screen.getByTestId('two-factor-setup-open'));
    type('two-factor-password', 'secret-value');
    await act(async () => {
      fireEvent.submit(screen.getByTestId('two-factor-password').closest('form')!);
    });

    type('two-factor-code', '123456');
    const form = screen.getByTestId('two-factor-code').closest('form')!;
    fireEvent.submit(form);
    fireEvent.submit(form);

    expect(calls.confirm).toHaveBeenCalledTimes(1);
  });

  it('sends one request for two submits of a password dialog, and describes it with its sentence only', () => {
    calls.disable.mockReturnValue(new Promise(() => {}));
    renderIn('fr', <SecurityCard initial={ON} />);
    fireEvent.click(screen.getByTestId('two-factor-disable-open'));
    type('two-factor-password', 'secret-value');

    const dialog = screen.getByTestId('two-factor-disable-dialog');
    const describedBy = dialog.getAttribute('aria-describedby')!;
    const sentence = document.getElementById(describedBy)!;

    expect(sentence.tagName).toBe('P');
    expect(sentence.querySelector('input')).toBeNull();

    const form = screen.getByTestId('two-factor-password').closest('form')!;
    fireEvent.submit(form);
    fireEvent.submit(form);

    expect(calls.disable).toHaveBeenCalledTimes(1);
  });
});
