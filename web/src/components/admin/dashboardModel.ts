import type { Election, ElectionCounts } from '@/lib/api/elections';
import type { TeamMember } from '@/lib/api/user';
import type { Locale } from '@/lib/i18n/locale';
import { daysUntil } from '@/components/elections/railModel';

/*
 * What the dashboard shows, worked out from the data the API gives. No vote count is ever
 * produced here: ballots stay at zero until the results exist (rule 6).
 */

export type StepKey = 'email' | 'institution' | 'election';

export type GettingStarted = {
  steps: { key: StepKey; done: boolean }[];
  done: number;
  total: number;
  /** 0, 33, 67 or 100. */
  percent: number;
  complete: boolean;
};

/** The three first steps: verify the e-mail, give the institution a logo, create the first election. */
export function gettingStarted(input: {
  emailVerified: boolean;
  hasLogo: boolean;
  hasElection: boolean;
}): GettingStarted {
  const steps: GettingStarted['steps'] = [
    { key: 'email', done: input.emailVerified },
    { key: 'institution', done: input.hasLogo },
    { key: 'election', done: input.hasElection },
  ];
  const done = steps.filter((step) => step.done).length;

  return {
    steps,
    done,
    total: steps.length,
    percent: Math.round((done / steps.length) * 100),
    complete: done === steps.length,
  };
}

export type Figures = {
  /** The elections that are not archived. */
  elections: number;
  /** The voters of those elections. */
  voters: number;
  /** Always zero for now: nothing is counted before an election is closed. */
  ballots: number;
};

export function figuresOf(elections: readonly Election[], counts: ElectionCounts): Figures {
  return {
    elections: counts.all,
    voters: elections
      .filter((election) => election.status !== 'archived')
      .reduce((sum, election) => sum + election.voters_count, 0),
    ballots: 0,
  };
}

/** The open election that ends first, or null. */
export function openElectionOf(elections: readonly Election[]): Election | null {
  const open = elections
    .filter((election) => election.status === 'open')
    .sort((a, b) => a.ends_at.localeCompare(b.ends_at));

  return open[0] ?? null;
}

/** How much of the voting time has passed, between 0 and 1 (time, not votes). */
export function elapsedShare(election: Pick<Election, 'starts_at' | 'ends_at'>, now: Date): number {
  const start = new Date(election.starts_at).getTime();
  const end = new Date(election.ends_at).getTime();

  if (!(end > start)) return 0;

  return Math.min(1, Math.max(0, (now.getTime() - start) / (end - start)));
}

/** The three newest elections that are not archived, the latest created first. */
export function latestOf(elections: readonly Election[], limit = 3): Election[] {
  return elections
    .filter((election) => election.status !== 'archived')
    .sort((a, b) => b.created_at.localeCompare(a.created_at))
    .slice(0, limit);
}

export type TodoRow = {
  election: Election;
  kind: 'draft' | 'soon';
  /** For `soon`: whole days until it starts (0 today). */
  inDays: number;
};

const SOON_MS = 7 * 86_400_000;

/** An election starting within seven days (the first to start), then up to three drafts. */
export function todoOf(elections: readonly Election[], now: Date, drafts = 3): TodoRow[] {
  const soon = elections
    .filter((election) => {
      const wait = new Date(election.starts_at).getTime() - now.getTime();

      return election.status === 'scheduled' && wait > 0 && wait <= SOON_MS;
    })
    .sort((a, b) => a.starts_at.localeCompare(b.starts_at))
    .slice(0, 1)
    .map((election): TodoRow => ({ election, kind: 'soon', inDays: daysUntil(election, now) }));
  const toFinish = elections
    .filter((election) => election.status === 'draft')
    .slice(0, drafts)
    .map((election): TodoRow => ({ election, kind: 'draft', inDays: 0 }));

  return [...soon, ...toFinish];
}

/** Today's date in words, in the institution's time zone (the device's, if the zone is unknown). */
export function todayText(now: Date, locale: Locale, zone: string): string {
  const options: Intl.DateTimeFormatOptions = { day: 'numeric', month: 'long', year: 'numeric' };

  try {
    return new Intl.DateTimeFormat(locale, { ...options, timeZone: zone }).format(now);
  } catch {
    return new Intl.DateTimeFormat(locale, options).format(now);
  }
}

/** How many owners and managers the institution has. */
export function roleCounts(members: readonly TeamMember[]): { owners: number; managers: number } {
  return {
    owners: members.filter((member) => member.role === 'owner').length,
    managers: members.filter((member) => member.role === 'manager').length,
  };
}

export type TimeLeft = {
  /** Which two units are worth showing: days and hours, hours and minutes, minutes, or none left. */
  kind: 'days' | 'hours' | 'minutes' | 'over';
  days: number;
  hours: number;
  minutes: number;
};

/** The time between `now` (ms) and an end, in whole days, hours and minutes (time only, never votes). */
export function timeLeft(endsAt: string, now: number): TimeLeft {
  const total = Math.max(0, Math.floor((new Date(endsAt).getTime() - now) / 60_000));
  const days = Math.floor(total / 1440);
  const hours = Math.floor((total % 1440) / 60);
  const minutes = total % 60;

  if (total === 0) return { kind: 'over', days, hours, minutes };
  if (days > 0) return { kind: 'days', days, hours, minutes };
  if (hours > 0) return { kind: 'hours', days, hours, minutes };

  return { kind: 'minutes', days, hours, minutes };
}

/** The first `limit` open elections of the dashboard's live data, and how many more there are. */
export function openShown<T>(open: readonly T[], limit = 3): { shown: T[]; more: number } {
  return { shown: open.slice(0, limit), more: Math.max(0, open.length - limit) };
}
