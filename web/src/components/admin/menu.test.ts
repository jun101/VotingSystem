import { describe, expect, it } from 'vitest';
import { entryOf, fold, initials, titleKeyOf } from './menu';

describe('entryOf', () => {
  it('finds the entry of a page, the deeper pages of Élections included', () => {
    expect(entryOf('/admin').key).toBe('dashboard');
    expect(entryOf('/admin/elections').key).toBe('elections');
    expect(entryOf('/admin/elections/new').key).toBe('elections');
    expect(entryOf('/admin/institution').key).toBe('institution');
    expect(entryOf('/admin/audit').key).toBe('audit');
  });

  it('does not take a path that only starts with a name for that entry', () => {
    expect(entryOf('/admin/elections-old').key).toBe('dashboard');
  });
});

describe('titleKeyOf', () => {
  it('gives the new-election title to its page and the label of the entry to the others', () => {
    expect(titleKeyOf('/admin/elections/new')).toBe('admin.nav.newElection');
    expect(titleKeyOf('/admin/audit')).toBe('admin.nav.audit');
  });
});

describe('fold', () => {
  it('ignores case and accents', () => {
    expect(fold('ÉLECTIONS')).toBe('elections');
    expect(fold("Journal d'audit")).toBe("journal d'audit");
  });
});

describe('initials', () => {
  it('takes the first letter of the first two words', () => {
    expect(initials('Collège Les Flamboyants')).toBe('CL');
    expect(initials('  marie ')).toBe('M');
    expect(initials('')).toBe('');
  });
});
