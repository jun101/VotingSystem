'use client';

import { useCallback, useEffect, useRef, useState, type ChangeEvent, type RefObject } from 'react';
import { Button } from '@/components/ui';
import type { Election } from '@/lib/api/elections';
import { ApiError, errorText } from '@/lib/api/errors';
import { useI18n } from '@/lib/i18n/client';

const MAX_BYTES = 5 * 1024 * 1024;

/**
 * The picture chosen in the form and not yet sent: the file, and an address to show it with. The
 * address is made when the file is picked and given back when it is replaced, removed or the form
 * goes away.
 */
export function usePickedCover() {
  const [picked, setPicked] = useState<{ file: File; url: string } | null>(null);
  const current = useRef<string | null>(null);

  const forget = useCallback(() => {
    if (current.current) URL.revokeObjectURL(current.current);

    current.current = null;
    setPicked(null);
  }, []);

  const pick = useCallback((file: File) => {
    if (current.current) URL.revokeObjectURL(current.current);

    current.current = URL.createObjectURL(file);
    setPicked({ file, url: current.current });
  }, []);

  useEffect(
    () => () => {
      if (current.current) URL.revokeObjectURL(current.current);
    },
    [],
  );

  return { picked, pick, forget };
}

type CoverFieldProps = {
  /** The cover the election has now, if it is not being removed. */
  stored: Election['cover'];
  /** A picture chosen and not sent yet. */
  picked: { file: File; url: string } | null;
  onPick: (file: File) => void;
  /** Drops the picked picture, or marks the stored one for removal. */
  onRemove: () => void;
  /** Why the picture was refused, told under the buttons. */
  error: string | null;
  errorRef: RefObject<HTMLParagraphElement | null>;
  /** The error of a file that is too heavy, found before sending. */
  onTooLarge: (message: string) => void;
};

/** The buttons of the cover: choose or change, remove. The picture itself is shown by the side panel. */
export function CoverField({
  stored,
  picked,
  onPick,
  onRemove,
  error,
  errorRef,
  onTooLarge,
}: CoverFieldProps) {
  const { t, tIfAny } = useI18n();
  const input = useRef<HTMLInputElement>(null);
  const has = Boolean(picked || stored);

  function choose(event: ChangeEvent<HTMLInputElement>) {
    const file = event.target.files?.[0];

    event.target.value = '';

    if (!file) return;

    // The API refuses it too; saying so here saves the upload.
    if (file.size > MAX_BYTES) {
      onTooLarge(errorText(new ApiError(413, 'file_too_large'), tIfAny));

      return;
    }

    onPick(file);
  }

  return (
    <div className="flex flex-col items-start gap-2">
      <input
        ref={input}
        type="file"
        accept="image/jpeg,image/png,image/webp"
        hidden
        tabIndex={-1}
        onChange={choose}
        data-testid="election-cover-input"
      />
      <div className="flex flex-wrap gap-2">
        <Button
          variant="secondary"
          onClick={() => input.current?.click()}
          data-testid="election-cover-change"
        >
          {t(has ? 'elections.cover.change' : 'elections.cover.add')}
        </Button>
        {has ? (
          <Button variant="quiet" onClick={onRemove} data-testid="election-cover-remove">
            {t('elections.cover.remove')}
          </Button>
        ) : null}
      </div>
      <p className="text-sm text-ink-soft">{t('elections.cover.help')}</p>
      <p
        ref={errorRef}
        role="alert"
        tabIndex={-1}
        data-testid={error ? 'election-cover-error' : undefined}
        className="text-sm font-medium text-danger outline-none"
      >
        {error}
      </p>
    </div>
  );
}
