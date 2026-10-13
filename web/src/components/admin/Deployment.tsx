'use client';

import { createContext, useContext, type ReactNode } from 'react';

const DeploymentContext = createContext({ school: false });

/** The edition of the installation, given by the admin layout (a server component) to the client components. */
export function DeploymentProvider({ school, children }: { school: boolean; children: ReactNode }) {
  return <DeploymentContext.Provider value={{ school }}>{children}</DeploymentContext.Provider>;
}

/** `school` is true in school mode: no email, one-time passwords, no sign-up. */
export function useDeployment(): { school: boolean } {
  return useContext(DeploymentContext);
}
