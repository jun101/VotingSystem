import Link from 'next/link';
import { Icon } from '@/components/admin/Icon';
import { VoteCard } from '@/components/vote/VoteCard';
import type { Translate } from '@/lib/i18n/messages';
import { PublicShell } from './PublicShell';

/**
 * The page for an election or an institution that shows nothing: unknown, not published,
 * archived, suspended. The same words for all (HTTP 404), so it never says which one it is.
 */
export function PublicUnavailable({ t }: { t: Translate }) {
  return (
    <PublicShell
      testId="public-unavailable"
      productName={t('app.name')}
      kicker={t('public.unavailable.kicker')}
      kickerIcon="lock"
      title={t('public.unavailable.title')}
      skip={t('public.skip')}
    >
      {/* React puts these in the head: a not-found page does not always get its metadata. */}
      <title>{t('public.meta.unavailable')}</title>
      <meta name="robots" content="noindex" />
      <VoteCard tone="activity" icon="warn" title={t('public.unavailable.title')} headingLevel={2}>
        <p className="text-md text-ink-2">{t('public.unavailable.text')}</p>
        <p className="flex items-center gap-2 text-base font-medium text-teal-ink">
          <Icon name="lock" size={18} />
          {t('public.unavailable.secure')}
        </p>
        <Link
          href="/"
          className="ui-control inline-flex min-h-12 w-fit items-center justify-center rounded-full border border-primary bg-primary px-6 text-md font-semibold text-surface shadow-button hover:border-primary-hover hover:bg-primary-hover focus-visible:ring-4 focus-visible:ring-primary-soft focus-visible:outline-2 focus-visible:outline-primary"
        >
          {t('public.unavailable.home')}
        </Link>
      </VoteCard>
    </PublicShell>
  );
}
