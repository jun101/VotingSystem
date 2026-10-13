'use client';

import { useState } from 'react';
import { Button, Notice } from '@/components/ui';
import { useI18n } from '@/lib/i18n/client';

/**
 * The one-time password an owner just created or reset (school mode): shown once, with a copy button, until the owner says
 * it is noted. It lives only in this component's props; nothing stores it.
 */
export function IssuedPassword({
  name,
  password,
  onDismiss,
}: {
  name: string;
  password: string;
  onDismiss: () => void;
}) {
  const { t } = useI18n();
  const [copied, setCopied] = useState(false);

  async function copy() {
    try {
      await navigator.clipboard.writeText(password);
      setCopied(true);
    } catch {
      setCopied(false);
    }
  }

  return (
    <Notice
      tone="warm"
      role="status"
      title={t('institution.users.issued.title', { name })}
      data-testid="issued-password"
      actions={
        <div className="flex flex-wrap gap-3">
          <Button variant="secondary" onClick={copy} data-testid="issued-password-copy">
            {copied ? t('institution.users.issued.copied') : t('institution.users.issued.copy')}
          </Button>
          <Button variant="primary" onClick={onDismiss} data-testid="issued-password-dismiss">
            {t('institution.users.issued.dismiss')}
          </Button>
        </div>
      }
    >
      <p
        data-testid="issued-password-value"
        className="my-2 font-mono text-xl font-bold tracking-wider break-all text-ink"
      >
        {password}
      </p>
      <p>{t('institution.users.issued.text')}</p>
    </Notice>
  );
}
