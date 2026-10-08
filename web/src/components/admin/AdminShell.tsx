'use client';

import { usePathname } from 'next/navigation';
import {
  useCallback,
  useEffect,
  useRef,
  useState,
  type KeyboardEvent,
  type ReactNode,
} from 'react';
import type { CurrentUser } from '@/lib/api/user';
import { useI18n } from '@/lib/i18n/client';
import { AdminUserProvider } from './AdminUser';
import { focusRing } from './classes';
import { SideMenu } from './SideMenu';
import { TopBar } from './TopBar';
import { VerifyBanner } from './VerifyBanner';

const FOCUSABLE =
  'a[href], button:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])';

/** True when the element is an input of text, so a key typed there is a character. */
function isTyping(target: EventTarget | null): boolean {
  if (!(target instanceof HTMLElement)) return false;

  return target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName);
}

/**
 * The admin area's frame: a fixed side menu from `lg` (272 px) and a drawer below it, the
 * top bar, the verification banner and the content, which fills the width.
 *
 * The one menu element is both: from `lg` it is fixed in place, below it slides in from the
 * left. The drawer closes with Escape, a tap outside or the choice of a page; the focus moves
 * into it when it opens, stays in it while it is open and goes back to the menu button.
 */
export function AdminShell({ user, children }: { user: CurrentUser; children: ReactNode }) {
  const { t } = useI18n();
  const pathname = usePathname();
  const [open, setOpen] = useState(false);
  const drawer = useRef<HTMLDivElement>(null);
  const menuButton = useRef<HTMLButtonElement>(null);
  const search = useRef<HTMLInputElement>(null);
  const closeButton = useRef<HTMLButtonElement>(null);

  const returnFocus = useRef(false);

  const close = useCallback(() => setOpen(false), []);

  // A page change ends the drawer's turn (a link of the menu does it already; a back button too).
  const [shownPath, setShownPath] = useState(pathname);

  if (shownPath !== pathname) {
    setShownPath(pathname);
    setOpen(false);
  }

  // Opening moves the focus into the drawer; closing gives it back to the menu button, once
  // the rest of the page is no longer inert.
  useEffect(() => {
    if (open) {
      returnFocus.current = true;
      closeButton.current?.focus();
    } else if (returnFocus.current) {
      returnFocus.current = false;
      menuButton.current?.focus();
    }
  }, [open]);

  // From `lg` the menu is fixed in place: an open drawer ends there, so the content is not
  // left inert behind a backdrop and a menu button that are both hidden.
  useEffect(() => {
    const wide = window.matchMedia('(min-width: 1024px)');

    function onChange(event: MediaQueryListEvent) {
      if (event.matches) setOpen(false);
    }

    wide.addEventListener('change', onChange);

    return () => wide.removeEventListener('change', onChange);
  }, []);

  // Escape closes the drawer; "/" goes to the search from anywhere but a field.
  useEffect(() => {
    function onKey(event: globalThis.KeyboardEvent) {
      if (event.key === 'Escape' && open) {
        event.preventDefault();
        close();

        return;
      }

      if (
        event.key === '/' &&
        !event.ctrlKey &&
        !event.metaKey &&
        !event.altKey &&
        !isTyping(event.target)
      ) {
        event.preventDefault();

        if (window.matchMedia('(min-width: 1024px)').matches) {
          search.current?.focus();
        } else {
          setOpen(true);
          window.setTimeout(() => search.current?.focus(), 0);
        }
      }
    }

    document.addEventListener('keydown', onKey);

    return () => document.removeEventListener('keydown', onKey);
  }, [open, close]);

  // While the drawer is open, Tab and Shift+Tab turn inside it.
  function keepFocusInside(event: KeyboardEvent<HTMLDivElement>) {
    if (event.key !== 'Tab' || !open || !drawer.current) return;

    const items = Array.from(drawer.current.querySelectorAll<HTMLElement>(FOCUSABLE));
    const first = items[0];
    const last = items[items.length - 1];

    if (!first || !last) return;

    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  }

  return (
    <AdminUserProvider user={user}>
      <div data-testid="admin-shell" className="min-h-screen bg-canvas">
        <a
          href="#admin-content"
          data-testid="skip-link"
          className={`sr-only focus:not-sr-only focus:fixed focus:start-3 focus:top-3 focus:z-50 focus:rounded focus:bg-surface focus:px-4 focus:py-3 focus:font-semibold focus:text-ink ${focusRing}`}
        >
          {t('admin.shell.skip')}
        </a>

        {open ? (
          <div
            aria-hidden="true"
            data-testid="menu-backdrop"
            onClick={close}
            className="fixed inset-0 z-30 bg-navy/60 lg:hidden"
          />
        ) : null}

        <div
          ref={drawer}
          id="menu-drawer"
          data-testid="menu-drawer"
          data-open={open}
          onKeyDown={keepFocusInside}
          className="menu-drawer fixed inset-y-0 start-0 z-40 w-[272px]"
        >
          <SideMenu user={user} searchRef={search} closeRef={closeButton} onNavigate={close} />
        </div>

        <div inert={open} className="flex min-h-screen flex-col lg:pl-[272px]">
          <TopBar open={open} menuButtonRef={menuButton} onMenu={() => setOpen(true)} />
          <main
            id="admin-content"
            tabIndex={-1}
            className="flex flex-1 flex-col gap-6 p-4 outline-none md:p-8"
          >
            <VerifyBanner />
            {children}
          </main>
        </div>
      </div>
    </AdminUserProvider>
  );
}
