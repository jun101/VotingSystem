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

const BARS = 'M4 7h16M4 12h16M4 17h16';

type TopBarProps = {
  open: boolean;
  menuButtonRef: RefObject<HTMLButtonElement | null>;
  onMenu: () => void;
};

/** The top bar: the menu button (below `lg`), the title of the page, the verification state, the user. */
export function TopBar({ open, menuButtonRef, onMenu }: TopBarProps) {
  const { t } = useI18n();
  const user = useAdminUser();
  const pathname = usePathname();

  return (
    <header
      data-testid="top-bar"
      className="sticky top-0 z-20 flex items-center gap-2 border-b border-line bg-surface px-3 py-2 md:px-6"
    >
      <button
        ref={menuButtonRef}
        type="button"
        data-testid="menu-button"
        aria-label={t('admin.shell.menuOpen')}
        aria-expanded={open}
        aria-controls="menu-drawer"
        onClick={onMenu}
        className={`ui-control flex size-11 shrink-0 items-center justify-center rounded text-ink hover:bg-primary-soft lg:hidden ${focusRing}`}
      >
        <Icon path={BARS} size={22} />
      </button>

      <h1
        data-testid="top-bar-title"
        className="min-w-0 flex-1 truncate text-xl text-ink md:text-2xl"
      >
        {t(titleKeyOf(pathname))}
      </h1>

      <Pill tone={user.email_verified ? 'teal' : 'warm'} className="hidden shrink-0 md:inline-flex">
        {t(user.email_verified ? 'admin.verification.verified' : 'admin.verification.unverified')}
      </Pill>

      <UserMenu />
    </header>
  );
}
