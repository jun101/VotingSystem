'use client';

import { useRef, useState, type ChangeEvent } from 'react';
import { Button } from '@/components/ui';
import { initials } from '@/components/admin/menu';
import { removeLogo, uploadLogo } from '@/lib/api/browser';
import { ApiError, errorText, fieldText } from '@/lib/api/errors';
import type { InstitutionProfile } from '@/lib/api/user';
import { useI18n } from '@/lib/i18n/client';

const MAX_BYTES = 5 * 1024 * 1024;

type Logo = InstitutionProfile['logo'];

type LogoFieldProps = {
  name: string;
  logo: Logo;
  /** Only an owner changes the logo; a manager only sees it. */
  canEdit: boolean;
  /** Called with the new logo (or `null`) once the API has answered. */
  onChange: (logo: Logo) => void;
};

/**
 * The logo of the institution, or its initials, with "Change" and "Remove" for an owner. A
 * picture is sent the moment it is chosen; a refusal is told here and the previous logo stays.
 */
export function LogoField({ name, logo, canEdit, onChange }: LogoFieldProps) {
  const { t, tIfAny } = useI18n();
  const input = useRef<HTMLInputElement>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  function problem(caught: unknown): string {
    const failure = caught instanceof ApiError ? caught : new ApiError(0, 'unknown');
    const dimensions = failure.fields.file?.[0];

    return dimensions ? fieldText('logo.file', dimensions, tIfAny) : errorText(failure, tIfAny);
  }

  async function choose(event: ChangeEvent<HTMLInputElement>) {
    const file = event.target.files?.[0];

    event.target.value = '';

    if (!file) return;

    setError(null);

    // The API refuses it too; saying so here saves the upload.
    if (file.size > MAX_BYTES) {
      setError(errorText(new ApiError(413, 'file_too_large'), tIfAny));

      return;
    }

    setBusy(true);

    try {
      onChange((await uploadLogo(file)).logo);
    } catch (caught) {
      setError(problem(caught));
    } finally {
      setBusy(false);
    }
  }

  async function remove() {
    setError(null);
    setBusy(true);

    try {
      await removeLogo();
      onChange(null);
    } catch (caught) {
      setError(problem(caught));
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="flex flex-wrap items-center gap-4">
      {logo ? (
        // The files are already optimised (160 px): a plain image, with its size set.
        // eslint-disable-next-line @next/next/no-img-element
        <img
          src={logo.md}
          alt={t('institution.profile.logo.alt')}
          width={96}
          height={96}
          data-testid="logo-preview"
          className="size-24 shrink-0 rounded-md border border-line bg-surface object-contain"
        />
      ) : (
        <div
          aria-hidden="true"
          data-testid="logo-initials"
          className="bg-hero flex size-24 shrink-0 items-center justify-center rounded-md font-display text-2xl font-extrabold text-surface"
        >
          {initials(name)}
        </div>
      )}

      <div className="flex min-w-0 flex-1 basis-48 flex-col items-start gap-2">
        <p className="text-base font-semibold text-ink">{t('institution.profile.logo.title')}</p>
        {canEdit ? (
          <>
            <input
              ref={input}
              type="file"
              accept="image/jpeg,image/png,image/webp"
              hidden
              tabIndex={-1}
              onChange={choose}
              data-testid="logo-input"
            />
            <div className="flex flex-wrap gap-2">
              <Button
                variant="secondary"
                loading={busy}
                onClick={() => input.current?.click()}
                data-testid="logo-change"
              >
                {t(logo ? 'institution.profile.logo.change' : 'institution.profile.logo.add')}
              </Button>
              {logo ? (
                <Button variant="quiet" disabled={busy} onClick={remove} data-testid="logo-remove">
                  {t('institution.profile.logo.remove')}
                </Button>
              ) : null}
            </div>
            <p className="text-sm text-ink-soft">{t('institution.profile.logo.help')}</p>
          </>
        ) : null}
        <p
          role="alert"
          data-testid={error ? 'logo-error' : undefined}
          className="text-sm font-medium text-danger"
        >
          {error}
        </p>
      </div>
    </div>
  );
}
