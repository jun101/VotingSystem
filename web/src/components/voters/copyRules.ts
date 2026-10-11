/*
 * The pure parts of the "load from another election" dialog (docs/slices/09-scope-copy-schedule.md,
 * 09b). The server judges the body and the limits; this builds the body from what the person
 * chose, counts the voters it will load and picks the words of a refusal.
 */

import type { ApiError } from '@/lib/api/errors';
import type { VoterCopyBody } from '@/lib/api/voters';

/** What the person chose: everything, or some groups of the source. */
export type CopyDraft = {
  /** The source election's UUID; `''` while none is chosen. */
  source: string;
  mode: 'all' | 'some';
  /** The UUIDs of the groups ticked (mode `some`). */
  groups: string[];
  /** Whether the voters with no group come too (mode `some`). */
  ungrouped: boolean;
  /** Group UUID to the new name typed; blank keeps the name. */
  rename: Record<string, string>;
};

export const EMPTY_COPY: CopyDraft = {
  source: '',
  mode: 'all',
  groups: [],
  ungrouped: false,
  rename: {},
};

/** The groups of the source and its ungrouped count, as the groups list gives them. */
export type CopySource = {
  groups: { id: string; name: string; voters_count: number }[];
  ungrouped: number;
};

/** A group ticked or unticked. */
export function toggleCopyGroup(groups: string[], id: string): string[] {
  return groups.includes(id) ? groups.filter((one) => one !== id) : [...groups, id];
}

/** The renames that count: of the ticked groups only, trimmed, the blank ones left out. */
export function renamesOf(draft: CopyDraft): Record<string, string> {
  const renames: Record<string, string> = {};

  for (const id of draft.groups) {
    const name = (draft.rename[id] ?? '').trim();

    if (name !== '') renames[id] = name;
  }

  return renames;
}

/**
 * The body of the call. Everything sends the source (the server copies every voter, ungrouped
 * included) and the renames typed; some groups sends the list, whether the ungrouped come, and the
 * renames of the ticked groups.
 */
export function copyBody(draft: CopyDraft): VoterCopyBody {
  if (draft.mode === 'all') {
    // Every group comes, and any of them may take a new name (the source alone otherwise).
    const named = renamesOf({ ...draft, groups: Object.keys(draft.rename) });

    return Object.keys(named).length > 0
      ? { source: draft.source, rename: named }
      : { source: draft.source };
  }

  const rename = renamesOf(draft);

  return {
    source: draft.source,
    groups: draft.groups,
    include_ungrouped: draft.ungrouped,
    ...(Object.keys(rename).length > 0 ? { rename } : {}),
  };
}

/** How many voters the copy will read from the source (before the repeated ones are skipped). */
export function copyCount(draft: CopyDraft, source: CopySource): number {
  if (draft.mode === 'all') {
    return source.groups.reduce((sum, group) => sum + group.voters_count, source.ungrouped);
  }

  const chosen = source.groups
    .filter((group) => draft.groups.includes(group.id))
    .reduce((sum, group) => sum + group.voters_count, 0);

  return chosen + (draft.ungrouped ? source.ungrouped : 0);
}

/** Whether the dialog can send: a source, and in "some" mode a group or the ungrouped. */
export function copyReady(draft: CopyDraft): boolean {
  if (draft.source === '') return false;

  return draft.mode === 'all' || draft.groups.length > 0 || draft.ungrouped;
}

/** The fields of the body the server may name, with the message key prefix of each. */
const FIELDS = ['source', 'groups', 'rename'] as const;

/**
 * The message key for a refused copy: the rule of the first field a 422 names
 * (`validation.copy.source.same`), or the code of any other answer (`errors.voter_limit_reached`).
 */
export function copyErrorKey(error: ApiError): string {
  if (error.status === 422) {
    for (const field of FIELDS) {
      const code = error.fields[field]?.[0];

      if (code) return `validation.copy.${field}.${code}`;
    }
  }

  return `errors.${error.code}`;
}
