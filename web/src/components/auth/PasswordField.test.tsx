import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { PasswordField } from './PasswordField';
import { renderIn } from './testing';

describe('PasswordField', () => {
  it('hides the password at first and shows it on request, with a labelled button', async () => {
    renderIn('en', <PasswordField label="Password" name="p" toggleTestId="toggle" />);
    const field = screen.getByLabelText('Password');
    const toggle = screen.getByTestId('toggle');

    expect(field).toHaveAttribute('type', 'password');
    expect(toggle).toHaveAccessibleName('Show password');
    expect(toggle).toHaveAttribute('aria-pressed', 'false');

    await userEvent.click(toggle);

    expect(field).toHaveAttribute('type', 'text');
    expect(toggle).toHaveAccessibleName('Hide password');
    expect(toggle).toHaveAttribute('aria-pressed', 'true');

    await userEvent.click(toggle);

    expect(field).toHaveAttribute('type', 'password');
  });

  it('keeps what was typed when it is shown', async () => {
    renderIn('fr', <PasswordField label="Mot de passe" name="p" toggleTestId="toggle" />);

    await userEvent.type(screen.getByLabelText('Mot de passe'), 'secret value here');
    await userEvent.click(screen.getByTestId('toggle'));

    expect(screen.getByLabelText('Mot de passe')).toHaveValue('secret value here');
    expect(screen.getByTestId('toggle')).toHaveAccessibleName('Masquer le mot de passe');
  });

  it('shows the error of the field next to it', () => {
    renderIn('en', <PasswordField label="Password" name="p" error="Too short" errorTestId="err" />);

    expect(screen.getByLabelText('Password')).toHaveAttribute('aria-invalid', 'true');
    expect(screen.getByTestId('err')).toHaveTextContent('Too short');
  });
});
