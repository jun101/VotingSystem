import type { ReactNode } from 'react';

/**
 * Only here for its not-found boundary: a `notFound()` thrown by the election's layout (a draft, an
 * unknown election) is caught by the segment above it, which is this one, and shows `not-found.tsx`.
 */
export default function VoteSectionLayout({ children }: { children: ReactNode }) {
  return children;
}
