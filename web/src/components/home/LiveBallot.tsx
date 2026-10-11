'use client';

import { useEffect, useState } from 'react';
import { Icon } from '@/components/admin/Icon';
import { cx } from '@/components/ui/cx';
import { useI18n } from '@/lib/i18n/client';

const PICKS = [
  { key: 'one', tint: 'from-primary-line to-primary' },
  { key: 'two', tint: 'from-warm-soft to-accent' },
  { key: 'blank', tint: 'from-teal-soft to-teal' },
] as const;

type PickKey = (typeof PICKS)[number]['key'];

/** How long the ballot stays in the box before the demo goes back to its start. */
const RESET_AFTER_MS = 3500;

/**
 * The hero's one live piece: touch a candidate and the ballot drops into the box. A trial only:
 * nothing is sent anywhere and no election is touched.
 */
export function LiveBallot() {
  const { t } = useI18n();
  const [choice, setChoice] = useState<PickKey | null>(null);

  useEffect(() => {
    if (!choice) return;

    const timer = setTimeout(() => setChoice(null), RESET_AFTER_MS);

    return () => clearTimeout(timer);
  }, [choice]);

  const name = choice ? t(`home.art.${choice}`) : '';

  return (
    <div
      role="group"
      aria-label={t('home.art.label')}
      className="rounded-xl border border-surface bg-linear-to-b from-surface to-primary-soft p-5 shadow-3 sm:p-6"
    >
      <div className="bg-panel rounded-lg px-5 pt-5 pb-4 text-center text-surface">
        <p className="text-sm font-bold tracking-wide uppercase">{t('home.art.label')}</p>
        <h2 className="mt-1 mb-3.5 text-xl text-surface">{t('home.art.ballot')}</h2>
        <p
          data-testid="home-ballot-slot"
          data-state={choice ? 'done' : 'idle'}
          className={cx(
            'flex h-14 items-center justify-center gap-2 rounded-md border-2 border-accent-light text-md font-semibold',
            choice ? 'border-solid bg-glass-line' : 'border-dashed bg-glass',
          )}
        >
          <Icon name={choice ? 'check' : 'down'} size={18} />
          {choice ? t('home.art.slotDone') : t('home.art.slot')}
        </p>
        <p aria-live="polite" className="mt-3 min-h-5 text-base font-semibold text-surface">
          {choice ? t('home.art.voted', { name }) : t('home.art.idle')}
        </p>
      </div>

      <ul className="mt-3.5 flex flex-col gap-2.5">
        {PICKS.map((pick) => (
          <li key={pick.key}>
            <button
              type="button"
              data-testid={`home-ballot-${pick.key}`}
              aria-pressed={choice === pick.key}
              onClick={() => setChoice(pick.key)}
              className={cx(
                'ui-control lift-sm flex w-full items-center gap-3 rounded-md border-2 bg-surface px-3.5 py-2.5 text-left shadow-1 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary',
                choice === pick.key
                  ? 'border-primary'
                  : 'border-transparent hover:border-primary-line',
              )}
            >
              <span
                aria-hidden="true"
                className={cx('size-10 shrink-0 rounded-sm bg-linear-to-br', pick.tint)}
              />
              <span className="min-w-0 flex-1">
                <span className="block truncate text-md font-bold text-ink">
                  {t(`home.art.${pick.key}`)}
                </span>
                <span className="block truncate text-base text-ink-soft">
                  {t(`home.art.${pick.key}Role`)}
                </span>
              </span>
              <span className="shrink-0 text-base font-bold text-primary-hover">
                {t('home.art.vote')}
              </span>
            </button>
          </li>
        ))}
      </ul>
    </div>
  );
}
