'use client';

import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui';
import { logout } from '@/lib/api/browser';
import { ApiError, errorText } from '@/lib/api/errors';
import { useI18n } from '@/lib/i18n/client';
import { useAdminUser } from './AdminUser';
import { focusRing, linkSecondary } from './classes';
import { ACCOUNT_PATH, initials } from './menu';
import { ROLE_KEYS } from './SideMenu';

/**
 * The person's button in the top bar. It opens a small panel with their name, their role and
 * the way out. Escape closes it and gives the focus back to the button; so does a tap
 * outside.
 */
export function UserMenu() {
  const user = useAdminUser();
  const { t, tIfAny } = useI18n();
  const router = useRouter();
  const [open, setOpen] = useState(false);
  const [signingOut, setSigningOut] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);
  const root = useRef<HTMLDivElement>(null);
  const button = useRef<HTMLButtonElement>(null);

  useEffect(() => {
    if (!open) return;

    function onKey(event: KeyboardEvent) {
      if (event.key === 'Escape') {
        setOpen(false);
        button.current?.focus();
      }
    }

    function onPointer(event: PointerEvent) {
      if (root.current && !root.current.contains(event.target as Node)) setOpen(false);
    }

    document.addEventListener('keydown', onKey);
    document.addEventListener('pointerdown', onPointer);

    return () => {
      document.removeEventListener('keydown', onKey);
      document.removeEventListener('pointerdown', onPointer);
    };
  }, [open]);

  async function signOut() {
    setSigningOut(true);
    setProblem(null);

    try {
      await logout();
    } catch (error) {
      // Already signed out elsewhere: the way out is the same.
      if (!(error instanceof ApiError && error.code === 'unauthenticated')) {
        setProblem(
          errorText(error instanceof ApiError ? error : new ApiError(0, 'unknown'), tIfAny),
        );
        setSigningOut(false);

        return;
      }
    }

    router.push('/login');
    router.refresh();
  }

  return (
    <div ref={root} className="relative">
      <button
        ref={button}
        type="button"
        data-testid="user-menu"
        aria-expanded={open}
        aria-controls="user-menu-panel"
        aria-label={t('admin.user.menu', { name: user.name })}
        onClick={() => setOpen((value) => !value)}
        className={`ui-control lift-sm bg-hero flex size-11 items-center justify-center rounded-full font-display text-sm font-extrabold text-surface shadow-1 ${focusRing}`}
      >
        <span aria-hidden="true">{initials(user.name)}</span>
      </button>

      {open ? (
        <div
          id="user-menu-panel"
          className="absolute end-0 top-full z-30 mt-2 flex w-64 flex-col gap-3 rounded-lg border border-line bg-surface p-4 shadow-3"
        >
          <div className="flex flex-col">
            <span className="truncate font-semibold text-ink">{user.name}</span>
            <span className="text-sm text-ink-soft">{t(ROLE_KEYS[user.role])}</span>
          </div>
          {problem ? (
            <p role="alert" className="text-sm text-danger">
              {problem}
            </p>
          ) : null}
          <Link
            href={ACCOUNT_PATH}
            data-testid="user-menu-account"
            onClick={() => setOpen(false)}
            className={linkSecondary}
          >
            {t('admin.user.account')}
          </Link>
          <Button
            variant="secondary"
            loading={signingOut}
            onClick={signOut}
            data-testid="signout-button"
            data-leaves-page=""
          >
            {t('admin.user.signOut')}
          </Button>
        </div>
      ) : null}
    </div>
  );
}
