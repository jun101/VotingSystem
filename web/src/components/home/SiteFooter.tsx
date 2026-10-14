import Link from 'next/link';
import type { ReactNode } from 'react';
import { LiveDot } from '@/components/motion';
import { LogoMark, Pill } from '@/components/ui';
import { formatDateTime } from '@/lib/format/dateTime';
import type { Locale } from '@/lib/i18n/locale';
import type { Translate } from '@/lib/i18n/messages';
import { DEVELOPER_LINKS } from '@/lib/public/developer';
import { linkStyles } from './links';

type State = 'ok' | 'down' | 'unknown';

export type FooterStatus = {
  rows: { id: 'api' | 'database' | 'redis'; label: string; state: State }[];
  overall: 'overallOk' | 'overallDown' | 'overallUnknown';
  time: string | null;
};

const PILL = {
  ok: { tone: 'teal', key: 'home.status.online' },
  down: { tone: 'danger', key: 'home.status.offline' },
  unknown: { tone: 'neutral', key: 'home.status.unknown' },
} as const;

const SOCIAL_LINK =
  'flex size-11 items-center justify-center rounded-full bg-glass text-surface transition-colors hover:bg-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-light';

function Social({ href, label, children }: { href: string; label: string; children: ReactNode }) {
  return (
    <a href={href} aria-label={label} rel="noopener noreferrer" className={SOCIAL_LINK}>
      <svg
        viewBox="0 0 24 24"
        width="20"
        height="20"
        fill="none"
        stroke="currentColor"
        strokeWidth="1.8"
        strokeLinecap="round"
        strokeLinejoin="round"
        aria-hidden="true"
      >
        {children}
      </svg>
    </a>
  );
}

const COLUMN_TITLE = 'text-xs font-bold tracking-wide text-surface/50 uppercase';
const COLUMN_LINK = `${linkStyles.footer} block py-1.5 transition-transform hover:translate-x-1`;

/**
 * The big footer shared by the home page and the developer page: near-black, the product and the
 * developer's profiles on the left, four columns of links on the right, a quiet legal row, and a
 * large faded name along the bottom. On the home page it also carries the state of the system.
 */
