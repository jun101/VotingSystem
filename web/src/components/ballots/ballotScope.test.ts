import { describe, expect, it } from 'vitest';
import { getMessages, translate, type MessageKey, type MessageParams } from '@/lib/i18n/messages';
import {
  ballotsCovering,
  groupNames,
  scopeText,
  toggleGroup,
  warningText,
  type ScopedBallot,
} from './ballotScope';

const fr = (key: MessageKey, params?: MessageParams) => translate(getMessages('fr'), key, params);
const en = (key: MessageKey, params?: MessageParams) => translate(getMessages('en'), key, params);

const ballot = (changes: Partial<ScopedBallot>): ScopedBallot => ({
  title: 'Délégué',
  scope: 'group',
  groups: [{ id: 'a', name: '4e année' }],
  eligible_voters: 6,
  warnings: [],
  ...changes,
});

describe('toggleGroup', () => {
  it('adds a group that is not chosen, at the end', () => {
    expect(toggleGroup(['a'], 'b')).toEqual(['a', 'b']);
  });

  it('removes a group that is chosen and keeps the order of the others', () => {
    expect(toggleGroup(['a', 'b', 'c'], 'b')).toEqual(['a', 'c']);
  });

  it('does not change the list it was given', () => {
    const chosen = ['a'];

    toggleGroup(chosen, 'b');

    expect(chosen).toEqual(['a']);
  });
});

describe('scopeText', () => {
  it('says who is eligible, with the number of voters in words', () => {
    expect(scopeText(ballot({}), 'fr', fr)).toContain('6 électeurs');
    expect(scopeText(ballot({ scope: 'general', eligible_voters: 1 }), 'fr', fr)).toContain(
      '1 électeur',
    );
    expect(scopeText(ballot({ eligible_voters: 1 }), 'en', en)).toContain('1 voter');
  });

  it('tells a general position from a group one', () => {
    expect(scopeText(ballot({ scope: 'general' }), 'fr', fr)).not.toBe(
      scopeText(ballot({ scope: 'group' }), 'fr', fr),
    );
  });
});

describe('groupNames', () => {
  it('joins the names in the order given', () => {
    expect(
      groupNames(
        ballot({
          groups: [
            { id: 'a', name: '4e année' },
            { id: 'b', name: '5e année' },
          ],
        }),
      ),
    ).toBe('4e année, 5e année');
    expect(groupNames(ballot({ groups: [] }))).toBe('');
  });
});

describe('warningText', () => {
  it('has none without a warning', () => {
    expect(warningText(ballot({}), 'fr', fr)).toBeNull();
  });

  it('names the number of voters when there are few, and says none when there is none', () => {
    expect(
      warningText(ballot({ eligible_voters: 2, warnings: ['few_voters'] }), 'fr', fr),
    ).toContain('2 électeurs');
    expect(warningText(ballot({ eligible_voters: 0, warnings: ['no_voters'] }), 'fr', fr)).toBe(
      fr('ballots.card.noVoters'),
    );
  });
});

describe('ballotsCovering', () => {
  const ballots = [
    { title: 'Président', groups: [] },
    { title: 'Délégué', groups: [{ id: 'a' }, { id: 'b' }] },
    { title: 'Trésorier', groups: [{ id: 'b' }] },
    { title: 'Secrétaire', groups: [{ id: 'a' }] },
  ];

  it('lists the titles of the positions that cover the group, in the order of the list', () => {
    expect(ballotsCovering(ballots, 'a')).toEqual(['Délégué', 'Secrétaire']);
    expect(ballotsCovering(ballots, 'b')).toEqual(['Délégué', 'Trésorier']);
  });

  it('is empty for a group no position covers', () => {
    expect(ballotsCovering(ballots, 'c')).toEqual([]);
  });
});
