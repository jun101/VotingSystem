import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/lib/api/errors';
import { RegisterForm } from './RegisterForm';
import { renderIn } from './testing';

const push = vi.fn();
const register = vi.fn();

vi.mock('next/navigation', () => ({ useRouter: () => ({ push }) }));
vi.mock('@/lib/api/browser', () => ({ register: (...args: unknown[]) => register(...args) }));

async function fill() {
  await userEvent.type(screen.getByTestId('register-institution-name'), 'Collège');
  await userEvent.type(screen.getByTestId('register-name'), 'Marie');
  await userEvent.type(screen.getByTestId('register-email'), 'marie@example.test');
  await userEvent.type(screen.getByTestId('register-password'), 'a long enough password');
}

beforeEach(() => {
  push.mockReset();
  register.mockReset();
});

describe('RegisterForm', () => {
  it('sends the fields and the language of the page, then goes to the admin area', async () => {
    register.mockResolvedValue({});
    renderIn('en', <RegisterForm />);

    await fill();
    await userEvent.click(screen.getByTestId('register-submit'));

    await waitFor(() => expect(push).toHaveBeenCalledWith('/admin'));
    expect(register).toHaveBeenCalledWith({
      institution_name: 'Collège',
      name: 'Marie',
      email: 'marie@example.test',
      password: 'a long enough password',
      language: 'en',
    });
  });

  it('shows each error next to its field and focuses the first one', async () => {
    register.mockRejectedValue(
      new ApiError(422, 'validation_failed', { email: ['taken'], password: ['min'] }),
    );
    renderIn('en', <RegisterForm />);

    await fill();
    await userEvent.click(screen.getByTestId('register-submit'));

    expect(await screen.findByTestId('field-error-email')).toHaveTextContent(
      'already has an account',
    );
    expect(screen.getByTestId('field-error-password')).toHaveTextContent('at least 12 characters');
    expect(screen.getByTestId('register-email')).toHaveAttribute('aria-invalid', 'true');
    expect(screen.getByTestId('register-institution-name')).not.toHaveAttribute('aria-invalid');
    expect(screen.getByTestId('register-email')).toHaveFocus();
    expect(screen.queryByTestId('form-error')).not.toBeInTheDocument();
    expect(push).not.toHaveBeenCalled();
  });

  it('shows an error that belongs to no field in one alert, in the language of the page', async () => {
    register.mockRejectedValue(new ApiError(429, 'too_many_attempts', {}, null, 30));
    renderIn('fr', <RegisterForm />);

    await fill();
    await userEvent.click(screen.getByTestId('register-submit'));

    expect(await screen.findByRole('alert')).toHaveTextContent('Trop de tentatives');
    expect(screen.getByTestId('register-submit')).not.toBeDisabled();
    // Focus does not fall to the page: it goes to the alert.
    expect(screen.getByTestId('form-error')).toHaveFocus();
  });

  it('says a field it does not show is wrong in the alert, and keeps the form usable', async () => {
    register.mockRejectedValue(new ApiError(422, 'validation_failed', { language: ['invalid'] }));
    renderIn('en', <RegisterForm />);

    await fill();
    await userEvent.click(screen.getByTestId('register-submit'));

    expect(await screen.findByTestId('form-error')).toHaveTextContent(
      'Some fields need correcting',
    );
  });

  it('makes the button busy while the call runs, and does not send twice', async () => {
    let finish: (value: unknown) => void = () => undefined;
    register.mockReturnValue(new Promise((resolve) => (finish = resolve)));
    renderIn('en', <RegisterForm />);

    await fill();
    await userEvent.click(screen.getByTestId('register-submit'));

    expect(screen.getByTestId('register-submit')).toBeDisabled();
    expect(screen.getByTestId('register-submit')).toHaveAttribute('aria-busy', 'true');

    await userEvent.click(screen.getByTestId('register-submit'));
    expect(register).toHaveBeenCalledTimes(1);

    finish({});
    await waitFor(() => expect(push).toHaveBeenCalled());
  });

  it('turns a network failure into a message', async () => {
    register.mockRejectedValue(new ApiError(0, 'network'));
    renderIn('en', <RegisterForm />);

    await fill();
    await userEvent.click(screen.getByTestId('register-submit'));

    expect(await screen.findByRole('alert')).toHaveTextContent('cannot be reached');
  });

  it('is a form that never sends the password in the address, even before it is ready', () => {
    renderIn('en', <RegisterForm />);

    expect(screen.getByTestId('register-form')).toHaveAttribute('method', 'post');
    expect(screen.getByTestId('register-form')).toHaveAttribute('novalidate');
  });
});
