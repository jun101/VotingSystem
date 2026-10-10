'use client';

import type { ReactNode } from 'react';
import { Icon } from '@/components/admin/Icon';
import { useI18n } from '@/lib/i18n/client';
import { Panel } from './Panel';

/** The cover as it will be: the picture picked, or the stored one; the field's buttons are in the form. */
export function CoverPreview({ url, children }: { url: string | null; children?: ReactNode }) {
  const { t } = useI18n();

  return (
    <Panel
      tone="cover"
      icon="image"
      title={t('elections.form.sections.cover')}
      id="election-cover-title"
      headerTestId="election-section-header-cover"
    >
      {url ? (
        // A local or already optimised picture: a plain image with its ratio set.
        // eslint-disable-next-line @next/next/no-img-element
        <img
          src={url}
          alt={t('elections.cover.previewAlt')}
          width={480}
          height={240}
          data-testid="election-cover-preview"
          className="aspect-2/1 w-full rounded-md border border-line bg-canvas object-cover"
        />
      ) : (
        <div className="bg-cover-empty cover-sweep flex aspect-2/1 w-full items-center justify-center rounded-md text-surface">
          <span aria-hidden="true" className="float-art relative z-10">
            <Icon name="image" size={48} />
          </span>
        </div>
      )}
      {url ? null : <p className="text-sm text-ink-soft">{t('elections.cover.none')}</p>}
      {children}
    </Panel>
  );
}
