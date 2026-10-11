'use client';

import { useRouter } from 'next/navigation';
import { useTransition } from 'react';
import { cx } from '@/components/ui/cx';
import { useI18n } from '@/lib/i18n/client';
import { rememberLocale, rememberVoteLocale, type Locale } from '@/lib/i18n/locale';

const FOCUS =
  'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-light';

/**
 * FR and EN on every voting screen. The choice is a session cookie of this election's pages only (a language, nothing about the voter), forgotten with the voting session;
 * the page is then rendered again by the server in that language. With `site`, it is the site-wide choice
 * (the `locale` cookie, a year) of the public home page. Choices already made stay: they
 * live in the page's state, not in the render.
 */
export function LanguageToggle({ site = false }: { site?: boolean }) {
  const { locale, t } = useI18n();
  const router = useRouter();
  const [pending, startTransition] = useTransition();

  function choose(next: Locale) {
    if (next === locale) return;

    if (site) rememberLocale(next);
    else rememberVoteLocale(next);
    startTransition(() => router.refresh());
  }

  const options: { locale: Locale; short: string; name: string; testId: string }[] = [
    {
      locale: 'fr',
      short: t('vote.language.frShort'),
      name: t('vote.language.fr'),
      testId: `${site ? 'home' : 'vote'}-language-fr`,
    },
    {
      locale: 'en',
      short: t('vote.language.enShort'),
      name: t('vote.language.en'),
      testId: `${site ? 'home' : 'vote'}-language-en`,
    },
  ];

  return (
    <div
      role="group"
      aria-label={t('vote.language.label')}
      aria-busy={pending ? 'true' : undefined}
      className="inline-flex shrink-0 rounded-full border border-glass-line bg-glass p-1"
    >
      {options.map((option) => (
        <button
          key={option.locale}
          type="button"
          lang={option.locale}
          data-testid={option.testId}
          aria-pressed={locale === option.locale}
          aria-label={option.name}
          onClick={() => choose(option.locale)}
          className={cx(
            'ui-control min-h-9 min-w-11 rounded-full px-3 text-base font-bold',
            FOCUS,
            locale === option.locale ? 'bg-surface text-deep' : 'text-surface hover:bg-glass-line',
          )}
        >
          {option.short}
        </button>
      ))}
    </div>
  );
}
