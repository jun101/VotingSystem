'use client';

import { useSyncExternalStore } from 'react';
import { Notice } from '@/components/ui';
import { useI18n } from '@/lib/i18n/client';
import { useDeployment } from './Deployment';

/**
 * School mode is served over plain HTTP on the school's network: the admin is told, on every page, that passwords and
 * codes are not encrypted. Shown only when the page really is on HTTP.
 */
export function InsecureNotice() {
  const { t } = useI18n();
  const { school } = useDeployment();
  // The protocol of the page, read in the browser only (the server renders as if it were HTTPS: nothing shown).
  const protocol = useSyncExternalStore(
    () => () => {},
    () => window.location.protocol,
    () => 'https:',
  );

  if (!school || protocol !== 'http:') return null;

  return (
    <Notice tone="warm" role="note" data-testid="insecure-notice">
      {t('admin.insecure')}
    </Notice>
  );
}
