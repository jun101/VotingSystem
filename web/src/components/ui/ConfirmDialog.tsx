'use client';

import { useEffect, useId, useRef, type KeyboardEvent, type ReactNode } from 'react';
import { Button } from './Button';

type ConfirmDialogProps = {
  title: string;
  /** What will happen, one or two sentences. */
  children: ReactNode;
  confirmLabel: string;
  cancelLabel: string;
  /** The call is running: the buttons wait. */
  busy?: boolean;
  /** Why the action did not happen; shown inside the dialog, which stays open. */
  error?: string | null;
  /** The id of the element that describes the dialog; the whole body when not given (a body that holds a form should name its sentence). */
  describedBy?: string;
  onConfirm: () => void;
  onCancel: () => void;
  /** `data-testid` of the dialog, of its two buttons and of its error. */
  testIds?: { dialog?: string; confirm?: string; cancel?: string; error?: string };
};

const FOCUSABLE = 'button:not([disabled]), a[href], input:not([disabled]), select:not([disabled])';

/**
 * A confirmation in a native modal `dialog`: the rest of the page is inert, the keyboard focus
 * turns inside it (Tab and Shift+Tab), Escape and a tap on the backdrop cancel, and the focus goes
 * back to the control that opened it. It is shown when it is mounted and gone when it is not,
 * so the page decides when with its own state. The cancel button has the focus first: the action
 * is destructive.
 */
export function ConfirmDialog({
  title,
  children,
  confirmLabel,
  cancelLabel,
  busy = false,
  error = null,
  describedBy,
  onConfirm,
  onCancel,
  testIds = {},
}: ConfirmDialogProps) {
  const dialog = useRef<HTMLDialogElement>(null);
  const titleId = useId();
  const bodyId = useId();

  useEffect(() => {
    const element = dialog.current;

    if (element && !element.open) element.showModal();

    // Closing a modal dialog gives the focus back to what had it when it opened.
    return () => {
      if (element?.open) element.close();
    };
  }, []);

  // Closing the dialog before the page removes it is what gives the focus back to the control
  // that opened it: a dialog that is simply removed leaves the focus on the page's body.
  function dismiss() {
    if (busy) return;

    dialog.current?.close();
    onCancel();
  }

  function onKeyDown(event: KeyboardEvent<HTMLDialogElement>) {
    if (event.key !== 'Tab' || !dialog.current) return;

    const items = Array.from(dialog.current.querySelectorAll<HTMLElement>(FOCUSABLE));
    const first = items[0];
    const last = items[items.length - 1];

    if (!first || !last) {
      event.preventDefault();

      return;
    }

    const active = document.activeElement;

    if (event.shiftKey && (active === first || !dialog.current.contains(active))) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && (active === last || !dialog.current.contains(active))) {
      event.preventDefault();
      first.focus();
    }
  }

  return (
    <dialog
      ref={dialog}
      data-testid={testIds.dialog}
      aria-labelledby={titleId}
      aria-describedby={describedBy ?? bodyId}
      onKeyDown={onKeyDown}
      onCancel={(event) => {
        // Escape: closed by dismiss(), so the focus goes back first.
        event.preventDefault();
        dismiss();
      }}
      onClick={(event) => {
        // Only the backdrop is the dialog itself: the content has its own box.
        if (event.target === dialog.current) dismiss();
      }}
      className="m-auto w-[min(92vw,28rem)] rounded-lg border border-line bg-surface p-0 text-ink shadow-3 backdrop:bg-deep/60"
    >
      <div className="flex flex-col gap-4 p-5 md:p-6">
        <h2 id={titleId} className="text-xl font-extrabold text-ink">
          {title}
        </h2>
        <div id={bodyId} className="text-base text-ink-soft">
          {children}
        </div>
        {error ? (
          <p role="alert" data-testid={testIds.error} className="text-base font-medium text-danger">
            {error}
          </p>
        ) : null}
        <div className="flex flex-wrap justify-end gap-3">
          <Button
            variant="secondary"
            disabled={busy}
            onClick={dismiss}
            data-testid={testIds.cancel}
          >
            {cancelLabel}
          </Button>
          <Button variant="danger" loading={busy} onClick={onConfirm} data-testid={testIds.confirm}>
            {confirmLabel}
          </Button>
        </div>
      </div>
    </dialog>
  );
}
