import type { MessageKey } from '@/lib/i18n/messages';
import type { IconName } from './Icon';

export type MenuKey = 'dashboard' | 'elections' | 'institution' | 'audit';

export type MenuEntry = {
  key: MenuKey;
  href: string;
  label: MessageKey;
  /** The icon of the entry, drawn with the text colour. */
  icon: IconName;
};

/** The entries of the "Établissement" section of the side menu, in order. */
export const MENU_ENTRIES: readonly MenuEntry[] = [
  {
    key: 'dashboard',
    href: '/admin',
    label: 'admin.nav.dashboard',
    icon: 'grid',
  },
  {
    key: 'elections',
    href: '/admin/elections',
    label: 'admin.nav.elections',
    icon: 'ballot',
  },
  {
    key: 'institution',
    href: '/admin/institution',
    label: 'admin.nav.institution',
    icon: 'school',
  },
  {
    key: 'audit',
    href: '/admin/audit',
    label: 'admin.nav.audit',
    icon: 'log',
  },
];

/** The entry a path belongs to: `/admin/elections/new` is part of Élections. */
export function entryOf(pathname: string): MenuEntry {
  const found = MENU_ENTRIES.find(
    (entry) =>
      entry.href !== '/admin' && (pathname === entry.href || pathname.startsWith(`${entry.href}/`)),
  );

  return found ?? MENU_ENTRIES[0]!;
}

/** "Mon compte": reached from the user menu, it is not an entry of the side menu. */
export const ACCOUNT_PATH = '/admin/account';

/**
 * The title of the page of a path: the label of its entry, or the one of the new-election page
 * or of the account page.
 */
export function titleKeyOf(pathname: string): MessageKey {
  if (pathname === ACCOUNT_PATH) return 'admin.nav.account';

  return pathname === '/admin/elections/new' ? 'admin.nav.newElection' : entryOf(pathname).label;
}

/** Lower case without accents, to compare what is typed with a label. */
export function fold(text: string): string {
  return text
    .normalize('NFD')
    .replace(/\p{Diacritic}/gu, '')
    .toLowerCase();
}

/** The first letters of the first two words: "Collège Les Flamboyants" gives "CL". */
export function initials(name: string): string {
  return name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((word) => Array.from(word)[0]!.toUpperCase())
    .join('');
}
