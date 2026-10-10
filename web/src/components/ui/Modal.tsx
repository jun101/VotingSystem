'use client';

import { useEffect, useId, useRef, type KeyboardEvent, type ReactNode } from 'react';
import { Icon, type IconName } from '@/components/admin/Icon';
import { cx } from './cx';
import { FOCUSABLE, wrapTarget } from './focusWrap';

type ModalProps = {
  title: string;
  /** The round icon of the coloured header. */
  icon: IconName;
  /** The accessible name of the close button. */
  closeLabel: string;
  /** Escape, the close button and a tap on the scrim ask for this; the page unmounts the modal. */
  onClose: () => void;
  /** A call is running: Escape, the scrim and the close button wait. */
  busy?: boolean;
  /** `data-testid` of the dialog and of its close button. */
  testId?: string;
  closeTestId?: string;
  /** Wider than a party's form (the candidate form of slice 06c). */
  wide?: boolean;
  children: ReactNode;
};

/**
 * A modal form (frontend.md 1.5 item 12): a real dialog (`role="dialog"`, `aria-modal`, named by
 * its title), centred on a dimmed scrim from 768 px and a sheet from the bottom, full width and
 * at most 92 % of the height, under it. Its height never passes the screen: the header stays and
 * the body scrolls. While it is open the page behind does not scroll and is inert; the keyboard
 * focus moves in (to the element marked `data-autofocus`, else the first control), cycles with
 * Tab and Shift+Tab, and returns to the control that opened it when the modal is unmounted.
 * Escape, the close button and the scrim close it. It is shown when it is mounted and gone when
 * it is not, so the page decides when with its own state.
 */
export function Modal({
  title,
  icon,
  closeLabel,
  onClose,
  busy = false,
  testId,
  closeTestId,
  wide = false,
  children,
}: ModalProps) {
  const dialog = useRef<HTMLDialogElement>(null);
  const titleId = useId();

  useEffect(() => {
    const element = dialog.current;

    if (!element) return;

    // What had the focus when the modal opened (the button that was pressed).
    const opener = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    const overflow = document.body.style.overflow;

    document.body.style.overflow = 'hidden';

    if (!element.open) element.showModal();

    const first = element.querySelector<HTMLElement>('[data-autofocus]');

    first?.focus();

    return () => {
      document.body.style.overflow = overflow;

      if (element.open) element.close();

      // Closed by the page (a save) as much as by the person: the focus goes back either way.
      if (opener?.isConnected) opener.focus();
    };
  }, []);

  function dismiss() {
    if (!busy) onClose();
  }

  function onKeyDown(event: KeyboardEvent<HTMLDialogElement>) {
    if (event.key !== 'Tab' || !dialog.current) return;

    const items = Array.from(dialog.current.querySelectorAll<HTMLElement>(FOCUSABLE));
    const active = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    const target = wrapTarget(
      items,
      active,
      event.shiftKey,
      active !== null && dialog.current.contains(active),
    );

    if (target === null) return;

    event.preventDefault();
    target?.focus();
  }

  return (
    <dialog
      ref={dialog}
      role="dialog"
      aria-modal="true"
      aria-labelledby={titleId}
      data-testid={testId}
      onKeyDown={onKeyDown}
      onCancel={(event) => {
        event.preventDefault();
        dismiss();
      }}
      onClick={(event) => {
        // Only the scrim is the dialog itself: the header and the body cover the rest.
        if (event.target === dialog.current) dismiss();
      }}
      className={cx(
        'modal-sheet m-0 mt-auto max-h-[92vh] w-full max-w-none flex-col overflow-hidden rounded-t-xl border-0 bg-surface p-0 text-ink shadow-3 backdrop:bg-deep/60 open:flex',
        'md:m-auto md:max-h-[calc(100vh-3rem)] md:rounded-xl',
        wide ? 'md:w-[min(70rem,calc(100vw-3rem))]' : 'md:w-[min(35rem,calc(100vw-3rem))]',
      )}
    >
      <header
        data-tone="info"
        className="sect-head relative flex h-16 shrink-0 items-center gap-3 overflow-hidden pr-3 pl-4 md:pl-6"
      >
        <span
          aria-hidden="true"
          className="flex size-9.5 shrink-0 items-center justify-center rounded-full bg-glass-line"
        >
          <Icon name={icon} size={21} />
        </span>
        <h2 id={titleId} className="min-w-0 flex-1 font-display text-xl font-extrabold">
          {title}
        </h2>
        <button
          type="button"
          aria-label={closeLabel}
          data-testid={closeTestId}
          disabled={busy}
          onClick={dismiss}
          className="ui-control inline-flex size-11 shrink-0 items-center justify-center rounded-full bg-glass text-surface hover:bg-glass-line focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-surface disabled:opacity-40"
        >
          <Icon name="close" size={20} />
        </button>
      </header>
      <div className="flex min-h-0 flex-1 flex-col overflow-y-auto">{children}</div>
    </dialog>
  );
}
