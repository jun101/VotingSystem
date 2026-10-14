import Link from 'next/link';
import { LogoMark } from '@/components/ui';
import { LanguageToggle } from '@/components/vote/LanguageToggle';
import type { Translate } from '@/lib/i18n/messages';
import { HomeMenu } from './HomeMenu';
import { linkStyles } from './links';

/**
 * The top line, white and fixed to the top while the page scrolls: the product, the section links,
 * the language, and the two ways in. Below `md` the links and the two buttons move into a menu.
 */
export function HomeNav({ t }: { t: Translate }) {
  const items = [
    { href: '#top', label: t('home.nav.home') },
    { href: '#how', label: t('home.nav.how') },
    { href: '#security', label: t('home.nav.security') },
    { href: '#faq', label: t('home.nav.faq') },
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

        <nav aria-label={t('home.nav.label')} className="ml-2 hidden items-center gap-5 lg:flex">
          {items.map((item) => (
            <a
              key={item.href}
              href={item.href}
              className="rounded-sm text-base font-semibold text-ink-soft hover:text-primary-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
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
        <Link
          href={create.href}
          data-testid="home-nav-create"
          className={`${linkStyles.primary} max-lg:hidden`}
        >
          {create.label}
        </Link>

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
