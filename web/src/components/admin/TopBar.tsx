'use client';

import { usePathname } from 'next/navigation';
import type { RefObject } from 'react';
import { Pill } from '@/components/ui';
import { useI18n } from '@/lib/i18n/client';
import { useAdminUser } from './AdminUser';
import { focusRing } from './classes';
import { Icon } from './Icon';
import { titleKeyOf } from './menu';
import { UserMenu } from './UserMenu';
import { VerifyBanner } from './VerifyBanner';

const BARS = 'M4 7h16M4 12h16M4 17h16';

type TopBarProps = {
  open: boolean;
  menuButtonRef: RefObject<HTMLButtonElement | null>;
  onMenu: () => void;
};

/**
 * The top bar: the menu button (below `lg`), the title of the page, the verification state and
 * the person. No band under it: the verification reminder, while there is one, belongs to the
 * bar. The bar stays in view while the page scrolls, except with that reminder, which is tall.
 */
export function TopBar({ open, menuButtonRef, onMenu }: TopBarProps) {
  const { t } = useI18n();
  const user = useAdminUser();
  const pathname = usePathname();

  return (
    <header
      data-testid="top-bar"
      className={`z-20 flex flex-col gap-3 px-4 pt-4 md:px-7 ${
        user.email_verified ? 'sticky top-0 bg-canvas pb-1' : ''
      }`}
    >
      <div className="flex items-center gap-3">
        <button
          ref={menuButtonRef}
          type="button"
          data-testid="menu-button"
          aria-label={t('admin.shell.menuOpen')}
          aria-expanded={open}
          aria-controls="menu-drawer"
          onClick={onMenu}
          className={`ui-control lift-sm flex size-11 shrink-0 items-center justify-center rounded-full bg-surface text-ink shadow-1 hover:bg-primary-soft lg:hidden ${focusRing}`}
        >
          <Icon path={BARS} size={22} />
        </button>

        <h1
          data-testid="top-bar-title"
          className="min-w-0 flex-1 truncate font-display text-2xl font-extrabold tracking-tight text-ink md:text-3xl"
        >
          {t(titleKeyOf(pathname))}
        </h1>

        <span className="hidden shrink-0 md:inline-flex">
          <Pill tone={user.email_verified ? 'teal' : 'warm'}>
            {t(
              user.email_verified ? 'admin.verification.verified' : 'admin.verification.unverified',
            )}
          </Pill>
        </span>

        <UserMenu />
      </div>

      <VerifyBanner />
    </header>
  );
}
