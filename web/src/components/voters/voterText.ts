import type { VoterGroup } from '@/lib/api/voters';
import { VOTERS_PER_PAGE } from '@/lib/api/voters';
import { pluralForm } from '@/lib/format/electionDates';
import type { Locale } from '@/lib/i18n/locale';
import type { MessageKey, MessageParams } from '@/lib/i18n/messages';

type T = (key: MessageKey, params?: MessageParams) => string;

/** `0 électeur`, `1 électeur`, `312 électeurs` (the French plural counts zero as one). */
export function votersText(count: number, locale: Locale, t: T): string {
  return t(`voters.count.${pluralForm(locale, count)}`, { count });
}

/** `0 groupe`, `1 groupe`, `4 groupes`. */
export function groupsText(count: number, locale: Locale, t: T): string {
  return t(`groups.count.${pluralForm(locale, count)}`, { count });
}

/** The number of pages of a list of this size. */
export function pageCount(total: number): number {
  return Math.max(1, Math.ceil(total / VOTERS_PER_PAGE));
}

/** The first and the last place (from 1) shown on a page; both 0 for an empty list. */
export function rangeOf(page: number, total: number): { from: number; to: number } {
  if (total <= 0) return { from: 0, to: 0 };

  const from = (page - 1) * VOTERS_PER_PAGE + 1;

  return { from, to: Math.min(page * VOTERS_PER_PAGE, total) };
}

/** `1 à 24 sur 312 électeurs`. */
export function rangeText(page: number, total: number, locale: Locale, t: T): string {
  const { from, to } = rangeOf(page, total);

  return t(`voters.range.${pluralForm(locale, total)}`, { from, to, total });
}

/**
 * The page numbers to show: the first, the last and the neighbours of the current one, with
 * `'gap'` where numbers are left out. Six pages or fewer are all shown.
 */
export function pageItems(page: number, pages: number): (number | 'gap')[] {
  const wanted = new Set([1, pages, page - 1, page, page + 1]);
  const numbers = [...wanted].filter((n) => n >= 1 && n <= pages).sort((a, b) => a - b);
  const items: (number | 'gap')[] = [];

  for (const [index, n] of numbers.entries()) {
    const before = numbers[index - 1];

    if (before !== undefined && n - before === 2) items.push(before + 1);
    else if (before !== undefined && n - before > 2) items.push('gap');

    items.push(n);
  }

  return items;
}

/** The two letters of the avatar: the first letters of the first and last words, upper case. */
export function initialsOf(name: string): string {
  const words = name.split(/[\s-]+/).filter((word) => word !== '');

  if (words.length === 0) return '?';

  const letters =
    words.length === 1
      ? Array.from(words[0]!).slice(0, 2)
      : [Array.from(words[0]!)[0]!, Array.from(words[words.length - 1]!)[0]!];

  return letters.join('').toLocaleUpperCase();
}

/** A stable number from 0 to `of - 1` for a name, to give each avatar one of a few colours. */
export function toneOf(name: string, of: number): number {
  let sum = 0;

  for (const char of name) sum = (sum * 31 + (char.codePointAt(0) ?? 0)) % 9973;

  return sum % of;
}

function plain(text: string): string {
  return text.normalize('NFD').replace(/\p{M}/gu, '').trim().toLocaleLowerCase();
}

/**
 * The groups offered under the group field as the person types: names that contain the text,
 * ignoring case and accents, those that start with it first; every group when the field is
 * empty. A name that is exactly the text is not offered again.
 */
export function suggestGroups(
  groups: Pick<VoterGroup, 'name'>[],
  typed: string,
  limit = 8,
): string[] {
  const wanted = plain(typed);
  const names = groups
    .map((group) => group.name)
    .filter((name) => plain(name) !== wanted || wanted === '');

  if (wanted === '') return names.slice(0, limit);

  const found = names.filter((name) => plain(name).includes(wanted));
  const starts = found.filter((name) => plain(name).startsWith(wanted));

  return [...starts, ...found.filter((name) => !starts.includes(name))].slice(0, limit);
}
