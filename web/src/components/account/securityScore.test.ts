import { describe, expect, it } from 'vitest';
import { initials } from './AccountPage';
import { securitySteps } from './SecurityScoreCard';

describe('initials', () => {
  it('takes the first letters of the first and the last word', () => {
    expect(initials('Marie Joseph')).toBe('MJ');
    expect(initials('  jean   luc  baptiste ')).toBe('JB');
  });

  it('works with one word, accents and an empty name', () => {
    expect(initials('Édouard')).toBe('É');
    expect(initials('   ')).toBe('?');
  });
});

describe('securitySteps', () => {
  const none = { enabled: false, setup_started: false, recovery_codes_left: null };

  it('counts nothing for an unverified address and no second factor', () => {
    expect(securitySteps(false, none).filter((step) => step.done)).toHaveLength(0);
  });

  it('counts the address, the second factor and the recovery codes left', () => {
    const on = { enabled: true, setup_started: false, recovery_codes_left: 8 };

    expect(securitySteps(true, on).every((step) => step.done)).toBe(true);
    expect(
      securitySteps(true, { ...on, recovery_codes_left: 0 }).filter((s) => s.done),
    ).toHaveLength(2);
  });
});