export function SiteFooter({
  t,
  locale,
  onHome,
  status,
}: {
  t: Translate;
  locale: Locale;
  /** On the home page the section links are anchors; anywhere else they go back to it. */
  onHome: boolean;
  status?: FooterStatus;
}) {
  const home = onHome ? '' : '/';

  return (
    <footer className="relative overflow-hidden bg-night text-surface/60">
      <div className="relative z-10 mx-auto w-full max-w-6xl px-4">
        <div className="grid gap-10 pt-16 pb-12 md:grid-cols-[minmax(0,1.2fr)_minmax(0,2fr)] md:gap-14 md:pt-20">
          <div className="flex flex-col items-start gap-4">
            <p className="flex items-center gap-2.5 font-display text-xl font-extrabold text-surface">
              <LogoMark size={36} />
              {t('app.name')}
            </p>
            <p className="max-w-xs text-md text-surface/60">{t('home.footer.tagline')}</p>
            <ul aria-label={t('home.footer.social.label')} className="flex gap-3">
              <li>
                <Social href={DEVELOPER_LINKS.linkedin} label={t('home.footer.social.linkedin')}>
                  <rect x="3" y="3" width="18" height="18" rx="3" />
                  <path d="M8 11v5M8 8v.01M12 16v-5M12 13a3 3 0 0 1 6 0v3" />
                </Social>
              </li>
              <li>
                <Social href={DEVELOPER_LINKS.github} label={t('home.footer.social.github')}>
                  <path d="M9 19c-4 1.2-4-2-6-2m12 4v-3.5a3 3 0 0 0-.8-2.3c2.8-.3 5.8-1.4 5.8-6.2a4.8 4.8 0 0 0-1.3-3.3 4.5 4.5 0 0 0-.1-3.3s-1.1-.3-3.5 1.3a12 12 0 0 0-6.2 0C6.5 2.8 5.4 3.1 5.4 3.1a4.5 4.5 0 0 0-.1 3.3A4.8 4.8 0 0 0 4 9.7c0 4.8 3 5.9 5.8 6.2A3 3 0 0 0 9 18.2V21" />
                </Social>
              </li>
              <li>
                <Social href={DEVELOPER_LINKS.x} label={t('home.footer.social.x')}>
                  <path d="M4 4l16 16M20 4L4 20" />
                </Social>
              </li>
            </ul>
            {status ? (
              <p
                data-testid="home-status-chip"
                data-state={status.overall}
                className="inline-flex items-center gap-2 rounded-full border border-glass-line bg-glass px-3 py-1.5 text-sm font-semibold text-surface"
              >
                <LiveDot
                  className={status.overall === 'overallOk' ? 'text-accent-light' : 'text-accent'}
                />
                {t(`home.status.${status.overall}`)}
              </p>
            ) : null}
          </div>

          <div className="grid grid-cols-2 gap-8 sm:grid-cols-4">
            <nav aria-labelledby="site-footer-product">
              <h3 id="site-footer-product" className={COLUMN_TITLE}>
                {t('home.footer.product')}
              </h3>
              <ul className="mt-3">
                <li>
                  <a href={`${home}#how`} className={COLUMN_LINK}>
                    {t('home.nav.how')}
                  </a>
                </li>
                <li>
                  <a href={`${home}#security`} className={COLUMN_LINK}>
                    {t('home.nav.security')}
                  </a>
                </li>
                <li>
                  <a href={`${home}#faq`} className={COLUMN_LINK}>
                    {t('home.nav.faq')}
                  </a>
                </li>
              </ul>
            </nav>

            <nav aria-labelledby="site-footer-resources">
              <h3 id="site-footer-resources" className={COLUMN_TITLE}>
                {t('home.footer.resources')}
              </h3>
              <ul className="mt-3">
                <li>
                  <Link href="/guide" className={COLUMN_LINK}>
                    {t('home.footer.guide')}
                  </Link>
                </li>
              </ul>
            </nav>

            <nav aria-labelledby="site-footer-account">
              <h3 id="site-footer-account" className={COLUMN_TITLE}>
                {t('home.footer.account')}
              </h3>
              <ul className="mt-3">
                <li>
                  <Link href="/login" className={COLUMN_LINK}>
                    {t('home.organiser.signIn')}
                  </Link>
                </li>
                <li>
                  <Link href="/register" className={COLUMN_LINK}>
                    {t('home.organiser.register')}
                  </Link>
                </li>
              </ul>
            </nav>

            <nav aria-labelledby="site-footer-about">
              <h3 id="site-footer-about" className={COLUMN_TITLE}>
                {t('home.footer.about')}
              </h3>
              <ul className="mt-3">
                <li>
                  <Link href="/developer" data-testid="footer-developer" className={COLUMN_LINK}>
                    {t('home.footer.developer')}
                  </Link>
                </li>
                <li>
                  <a href={`mailto:${t('home.footer.email')}`} className={COLUMN_LINK}>
                    {t('home.footer.email')}
                  </a>
                </li>
              </ul>
            </nav>
          </div>
        </div>

        {status ? (
          <section
            aria-labelledby="home-status-title"
            className="flex flex-col gap-3 border-t border-glass-line py-4 md:flex-row md:items-center md:justify-between"
          >
            <h2
              id="home-status-title"
              className="text-sm font-bold tracking-wide text-surface/60 uppercase"
            >
              {t('home.status.title')}
            </h2>
            <dl className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center sm:gap-5">
              {status.rows.map((row) => (
                <div
                  key={row.id}
                  data-testid={`status-${row.id}`}
                  data-state={row.state}
                  className="flex items-center justify-between gap-3"
                >
                  <dt className="text-base text-surface/60">{row.label}</dt>
                  <dd>
                    <Pill tone={PILL[row.state].tone}>{t(PILL[row.state].key)}</Pill>
                  </dd>
                </div>
              ))}
              <div className="flex items-center justify-between gap-3">
                <dt className="text-base text-surface/60">{t('home.status.time')}</dt>
                <dd className="text-base font-semibold text-surface">
                  {status.time ? (
                    <time data-testid="status-time" dateTime={status.time}>
                      {formatDateTime(status.time, locale)}
                    </time>
                  ) : (
                    <span>—</span>
                  )}
                </dd>
              </div>
            </dl>
          </section>
        ) : null}

        <div className="flex flex-col gap-2 border-t border-glass-line py-5 text-sm text-surface/50 md:flex-row md:items-center md:justify-between">
          <p>{t('home.footer.copy', { year: new Date().getFullYear() })}</p>
          <p>{t('home.footer.legal')}</p>
          <p>{t('home.footer.location')}</p>
        </div>
      </div>

      <p
        aria-hidden="true"
        className="site-footer-name pointer-events-none relative z-0 px-4 pb-2 text-center font-display font-extrabold whitespace-nowrap select-none"
      >
        {t('app.name')}
      </p>
    </footer>
  );
}
