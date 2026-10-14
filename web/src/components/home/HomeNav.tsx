import Link from 'next/link';
import { Icon } from '@/components/admin/Icon';
import { LogoMark } from '@/components/ui';
import { LanguageToggle } from '@/components/vote/LanguageToggle';
import type { Translate } from '@/lib/i18n/messages';
import { HomeMenu } from './HomeMenu';
import { linkStyles } from './links';

/**
 * The top line, white and fixed to the top while the page scrolls: the product, the section links,
 * the language, and the two ways in. Below `md` the links and the two buttons move into a menu.
 */
export function HomeNav({ t, onHome = true }: { t: Translate; onHome?: boolean }) {
  // Away from the home page the section links go back to it.
  const base = onHome ? '' : '/';
  const items = [
    { href: onHome ? '#top' : '/', label: t('home.nav.home') },
    { href: `${base}#how`, label: t('home.nav.how') },
    { href: `${base}#security`, label: t('home.nav.security') },
    { href: `${base}#faq`, label: t('home.nav.faq') },
    { href: '/developer', label: t('home.nav.developer') },
  ];
  const signIn = { href: '/login', label: t('home.nav.signIn') };
  const create = { href: '/register', label: t('home.nav.create') };

  return (
    <header className="sticky top-0 z-20 border-b border-line-soft bg-surface/85 backdrop-blur-md">
      <div className="mx-auto flex h-19 w-full max-w-6xl items-center gap-3 px-4 md:gap-6">
        <Link
          href="/"
          className="flex w-fit shrink-0 items-center gap-2.5 rounded-sm font-display text-lg font-extrabold text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
        >
          <LogoMark size={36} />
          <span className="max-sm:sr-only">{t('app.name')}</span>
        </Link>

        <nav
          aria-label={t('home.nav.label')}
          className="ml-2 hidden items-center gap-4 lg:flex xl:gap-5"
        >
          {items.map((item) => (
            <a
              key={item.href}
              href={item.href}
              className="rounded-sm text-base font-semibold whitespace-nowrap text-ink-soft hover:text-primary-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
            >
              {item.label}
            </a>
          ))}
        </nav>

        <span className="flex-1" />

        <LanguageToggle site />

        <Link
          href={signIn.href}
          data-testid="home-nav-signin"
          className={`${linkStyles.text} max-md:hidden`}
        >
          {signIn.label}
        </Link>
        <span className="group relative max-lg:hidden">
          <Link
            href={create.href}
            data-testid="home-nav-create"
            aria-label={create.label}
            className="ui-control relative inline-flex size-11 items-center justify-center rounded-full border border-primary bg-primary text-surface shadow-button hover:border-primary-hover hover:bg-primary-hover focus-visible:ring-4 focus-visible:ring-primary-soft focus-visible:outline-2 focus-visible:outline-primary"
          >
            <Icon name="ballot" size={24} />
            <span
              aria-hidden="true"
              className="absolute -top-1 -right-1 flex size-5 items-center justify-center rounded-full bg-accent text-deep ring-2 ring-surface"
            >
              <Icon name="plus" size={12} />
            </span>
          </Link>
          <span
            role="tooltip"
            aria-hidden="true"
            className="pointer-events-none absolute top-full right-0 mt-2 rounded-md bg-night px-3 py-1.5 text-sm font-semibold whitespace-nowrap text-surface opacity-0 shadow-3 transition-opacity duration-150 group-focus-within:opacity-100 group-hover:opacity-100"
          >
            {create.label}
          </span>
        </span>

        <HomeMenu
          items={items}
          signIn={signIn}
          create={create}
          openLabel={t('home.nav.menu')}
          closeLabel={t('home.nav.close')}
        />
      </div>
    </header>
  );
}
