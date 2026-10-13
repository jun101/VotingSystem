import type { GuideRole } from '@/components/guide/guide-data';
import type { Locale } from './locale';
import en from './messages/guide/en.json';
import fr from './messages/guide/fr.json';

export type GuideSectionText = {
  title: string;
  lead: string;
  steps: string[];
  legend?: string[];
  tip?: string;
  commands?: string[];
};

export type GuideCatalog = {
  ui: Record<string, string>;
  roles: Record<
    GuideRole,
    { name: string; lead: string; sections: Record<string, GuideSectionText> }
  >;
};

const catalogs = { fr, en } as unknown as Record<Locale, GuideCatalog>;

/** The text of the manuals. Only the guide page imports it: the main catalogues stay light. */
export function getGuide(locale: Locale): GuideCatalog {
  return catalogs[locale];
}
