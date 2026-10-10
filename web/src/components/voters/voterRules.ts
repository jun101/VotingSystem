/*
 * What a voter's election lets the page do (docs/slices/07-voters-groups.md, "Rules decided in
 * the contract"). The server decides; this only keeps the page from offering what it refuses.
 */

import type { ElectionStatus } from '@/lib/api/elections';

export type VoterRules = {
  /** Add and edit a voter. */
  write: boolean;
  /** Delete a voter. */
  remove: boolean;
  /** Add, rename, merge and delete a group. */
  groups: boolean;
  /** Nothing can change any more: the page says so. */
  locked: boolean;
};

export function rulesOf(status: ElectionStatus): VoterRules {
  switch (status) {
    case 'draft':
    case 'scheduled':
      return { write: true, remove: true, groups: true, locked: false };
    case 'open':
      // Slice 12 knows who has voted and will allow deleting.
      return { write: true, remove: false, groups: false, locked: false };
    default:
      return { write: false, remove: false, groups: false, locked: true };
  }
}
