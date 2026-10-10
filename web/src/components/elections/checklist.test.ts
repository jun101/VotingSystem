import { describe, expect, it } from 'vitest';
import { itemMessageKey, itemText, scheduleDialogState, schedulingControls } from './checklist';

const t = (key: string, params?: Record<string, string | number>) =>
  `${key}|${JSON.stringify(params ?? {})}`;

describe('itemMessageKey', () => {
  it('has a sentence for each code of the checklist', () => {
    expect(itemMessageKey('no_ballots')).toBe('elections.checklist.items.no_ballots');
    expect(itemMessageKey('ballot_without_candidates')).toBe(
      'elections.checklist.items.ballot_without_candidates',
    );
    expect(itemMessageKey('group_ballot_few_voters')).toBe(
      'elections.checklist.items.group_ballot_few_voters',
    );
    expect(itemMessageKey('voters_without_ballot')).toBe(
      'elections.checklist.items.voters_without_ballot',
    );
  });

  it('falls back to the generic sentence for a code it does not know', () => {
    expect(itemMessageKey('something_new')).toBe('elections.checklist.items.unknown');
  });
});

describe('itemText', () => {
  it('gives the title of the position and the number of voters in words', () => {
    const text = itemText(
      { code: 'group_ballot_few_voters', ballot: { id: 'x', title: 'Délégué 6e' }, count: 3 },
      'fr',
      t as never,
    );

    expect(text).toContain('group_ballot_few_voters');
    expect(text).toContain('"title":"Délégué 6e"');
    expect(text).toContain('elections.checklist.voters.other');
  });

  it('uses the singular for one voter', () => {
    const text = itemText({ code: 'voters_without_ballot', count: 1 }, 'fr', t as never);

    expect(text).toContain('elections.checklist.voters.one');
  });
});

describe('scheduleDialogState', () => {
  it('asks for no tick when there is no warning', () => {
    expect(scheduleDialogState(0, false, false)).toEqual({ needsTick: false, canConfirm: true });
  });

  it('waits for the tick when there are warnings', () => {
    expect(scheduleDialogState(2, false, false)).toEqual({ needsTick: true, canConfirm: false });
    expect(scheduleDialogState(2, true, false)).toEqual({ needsTick: true, canConfirm: true });
  });

  it('cannot be confirmed while a call is running', () => {
    expect(scheduleDialogState(0, false, true).canConfirm).toBe(false);
    expect(scheduleDialogState(2, true, true).canConfirm).toBe(false);
  });
});

describe('schedulingControls', () => {
  it('shows the schedule button for a draft, disabled while a blocker or nothing is known', () => {
    expect(schedulingControls('draft', 0)).toEqual({
      showSchedule: true,
      scheduleDisabled: false,
      showUnschedule: false,
    });
    expect(schedulingControls('draft', 2).scheduleDisabled).toBe(true);
    expect(schedulingControls('draft', null).scheduleDisabled).toBe(true);
  });

  it('shows only the unschedule button for a scheduled election', () => {
    expect(schedulingControls('scheduled', 0)).toEqual({
      showSchedule: false,
      scheduleDisabled: false,
      showUnschedule: true,
    });
  });

  it('shows neither for the other statuses', () => {
    for (const status of ['open', 'closed', 'published', 'archived'] as const) {
      const controls = schedulingControls(status, 0);

      expect(controls.showSchedule).toBe(false);
      expect(controls.showUnschedule).toBe(false);
    }
  });
});
