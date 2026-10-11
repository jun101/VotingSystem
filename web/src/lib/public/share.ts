import type { PublicBallot, PublicCandidate } from '@/lib/api/public';

/*
 * Pure helpers of the public results page: the absolute address of a page (from the request's
 * host, never a domain written in the code), the address of each share button, and the sentence
 * that sums up the winners. Nothing here reads a request or a message file.
 */

type HeaderReader = { get(name: string): string | null };

/** The first value of a header that a chain of proxies may have made a list. */
function first(value: string | null): string {
  return (value ?? '').split(',')[0]!.trim();
}

/**
 * The origin the visitor used (`https://vote.example`), from `x-forwarded-host` or `host` and
 * `x-forwarded-proto`. `http` is the fallback for a host on this machine, `https` for any other.
 */
export function originOf(headers: HeaderReader): string {
  const host = first(headers.get('x-forwarded-host')) || first(headers.get('host')) || 'localhost';
  const forwarded = first(headers.get('x-forwarded-proto')).toLowerCase();
  const local = /^(localhost|127\.0\.0\.1|\[::1\])(:\d+)?$/.test(host);
  const proto =
    forwarded === 'http' || forwarded === 'https' ? forwarded : local ? 'http' : 'https';

  return `${proto}://${host}`;
}

/** The absolute address of a path of this site. */
export function absoluteUrl(origin: string, path: string): string {
  return `${origin.replace(/\/+$/, '')}${path.startsWith('/') ? path : `/${path}`}`;
}

export type ShareLinks = { whatsapp: string; facebook: string; x: string };

/** The address each network's share page is given: the title and the absolute address of the results. */
export function shareLinks(title: string, url: string): ShareLinks {
  const text = encodeURIComponent(`${title} ${url}`);

  return {
    whatsapp: `https://wa.me/?text=${text}`,
    facebook: `https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(url)}`,
    x: `https://x.com/intent/post?text=${encodeURIComponent(title)}&url=${encodeURIComponent(url)}`,
  };
}

export function candidateName(
  candidate: Pick<PublicCandidate, 'first_name' | 'last_name'>,
): string {
  return `${candidate.first_name} ${candidate.last_name}`.trim();
}

/** The names of the winners of a ballot, in the order of its candidates. */
export function winnerNames(ballot: PublicBallot): string[] {
  return ballot.candidates
    .filter((candidate) => ballot.winners.includes(candidate.id))
    .map(candidateName);
}

/** The names of the tied candidates of a ballot, in the order of its candidates. */
export function tiedCandidates(ballot: PublicBallot): string[] {
  const tied = ballot.tie?.candidates ?? [];

  return ballot.candidates.filter((candidate) => tied.includes(candidate.id)).map(candidateName);
}

/** Whether a candidate of the ballot is a winner or one of the tied. */
export function isHighlighted(ballot: PublicBallot, id: string): boolean {
  return ballot.winners.includes(id) || (ballot.tie?.candidates.includes(id) ?? false);
}

/** A share of the votes of a ballot as a bar, between 0 and 1 (the percentage is of the cast papers). */
export function barOf(percentage: number | undefined): number {
  if (percentage === undefined || !(percentage > 0)) return 0;

  return Math.min(1, percentage / 100);
}

/**
 * The names of everyone elected in the election, ballot after ballot, without a name twice.
 * `[]` when nobody was (every ballot tied or without a candidate).
 */
export function electedNames(ballots: readonly PublicBallot[]): string[] {
  const names: string[] = [];

  for (const ballot of ballots) {
    for (const name of winnerNames(ballot)) {
      if (!names.includes(name)) names.push(name);
    }
  }

  return names;
}

/**
 * The two letters of an avatar: first letters of the first and last words, upper case. A copy of
 * the admin helper, kept here so the public page imports nothing from the admin area.
 */
export function initials(name: string): string {
  const words = name.split(/[\s-]+/).filter((word) => word !== '');

  if (words.length === 0) return '?';

  const letters =
    words.length === 1
      ? Array.from(words[0]!).slice(0, 2)
      : [Array.from(words[0]!)[0]!, Array.from(words[words.length - 1]!)[0]!];

  return letters.join('').toLocaleUpperCase();
}
