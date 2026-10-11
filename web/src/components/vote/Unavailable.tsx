'use client';

import Link from 'next/link';
import { Icon, type IconName } from '@/components/admin/Icon';
import { Float } from '@/components/motion';
import { LogoMark } from '@/components/ui/LogoMark';
import { useI18n } from '@/lib/i18n/client';
import { LanguageToggle } from './LanguageToggle';
import { VoteCard } from './VoteCard';

/**
 * The same page for a draft election and for an address that names none: nothing tells them
 * apart. It is the voter's own language (the visitor's, since no election is known).
 */
export function Unavailable({ productName, toggle }: { productName: string; toggle: boolean }) {
  const { t } = useI18n();
  const options: { icon: IconName; title: string; text: string }[] = [
    { icon: 'eye', title: t('vote.unavailable.check'), text: t('vote.unavailable.checkText') },
    { icon: 'mail', title: t('vote.unavailable.ask'), text: t('vote.unavailable.askText') },
    { icon: 'school', title: t('vote.unavailable.home'), text: t('vote.unavailable.homeText') },
  ];

  return (
    <div data-testid="vote-unavailable" className="flex min-h-screen flex-col bg-canvas">
      {/* React puts these in the head: a not-found page does not always get the title of its metadata. */}
      <title>{t('vote.unavailable.title')}</title>
      <meta name="referrer" content="no-referrer" />
      <header data-live="true" className="bg-hero relative overflow-hidden text-surface">
        <div aria-hidden="true" className="hero-light hero-light-a -top-24 -right-16 size-96" />
        <div aria-hidden="true" className="hero-light hero-light-b -bottom-32 left-1/4 size-80" />
        <div className="relative mx-auto w-full max-w-6xl px-4 pt-4 pb-16 md:pt-5 md:pb-20">
          <div className="flex items-center justify-between gap-3">
            <p className="flex min-w-0 items-center gap-2.5 font-display text-lg font-extrabold">
              <LogoMark size={32} />
              <span className="truncate">{productName}</span>
            </p>
            {toggle ? <LanguageToggle /> : null}
          </div>
          <div className="mt-5 flex flex-col gap-2 md:mt-6">
            <p className="flex items-center gap-2 text-sm font-semibold tracking-wide text-hero-ink-soft uppercase">
              <Icon name="ballot" size={18} />
              {t('vote.unavailable.kicker')}
            </p>
            <h1 className="text-3xl text-surface md:text-4xl">{t('vote.unavailable.title')}</h1>
          </div>
        </div>
      </header>
      <main className="relative z-10 mx-auto -mt-10 w-full max-w-6xl flex-1 px-4 pb-10 md:-mt-12">
        <div className="grid items-start gap-4 lg:grid-cols-[minmax(0,1.25fr)_minmax(0,1fr)]">
          <VoteCard
            tone="activity"
            icon="warn"
            title={t('vote.unavailable.title')}
            headingLevel={2}
          >
            <div className="flex flex-col items-center gap-5 sm:flex-row">
              <div aria-hidden="true" className="relative mx-auto size-32 shrink-0 sm:mx-0">
                <span className="absolute -inset-2 rounded-full border border-primary-line bg-primary-soft" />
                <span className="float-art bg-hero absolute inset-2 flex items-center justify-center rounded-full text-surface shadow-3">
                  <Icon name="ballot" size={60} />
                </span>
                <Float tilt="b" className="absolute -right-1 -bottom-1">
                  <span className="flex size-12 items-center justify-center rounded-full border-2 border-surface bg-accent text-deep shadow-2">
                    <Icon name="warn" size={24} />
                  </span>
                </Float>
              </div>
              <div className="flex min-w-0 flex-col gap-2 text-center sm:text-left">
                <p className="text-md text-ink-2">{t('vote.unavailable.text')}</p>
                <p className="flex items-center justify-center gap-2 text-base font-medium text-teal-ink sm:justify-start">
                  <Icon name="lock" size={18} />
                  {t('vote.unavailable.secure')}
                </p>
              </div>
            </div>
            <Link
              href="/"
              className="ui-control inline-flex min-h-12 items-center justify-center rounded-full border border-primary bg-primary px-6 text-md font-semibold text-surface shadow-button hover:border-primary-hover hover:bg-primary-hover focus-visible:ring-4 focus-visible:ring-primary-soft focus-visible:outline-2 focus-visible:outline-primary"
            >
              {t('vote.unavailable.home')}
            </Link>
          </VoteCard>
          <VoteCard tone="latest" icon="list" title={t('vote.unavailable.whatTitle')}>
            <ul className="flex flex-col gap-3">
              {options.map((option) => (
                <li key={option.icon} className="flex items-start gap-3">
                  <span
                    aria-hidden="true"
                    className="flex size-10 shrink-0 items-center justify-center rounded-full bg-primary-soft text-primary-hover"
                  >
                    <Icon name={option.icon} size={20} />
                  </span>
                  <span className="flex min-w-0 flex-col">
                    <span className="font-semibold text-ink">{option.title}</span>
                    <span className="text-base text-ink-soft">{option.text}</span>
                  </span>
                </li>
              ))}
            </ul>
          </VoteCard>
        </div>
      </main>
    </div>
  );
}
