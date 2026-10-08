'use client';

import Link from 'next/link';
import { usePathname, useRouter } from 'next/navigation';
import { useMemo, useState, type KeyboardEvent, type RefObject } from 'react';
import type { CurrentUser } from '@/lib/api/user';
import { useI18n } from '@/lib/i18n/client';
import type { MessageKey } from '@/lib/i18n/messages';
import { navyFocus } from './classes';
import { Icon } from './Icon';
import { LanguageSwitch } from './LanguageSwitch';
import { entryOf, fold, initials, MENU_ENTRIES } from './menu';

const SEARCH = 'M11 17.5a6.5 6.5 0 1 0 0-13 6.5 6.5 0 0 0 0 13zM16 16l4.5 4.5';
const PLUS = 'M12 5v14M5 12h14';
const CLOSE = 'M6 6l12 12M18 6L6 18';

export const ROLE_KEYS = {
  owner: 'admin.roles.owner',
  manager: 'admin.roles.manager',
  platform_admin: 'admin.roles.platform_admin',
} as const satisfies Record<CurrentUser['role'], MessageKey>;

type SideMenuProps = {
  user: CurrentUser;
  searchRef: RefObject<HTMLInputElement | null>;
  closeRef: RefObject<HTMLButtonElement | null>;
  /** The drawer closes when a page is chosen (below `lg`). */
  onNavigate: () => void;
  /** The address of the institution's logo (64 px), when it has one. */
  logo?: string | null;
};

/**
 * The side menu ("Menu latéral" mockup): the institution, the "go to" search, the new
 * election button, the institution's pages, the chosen election, and the person at the
 * bottom. Without any election yet the card says so and the election section is absent.
 */
