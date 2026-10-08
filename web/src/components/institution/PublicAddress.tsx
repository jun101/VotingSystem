'use client';

import { useState, useSyncExternalStore } from 'react';
import { Button } from '@/components/ui';
import { useI18n } from '@/lib/i18n/client';

const noSubscription = () => () => undefined;

/**
 * The institution's public address, `{site}/institutions/{id}`, with a button that copies it.
 * The page behind it comes in slice 16, and the line says so. The site is the one the visitor is
 * on, read in the browser (empty while the page is made on the server).
 */
export function PublicAddress({ id }: { id: string }) {
  const { t } = useI18n();
  const origin = useSyncExternalStore(
    noSubscription,
    () => window.location.origin,
    () => '',
  );
  const [copied, setCopied] = useState(false);
  const address = `${origin}/institutions/${id}`;

  async function copy() {
    try {
      await navigator.clipboard.writeText(address);
      setCopied(true);
    } catch {
      // No clipboard (an insecure page, a refusal): the address stays on screen to select.
      setCopied(false);
    }
  }

  return (
    <div
      data-testid="public-address"
      className="flex flex-wrap items-center gap-3 rounded-lg border border-line bg-surface p-5"
    >
      <div className="flex min-w-0 flex-1 basis-60 flex-col gap-1">
        <p className="text-base font-semibold text-ink">{t('institution.publicAddress.label')}</p>
        <p className="text-base break-all text-primary">{address}</p>
        <p className="text-sm text-ink-soft">{t('institution.publicAddress.soon')}</p>
      </div>
      <div className="flex flex-col items-start gap-1">
        <Button variant="secondary" onClick={copy} data-testid="public-address-copy">
          {t('institution.publicAddress.copy')}
        </Button>
        <p role="status" className="text-sm font-medium text-teal-ink">
          {copied ? t('institution.publicAddress.copied') : null}
        </p>
      </div>
    </div>
  );
}
