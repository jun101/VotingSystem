'use client';

import { createContext, useContext, type ReactNode } from 'react';
import type { CurrentUser } from '@/lib/api/user';

const Context = createContext<CurrentUser | null>(null);

/** The signed-in user of the admin area, read once by the layout and given to its pages. */
export function AdminUserProvider({ user, children }: { user: CurrentUser; children: ReactNode }) {
  return <Context value={user}>{children}</Context>;
}

export function useAdminUser(): CurrentUser {
  const user = useContext(Context);

  if (!user) throw new Error('useAdminUser needs an AdminUserProvider');

  return user;
}
