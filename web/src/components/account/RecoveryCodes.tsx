'use client';

import { useEffect, useRef, useState } from 'react';
import { Button, Notice } from '@/components/ui';
import { useI18n } from '@/lib/i18n/client';
import { RECOVERY_FILE_NAME, recoveryFileText, saveTextFile } from './recoveryFile';

/**
 * The recovery codes, shown once: the list (the focus moves to it), copy, download as a text
 * file, and the box that says they are kept, which is what lets the person finish. Leaving the
 * page before that is warned about by the browser, and the warning is written here as well.
 */
export function RecoveryCodes({ codes, onDone }: { codes: string[]; onDone: () => void }) {
  const { t } = useI18n();
  const list = useRef<HTMLUListElement>(null);
  const [saved, setSaved] = useState(false);
  const [copy, setCopy] = useState<'copied' | 'failed' | null>(null);

  useEffect(() => list.current?.focus(), []);

  // Closing or reloading the page loses the codes: the browser asks first.
  useEffect(() => {
    function warn(event: BeforeUnloadEvent) {
      event.preventDefault();
    }

    window.addEventListener('beforeunload', warn);

    return () => window.removeEventListener('beforeunload', warn);
  }, []);

  async function copyAll() {
    try {
      await navigator.clipboard.writeText(codes.join('\n'));
      setCopy('copied');
    } catch {
      setCopy('failed');
    }
  }

  return (
    <section aria-labelledby="recovery-title" className="flex flex-col gap-4">
      <h3 id="recovery-title" className="text-md font-bold text-ink">
        {t('account.codes.title')}
      </h3>
      <p className="text-base text-ink-soft">{t('account.codes.lede')}</p>
      <Notice tone="warm">{t('account.codes.warning')}</Notice>

      <ul
        ref={list}
        tabIndex={-1}
        aria-label={t('account.codes.list')}
        data-testid="recovery-codes"
        className="grid grid-cols-1 gap-2 rounded-md border border-line bg-surface-alt p-3 outline-none focus-visible:ring-4 focus-visible:ring-primary-soft sm:grid-cols-2"
      >
        {codes.map((code, index) => (
          <li
            key={code}
            data-testid={`recovery-code-${index + 1}`}
            className="rounded border border-line bg-surface px-3 py-2 font-mono text-md tracking-wider text-ink"
          >
            {code}
          </li>
        ))}
      </ul>

      <div className="flex flex-wrap gap-3">
        <Button variant="secondary" onClick={() => void copyAll()} data-testid="recovery-copy">
          {t('account.codes.copy')}
        </Button>
        <Button
          variant="secondary"
          onClick={() => saveTextFile(RECOVERY_FILE_NAME, recoveryFileText(codes))}
          data-testid="recovery-download"
        >
          {t('account.codes.download')}
        </Button>
      </div>
      <p role="status" className="min-h-5 text-sm text-ink-soft">
        {copy ? t(copy === 'copied' ? 'account.codes.copied' : 'account.codes.copyFailed') : ''}
      </p>

      <label className="flex min-h-11 items-center gap-3 text-base font-semibold text-ink">
        <input
          type="checkbox"
          checked={saved}
          onChange={(event) => setSaved(event.target.checked)}
          data-testid="recovery-saved"
          className="size-5 accent-primary"
        />
        {t('account.codes.saved')}
      </label>

      <div>
        <Button variant="primary" disabled={!saved} onClick={onDone} data-testid="recovery-done">
          {t('account.codes.done')}
        </Button>
      </div>
    </section>
  );
}
