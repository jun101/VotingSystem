'use client';

import { useState } from 'react';
import { Icon } from '@/components/admin/Icon';
import { cx } from '@/components/ui/cx';

/**
 * Copies the address of the page. The only client part of the share block besides the bars: with
 * JavaScript off, or a browser that refuses, the address is written on the page to select, and
 * the button says so instead of failing.
 */
export function CopyLinkButton({
  url,
  label,
  copied,
  failed,
  className,
}: {
  url: string;
  label: string;
  copied: string;
  failed: string;
  className?: string;
}) {
  const [state, setState] = useState<'idle' | 'copied' | 'failed'>('idle');

  async function copy() {
    try {
      await navigator.clipboard.writeText(url);
      setState('copied');
    } catch {
      setState('failed');
    }
  }

  return (
    <>
      <button
        type="button"
        data-testid="public-share-copy"
        onClick={() => void copy()}
        className={cx(className)}
      >
        <Icon name={state === 'copied' ? 'check' : 'copy'} size={18} />
        {state === 'copied' ? copied : label}
      </button>
      <span role="status" className="sr-only">
        {state === 'copied' ? copied : state === 'failed' ? failed : ''}
      </span>
      {state === 'failed' ? (
        <span
          data-testid="public-share-copy-failed"
          className="w-full text-sm font-medium text-danger"
        >
          {failed}
        </span>
      ) : null}
    </>
  );
}
