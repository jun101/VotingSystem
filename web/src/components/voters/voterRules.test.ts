import { describe, expect, it } from 'vitest';
import type { ElectionStatus } from '@/lib/api/elections';
import { rulesOf } from './voterRules';

describe('rulesOf', () => {
  it.each<ElectionStatus>(['draft', 'scheduled'])(
    'allows everything in a %s election',
    (status) => {
      expect(rulesOf(status)).toEqual({ write: true, remove: true, groups: true, locked: false });
    },
  );

  it('lets an open election add and edit voters, nothing more', () => {
    expect(rulesOf('open')).toEqual({ write: true, remove: false, groups: false, locked: false });
  });

  it.each<ElectionStatus>(['closed', 'published', 'archived'])('locks a %s election', (status) => {
    expect(rulesOf(status)).toEqual({
      write: false,
      remove: false,
      groups: false,
      locked: true,
    });
  });
});
