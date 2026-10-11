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

/**
 * A run is going on while a FILE is queued. Messages waiting to be sent do not make a run: the
 * buttons stay usable (the server only refuses a new run while a file is queued).
 */
export function isRunning(files: readonly GeneratedFile[]): boolean {
  return files.some(isQueued);
}

/** The page reads the state this often while a file is being written. */
export const POLL_MS = 1500;

/** And this often while only messages are waiting to be sent. */
export const EMAIL_POLL_MS = 5000;

/** The delay before the page reads the state again, or `null` when nothing is waiting. */
export function pollDelay(state: CredentialState, files: readonly GeneratedFile[]): number | null {
  if (isRunning(files)) return POLL_MS;

  return state.email_queued > 0 ? EMAIL_POLL_MS : null;
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

/** The slip PDFs of a group that are ready, newest first, then by part. */
function readySlips(files: readonly GeneratedFile[], line: CredentialLine): GeneratedFile[] {
  return files
    .filter((file) => file.kind === 'slips_pdf' && file.status === 'ready' && sameGroup(file, line))
    .sort((a, b) => b.created_at.localeCompare(a.created_at) || a.part - b.part);
}

/**
 * The files a card offers: EVERY ready slip file of the group, newest first, then in order of
 * part. A reissue's one-slip file is one of them. Files are never grouped by equal timestamps:
 * the server's `part` and `parts` say which files are pieces of one run.
 */
export function downloadsOf(
  files: readonly GeneratedFile[],
  line: CredentialLine,
): GeneratedFile[] {
  return readySlips(files, line);
}

/** What a run came to, read from the files it queued. */
export type RunOutcome = {
  voters: number;
  /** Slip files that failed. */
  failed: number;
  /** The self-distribution file failed: the codes and the slips are fine, the file is not. */
  codeFileFailed?: true;
};

/**
 * The outcome of the files this page watched (by UUID): the voters of the slip files that are
 * ready, and how many slip files failed; a failed file of codes is reported on its own (its voters
 * are those of the slips, never counted twice). `null` while any of them is still queued, or when
 * none is known.
 */
export function outcomeOf(
  files: readonly GeneratedFile[],
  watched: ReadonlySet<string>,
): RunOutcome | null {
  const mine = files.filter((file) => watched.has(file.id));

  if (mine.length === 0 || mine.some(isQueued)) return null;

  const slips = mine.filter((file) => file.kind === 'slips_pdf');
  const codeFileFailed = mine.some((file) => file.kind !== 'slips_pdf' && file.status === 'failed');

  return {
    voters: slips
      .filter((file) => file.status === 'ready')
      .reduce((sum, file) => sum + file.voter_count, 0),
    failed: slips.filter((file) => file.status === 'failed').length,
    ...(codeFileFailed ? { codeFileFailed: true as const } : {}),
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
