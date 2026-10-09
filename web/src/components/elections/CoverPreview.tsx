'use client';

import type { ReactNode } from 'react';
import { useI18n } from '@/lib/i18n/client';

/** The cover as it will be: the picture picked, or the stored one; the field's buttons are in the form. */
export function CoverPreview({ url, children }: { url: string | null; children?: ReactNode }) {
  const { t } = useI18n();

  return (
    <section
      aria-labelledby="election-cover-title"
      className="flex flex-col gap-3 rounded-lg border border-line bg-surface p-4"
    >
      <h2 id="election-cover-title" className="text-lg font-bold text-ink">
        {t('elections.cover.title')}
      </h2>
      {url ? (
        // A local or already optimised picture: a plain image with its ratio set.
        // eslint-disable-next-line @next/next/no-img-element
        <img
          src={url}
          alt={t('elections.cover.previewAlt')}
          width={480}
          height={240}
          data-testid="election-cover-preview"
          className="aspect-2/1 w-full rounded border border-line bg-canvas object-cover"
        />
      ) : (
        <p className="rounded border border-dashed border-line-strong p-3 text-sm text-ink-soft">
          {t('elections.cover.none')}
        </p>
      )}
      {children}
    </section>
  );
}
