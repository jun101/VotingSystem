import type { MessageKey } from '@/lib/i18n/messages';

export type MenuKey = 'dashboard' | 'elections' | 'institution' | 'audit';

export type MenuEntry = {
  key: MenuKey;
  href: string;
  label: MessageKey;
  /** The path of a 24 x 24 icon, drawn with the text colour. */
  icon: string;
};

/** The entries of the "Établissement" section of the side menu, in order. */
export const MENU_ENTRIES: readonly MenuEntry[] = [
  {
    key: 'dashboard',
    href: '/admin',
    label: 'admin.nav.dashboard',
    icon: 'M4 13h6V4H4zM14 20h6V4h-6zM4 20h6v-4H4z',
  },
  {
    key: 'elections',
    href: '/admin/elections',
    label: 'admin.nav.elections',
    icon: 'M9 11l3 3 8-8M20 12v7a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2h9',
  },
  {
    key: 'institution',
    href: '/admin/institution',
    label: 'admin.nav.institution',
    icon: 'M3 21h18M5 21V9l7-5 7 5v12M9 21v-6h6v6',
  },
  {
    key: 'audit',
    href: '/admin/audit',
    label: 'admin.nav.audit',
    icon: 'M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8zM14 3v5h5M9 13h6M9 17h6',
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

/** The title of the page of a path: the label of its entry, or the one of the new-election page. */
export function titleKeyOf(pathname: string): MessageKey {
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
