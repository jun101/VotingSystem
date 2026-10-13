import { screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { renderIn } from '@/components/auth/testing';
import { TwoFactorRequired } from './TwoFactorRequired';

describe('TwoFactorRequired', () => {
  it('says why /platform is closed and shows the two-factor card under it, in French', () => {
    renderIn(
      'fr',
      <TwoFactorRequired>
        <p data-testid="card">carte</p>
      </TwoFactorRequired>,
    );

    expect(screen.getByRole('heading', { level: 1 }).textContent).toContain('deux facteurs');
    expect(screen.getByRole('status').textContent).toContain('exige');
    expect(screen.getByTestId('card')).toBeTruthy();
  });

  it('speaks English too', () => {
    renderIn('en', <TwoFactorRequired>{null}</TwoFactorRequired>);

    expect(screen.getByRole('heading', { level: 1 }).textContent).toContain('two-factor');
  });
});
