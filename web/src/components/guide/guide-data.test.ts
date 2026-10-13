import { describe, expect, it } from 'vitest';
import { getGuide } from '@/lib/i18n/guide';
import { allowedGuides, GUIDE_ROLES, GUIDE_SECTIONS, guideRoleFor, shotOf } from './guide-data';

describe('allowedGuides', () => {
  it('gives a visitor only the voter manual', () => {
    expect(allowedGuides(null)).toEqual(['voter']);
  });

  it('gives each account the manuals of its tools', () => {
    expect(allowedGuides({ role: 'manager' })).toEqual(['voter', 'manager']);
    expect(allowedGuides({ role: 'owner' })).toEqual(['voter', 'manager', 'owner', 'install']);
    expect(allowedGuides({ role: 'platform_admin' })).toEqual(['voter', 'platform', 'install']);
  });

  it('points each account to its own manual', () => {
    expect(guideRoleFor(null)).toBe('voter');
    expect(guideRoleFor({ role: 'manager' })).toBe('manager');
    expect(guideRoleFor({ role: 'owner' })).toBe('owner');
    expect(guideRoleFor({ role: 'platform_admin' })).toBe('platform');
  });
});

describe('the manuals', () => {
  for (const locale of ['fr', 'en'] as const) {
    const catalog = getGuide(locale);

    it(`${locale}: has a name, a lead and every section of every manual`, () => {
      for (const role of GUIDE_ROLES) {
        const manual = catalog.roles[role];

        expect(manual.name, role).toBeTruthy();
        expect(manual.lead, role).toBeTruthy();

        for (const section of GUIDE_SECTIONS[role]) {
          const text = manual.sections[section.id];

          expect(text, `${role}.${section.id}`).toBeDefined();
          expect(text!.title).toBeTruthy();
          expect(text!.lead).toBeTruthy();
          expect(text!.steps.length, `${role}.${section.id} steps`).toBeGreaterThan(0);
        }
      }
    });

    it(`${locale}: has no section the structure does not list`, () => {
      for (const role of GUIDE_ROLES) {
        const listed = GUIDE_SECTIONS[role].map((section) => section.id).sort();

        expect(Object.keys(catalog.roles[role].sections).sort(), role).toEqual(listed);
      }
    });
  }

  it('has the same sections and the same number of legend lines in both languages', () => {
    const fr = getGuide('fr');
    const en = getGuide('en');

    for (const role of GUIDE_ROLES) {
      for (const section of GUIDE_SECTIONS[role]) {
        const a = fr.roles[role].sections[section.id]!;
        const b = en.roles[role].sections[section.id]!;

        expect(a.steps.length, `${role}.${section.id} steps`).toBe(b.steps.length);
        expect(a.legend?.length ?? 0, `${role}.${section.id} legend`).toBe(b.legend?.length ?? 0);
        expect(Boolean(a.tip), `${role}.${section.id} tip`).toBe(Boolean(b.tip));
        expect(a.commands?.length ?? 0).toBe(b.commands?.length ?? 0);
      }
    }
  });

  it('has a picture, taken by the capture spec, for every section that names one', () => {
    for (const role of GUIDE_ROLES) {
      for (const section of GUIDE_SECTIONS[role]) {
        if (section.shot)
          expect(shotOf(section.shot), `${role}.${section.id}: ${section.shot}`).not.toBeNull();
      }
    }
  });

  it('has one legend line for every marker of a picture, so no marker is unexplained', () => {
    const fr = getGuide('fr');

    for (const role of GUIDE_ROLES) {
      for (const section of GUIDE_SECTIONS[role]) {
        const shot = shotOf(section.shot);

        if (!shot) continue;
        const legend = fr.roles[role].sections[section.id]!.legend ?? [];

        expect(legend.length, `${role}.${section.id}: legend and markers`).toBe(
          shot.markers.length,
        );
      }
    }
  });
});
