'use client';

import { useState, type ComponentProps } from 'react';
import { Input } from '@/components/ui';
import { useI18n } from '@/lib/i18n/client';

type PasswordFieldProps = Omit<ComponentProps<typeof Input>, 'type' | 'trailing'> & {
  /** `data-testid` of the show/hide button. */
  toggleTestId?: string;
};

/** A password field with a visible "show" button (the person can check what they typed). */
export function PasswordField({ toggleTestId, ...rest }: PasswordFieldProps) {
  const { t } = useI18n();
  const [visible, setVisible] = useState(false);

  return (
    <Input
      {...rest}
      type={visible ? 'text' : 'password'}
      trailing={
        <button
          type="button"
          data-testid={toggleTestId}
          aria-label={t(visible ? 'auth.password.hide' : 'auth.password.show')}
          aria-pressed={visible}
          onClick={() => setVisible((shown) => !shown)}
          className="ui-control flex size-11 items-center justify-center rounded text-ink-soft hover:text-primary focus-visible:ring-4 focus-visible:ring-primary-soft focus-visible:outline-2 focus-visible:outline-primary"
        >
          <svg
            aria-hidden="true"
            viewBox="0 0 24 24"
            className="size-5"
            fill="none"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
          >
            <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z" />
            <circle cx="12" cy="12" r="3" />
            {visible ? <path d="M4 4l16 16" /> : null}
          </svg>
        </button>
      }
    />
  );
}
