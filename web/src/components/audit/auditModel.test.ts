import { describe, expect, it } from 'vitest';
import { NO_AUDIT_FILTERS } from '@/lib/api/audit';
import en from '@/lib/i18n/messages/en.json';
import fr from '@/lib/i18n/messages/fr.json';
import { translateIfAny } from '@/lib/i18n/messages';
import { actionKey, familyOf, hasMore, iconOf, isFiltering, whenOf } from './auditModel';

const CODES = [
  'auth.sign_in',
  'election.created',
  'election.duplicated',
  'election.updated',
  'election.times_changed',
  'election.scheduled',
  'election.unscheduled',
  'election.opened',
  'election.closed',
  'election.extended',
  'election.published',
  'election.unpublished',
  'election.archived',
  'election.unarchived',
  'election.deleted',
  'second_round.created',
  'voters.imported',
  'voter.created',
  'voter.updated',
  'voter.deleted',
  'credentials.generated',
  'credentials.reissued',
  'credentials.downloaded',
  'credentials.emailed',
];

describe('audit model', () => {
  it('says every recorded action in words, in both languages', () => {
    for (const messages of [fr, en]) {
      for (const code of CODES) {
        expect(translateIfAny(messages as never, actionKey(code)), code).not.toBeNull();
      }
    }
  });

  it('gives no text for a code it does not know, so the page shows the raw code', () => {
    expect(translateIfAny(fr as never, actionKey('election.teleported'))).toBeNull();
  });

  it('finds the family of a code, and none for an unknown prefix', () => {
    expect(familyOf('voter.deleted')).toBe('voter');
    expect(familyOf('voters.imported')).toBe('voters');
    expect(familyOf('second_round.created')).toBe('second_round');
    expect(familyOf('mystery.thing')).toBeNull();
    expect(iconOf('mystery.thing')).toBe('log');
  });

  it('knows when a filter is set and when more is to read', () => {
    expect(isFiltering(NO_AUDIT_FILTERS)).toBe(false);
    expect(isFiltering({ ...NO_AUDIT_FILTERS, from: '2026-10-01' })).toBe(true);
    expect(hasMore(50, 112)).toBe(true);
    expect(hasMore(112, 112)).toBe(false);
  });

  it('writes the UTC instant and refuses a date that is not one', () => {
    expect(whenOf('2026-10-11T15:02:11Z', 'en', 'UTC')?.instant).toBe('2026-10-11 15:02:11');
    expect(whenOf('nope', 'en')).toBeNull();
  });
});
