/*
 * What the codes page lets a person do (docs/slices/10-codes-and-slips.md, A11). The server
 * decides; this only keeps the page from offering what it refuses, and picks which files a card
 * shows. No text here, no browser call.
 */

import type {
  CredentialLine,
  CredentialState,
  GeneratedFile,
  RunBody,
} from '@/lib/api/credentials';
import type { ElectionStatus } from '@/lib/api/elections';

/** Codes are made and replaced in a draft, scheduled or open election only. */
export function codesLocked(status: ElectionStatus): boolean {
  return status !== 'draft' && status !== 'scheduled' && status !== 'open';
}

/** A file is still being written. */
export function isQueued(file: GeneratedFile): boolean {
  return file.status === 'queued';
}

/** The page polls while a file is queued or the server says a run is in progress. */
export function isRunning(state: CredentialState, files: readonly GeneratedFile[]): boolean {
  return state.run_in_progress || files.some(isQueued);
}

/** "Generate for everyone" is offered when someone has no code and no run is going on. */
export function canGenerateAll(state: CredentialState, running: boolean): boolean {
  return !running && state.without_code > 0;
}

/** The voters of a group who have no code. */
export function missingIn(line: CredentialLine): number {
  return Math.max(0, line.voters - line.with_code);
}

/** A group's own button is offered when one of its voters has no code and no run is going on. */
export function canGenerateGroup(line: CredentialLine, running: boolean): boolean {
  return !running && missingIn(line) > 0;
}

/** The body of the run of one card: a group, or the voters with no group. */
export function runBodyOf(line: CredentialLine): RunBody {
  return line.group ? { scope: 'group', group: line.group.id } : { scope: 'ungrouped' };
}

/** Share of the voters who have a code, as a whole percentage (0 for an election with none). */
export function percentOf(withCode: number, voters: number): number {
  if (voters <= 0) return 0;

  return Math.min(100, Math.floor((withCode / voters) * 100));
}

/** The words of a card's state pill. */
export type LineState = 'none' | 'partial' | 'done' | 'empty';

export function lineState(line: CredentialLine): LineState {
  if (line.voters === 0) return 'empty';
  if (line.with_code === 0) return 'none';

  return line.with_code >= line.voters ? 'done' : 'partial';
}

function sameGroup(file: GeneratedFile, line: CredentialLine): boolean {
  return (file.group?.id ?? null) === (line.group?.id ?? null);
}

/** The slip PDFs of a group that are ready, newest first. */
function readySlips(files: readonly GeneratedFile[], line: CredentialLine): GeneratedFile[] {
  return files
    .filter((file) => file.kind === 'slips_pdf' && file.status === 'ready' && sameGroup(file, line))
    .sort((a, b) => b.created_at.localeCompare(a.created_at) || a.part - b.part);
}

/**
 * The files a card offers: those of the group's newest run. A reissue writes a one-slip file in
 * the same group, newer than the run; it is left out when the group has a larger file. The
 * parts of one run share their creation time and their count of parts, in order of part.
 */
export function downloadsOf(
  files: readonly GeneratedFile[],
  line: CredentialLine,
): GeneratedFile[] {
  const ready = readySlips(files, line);
  const larger = ready.filter((file) => file.voter_count > 1 || file.parts > 1);
  const pool = larger.length > 0 ? larger : ready;
  const first = pool[0];

  if (!first) return [];

  return pool
    .filter((file) => file.created_at === first.created_at && file.parts === first.parts)
    .sort((a, b) => a.part - b.part);
}

/** What a run came to, read from the files it queued. */
export type RunOutcome = { voters: number; failed: number };

/**
 * The outcome of the files this page watched (by UUID): the voters of the files that are ready,
 * and how many of them failed. `null` while any of them is still queued, or when none is known.
 */
export function outcomeOf(
  files: readonly GeneratedFile[],
  watched: ReadonlySet<string>,
): RunOutcome | null {
  const mine = files.filter((file) => watched.has(file.id));

  if (mine.length === 0 || mine.some(isQueued)) return null;

  return {
    voters: mine
      .filter((file) => file.status === 'ready')
      .reduce((sum, file) => sum + file.voter_count, 0),
    failed: mine.filter((file) => file.status === 'failed').length,
  };
}

/** The list with the files of an answer put first and replacing the ones it names. */
export function mergeFiles(
  known: readonly GeneratedFile[],
  fresh: readonly GeneratedFile[],
): GeneratedFile[] {
  const ids = new Set(fresh.map((file) => file.id));

  return [...fresh, ...known.filter((file) => !ids.has(file.id))];
}
