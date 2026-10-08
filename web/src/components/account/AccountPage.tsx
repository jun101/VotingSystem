'use client';

import { Reveal } from '@/components/motion';
import type { TwoFactorState } from '@/lib/api/user';
import { SecurityCard } from './SecurityCard';

/**
 * "Mon compte": the settings of the signed-in person. For now one card, Sécurité (two-factor
 * authentication). Changing one's name or password comes later.
 */
export function AccountPage({ twoFactor }: { twoFactor: TwoFactorState }) {
  return (
    <Reveal stagger data-testid="account-page" className="grid gap-6 lg:max-w-3xl">
      <SecurityCard initial={twoFactor} />
    </Reveal>
  );
}
