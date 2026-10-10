import type { ElectionStatus } from '@/lib/api/elections';
import { pluralForm } from '@/lib/format/electionDates';
import type { Locale } from '@/lib/i18n/locale';
import type { MessageKey, MessageParams } from '@/lib/i18n/messages';

type T = (key: MessageKey, params?: MessageParams) => string;

/** A blocker or a warning, as the checklist (and `error.items`) gives it. */
export type Item = { code: string; ballot?: { id: string; title: string }; count?: number };

/** The codes the message files have a sentence for. */
const KNOWN = [
  'no_ballots',
  'ballot_without_candidates',
  'no_voters',
  'ballot_single_candidate',
  'group_ballot_no_voters',
  'group_ballot_few_voters',
  'voters_without_ballot',
] as const;

type KnownCode = (typeof KNOWN)[number];

/** The message of an item: its own code, or the generic one for a code this page does not know. */
export function itemMessageKey(code: string): MessageKey {
  return (KNOWN as readonly string[]).includes(code)
    ? (`elections.checklist.items.${code as KnownCode}` as MessageKey)
    : 'elections.checklist.items.unknown';
}

/** An item in words: the title of its position and the number of voters, when it has them. */
export function itemText(item: Item, locale: Locale, t: T): string {
  const count = item.count ?? 0;

  return t(itemMessageKey(item.code), {
    title: item.ballot?.title ?? '',
    voters: t(`elections.checklist.voters.${pluralForm(locale, count)}`, { count }),
  });
}

/** What the schedule dialog shows and allows. */
export type ScheduleDialogState = {
  /** The warning tick is shown (only when there are warnings). */
  needsTick: boolean;
  /** The confirm button can be pressed. */
  canConfirm: boolean;
};

/** The tick is asked only for warnings; the button waits for it, and for a call in flight. */
export function scheduleDialogState(
  warnings: number,
  accepted: boolean,
  busy: boolean,
): ScheduleDialogState {
  const needsTick = warnings > 0;

  return { needsTick, canConfirm: !busy && (!needsTick || accepted) };
}

/** Which scheduling buttons the summary page shows, from the status and the checklist. */
export type SchedulingControls = {
  showSchedule: boolean;
  scheduleDisabled: boolean;
  showUnschedule: boolean;
};

/**
 * The schedule button is for a draft only and is disabled while the checklist is unknown or has a
 * blocker; the unschedule button is for a scheduled election only.
 */
export function schedulingControls(
  status: ElectionStatus,
  blockers: number | null,
): SchedulingControls {
  return {
    showSchedule: status === 'draft',
    scheduleDisabled: blockers === null || blockers > 0,
    showUnschedule: status === 'scheduled',
  };
}