export function SideMenu({ user, searchRef, closeRef, onNavigate, logo = null }: SideMenuProps) {
  const { t } = useI18n();
  const router = useRouter();
  const pathname = usePathname();
  const [query, setQuery] = useState('');
  const current = entryOf(pathname).key;

  const matches = useMemo(() => {
    const wanted = fold(query.trim());

    return MENU_ENTRIES.filter((entry) => wanted === '' || fold(t(entry.label)).includes(wanted));
  }, [query, t]);

  const institutionName = user.institution?.name ?? '';

  function open(href: string) {
    setQuery('');
    onNavigate();
    router.push(href);
  }

  function onSearchKey(event: KeyboardEvent<HTMLInputElement>) {
    if (event.key === 'Enter') {
      event.preventDefault();
      const first = matches[0];

      if (first) open(first.href);
    }
  }

  const itemClass = (selected: boolean) =>
    `ui-control flex min-h-11 shrink-0 items-center gap-3 rounded px-3 text-base font-semibold text-surface ${
      selected ? 'bg-navy-raised' : 'hover:bg-navy-raised'
    } ${navyFocus}`;

  return (
    <aside
      data-testid="side-menu"
      className="flex h-full w-[272px] flex-col gap-1 overflow-y-auto bg-navy px-3.5 pt-4 pb-4 text-surface"
    >
      <div className="flex shrink-0 items-center gap-2.5 px-1.5 pb-3">
        {logo ? (
          // The files are already optimised (64 px): a plain image, with its size set. The name
          // is written next to it, so the image says nothing more.
          // eslint-disable-next-line @next/next/no-img-element
          <img
            src={logo}
            alt=""
            width={38}
            height={38}
            data-testid="menu-institution-logo"
            className="size-[38px] shrink-0 rounded bg-surface object-cover"
          />
        ) : (
          <span
            aria-hidden="true"
            className="flex size-[38px] shrink-0 items-center justify-center rounded bg-surface text-sm font-bold text-navy"
          >
            {initials(institutionName)}
          </span>
        )}
        <span className="flex min-w-0 flex-1 flex-col">
          <span className="font-display text-lg font-bold">{t('app.name')}</span>
          <span className="truncate text-xs text-primary-line">{institutionName}</span>
        </span>
        <button
          ref={closeRef}
          type="button"
          onClick={onNavigate}
          aria-label={t('admin.shell.menuClose')}
          className={`ui-control flex size-11 shrink-0 items-center justify-center rounded hover:bg-navy-raised lg:hidden ${navyFocus}`}
        >
          <Icon path={CLOSE} size={20} />
        </button>
      </div>

      <div className="flex h-11 shrink-0 items-center gap-2 rounded border border-ink-2 bg-navy-raised px-2.5 focus-within:border-accent-light">
        <span className="text-primary-line">
          <Icon path={SEARCH} size={16} />
        </span>
        <label htmlFor="menu-search" className="sr-only">
          {t('admin.menu.searchLabel')}
        </label>
        <input
          ref={searchRef}
          id="menu-search"
          type="search"
          autoComplete="off"
          value={query}
          placeholder={t('admin.menu.search')}
          onChange={(event) => setQuery(event.target.value)}
          onKeyDown={onSearchKey}
          data-testid="menu-search"
          className="h-10 min-w-0 flex-1 appearance-none border-0 bg-transparent text-base text-surface outline-none placeholder:text-ink-muted"
        />
        <kbd
          aria-hidden="true"
          className="rounded-sm border border-ink-muted px-1.5 text-xs text-primary-line"
        >
          {'/'}
        </kbd>
      </div>

      <Link
        href="/admin/elections/new"
        onClick={onNavigate}
        data-testid="menu-new-election"
        className={`ui-control my-1.5 flex h-11 shrink-0 items-center justify-center gap-2 rounded bg-surface text-base font-bold text-navy hover:bg-primary-soft ${navyFocus}`}
      >
        <Icon path={PLUS} size={16} />
        {t('admin.menu.newElection')}
      </Link>

      <nav aria-label={t('admin.shell.menuLabel')} className="flex shrink-0 flex-col gap-1">
        <span className="px-2.5 pt-2.5 pb-1 text-xs font-bold tracking-wider text-primary-line uppercase">
          {t('admin.menu.sectionInstitution')}
        </span>
        {matches.map((entry) => (
          <Link
            key={entry.key}
            href={entry.href}
            onClick={onNavigate}
            aria-current={entry.key === current ? 'page' : undefined}
            data-testid={`menu-link-${entry.key}`}
            className={itemClass(entry.key === current)}
          >
            <Icon path={entry.icon} />
            {t(entry.label)}
          </Link>
        ))}
        {matches.length === 0 ? (
          <p
            data-testid="menu-search-empty"
            role="status"
            className="px-2.5 py-2 text-sm text-primary-line"
          >
            {t('admin.menu.searchEmpty')}
          </p>
        ) : null}
      </nav>

      <section
        data-testid="menu-election-card"
        aria-labelledby="menu-election-title"
        className="mt-3 flex shrink-0 flex-col gap-2 rounded-md border border-ink-2 bg-navy-raised p-3"
      >
        <p
          id="menu-election-title"
          className="text-xs font-bold tracking-wider text-primary-line uppercase"
        >
          {t('admin.menu.electionTitle')}
        </p>
        <p className="font-display text-lg leading-tight font-bold">{t('admin.menu.noElection')}</p>
        <p className="text-xs text-primary-line">{t('admin.menu.noElectionHelp')}</p>
        <Link
          href="/admin/elections"
          onClick={onNavigate}
          className={`ui-control flex min-h-11 items-center rounded text-sm font-semibold text-surface underline ${navyFocus}`}
        >
          {t('admin.menu.electionList')}
        </Link>
      </section>

      <div className="min-h-4 flex-1" />

      <div className="flex shrink-0 flex-col gap-3 border-t border-ink-2 pt-3">
        <div className="flex items-center gap-2.5 px-1">
          <span
            aria-hidden="true"
            className="flex size-9 shrink-0 items-center justify-center rounded-full bg-warm text-sm font-bold text-surface"
          >
            {initials(user.name)}
          </span>
          <span className="flex min-w-0 flex-col">
            <span data-testid="menu-user-name" className="truncate text-base font-semibold">
              {user.name}
            </span>
            <span data-testid="menu-user-role" className="truncate text-xs text-primary-line">
              {t(ROLE_KEYS[user.role])}
            </span>
          </span>
        </div>
        <LanguageSwitch />
      </div>
    </aside>
  );
}
