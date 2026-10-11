'use client';

import { Icon } from '@/components/admin/Icon';
import { PublicShell } from '@/components/public/PublicShell';
import { VoteCard } from '@/components/vote/VoteCard';
import { useI18n } from '@/lib/i18n/client';

/**
 * A public page whose data could not be read (the API's limit, an error, a timeout): "try again",
 * never the "no public results" page, which is only for what does not exist. The error itself is
 * not shown (nothing of it reaches the visitor); the request is not kept by any cache.
 */
export default function PublicError({ reset }: { error: Error; reset: () => void }) {
  const { t } = useI18n();

  return (
    <PublicShell
      testId="public-error"
      productName={t('app.name')}
      kicker={t('public.error.kicker')}
      kickerIcon="warn"
      title={t('public.error.title')}
      skip={t('public.skip')}
    >
      <meta name="robots" content="noindex" />
      <VoteCard tone="activity" icon="warn" title={t('public.error.title')} headingLevel={2}>
        <p role="alert" className="text-md text-ink-2">
          {t('public.error.text')}
        </p>
        <button
          type="button"
          onClick={reset}
          className="ui-control inline-flex min-h-12 w-fit items-center justify-center gap-2 rounded-full border border-primary bg-primary px-6 text-md font-semibold text-surface shadow-button hover:border-primary-hover hover:bg-primary-hover focus-visible:ring-4 focus-visible:ring-primary-soft focus-visible:outline-2 focus-visible:outline-primary"
        >
          <Icon name="warn" size={18} />
          {t('public.error.retry')}
        </button>
      </VoteCard>
    </PublicShell>
  );
}
