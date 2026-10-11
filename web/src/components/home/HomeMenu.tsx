'use client';

import Link from 'next/link';
import { useEffect, useRef, useState } from 'react';
import { Icon } from '@/components/admin/Icon';
import { linkStyles } from './links';

type Item = { href: string; label: string };

/**
 * The menu of a phone: the section links and the two account links, in a panel under a button.
 * Closes on a choice, on Escape and when focus leaves it.
 */
export function HomeMenu({
  items,
  signIn,
  create,
  openLabel,
  closeLabel,
}: {
  items: Item[];
  signIn: Item;
  create: Item;
  openLabel: string;
  closeLabel: string;
}) {
  const [open, setOpen] = useState(false);
  const root = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!open) return;

    function onKey(event: KeyboardEvent) {
      if (event.key === 'Escape') setOpen(false);
    }

    function onPointer(event: MouseEvent) {
      if (root.current && !root.current.contains(event.target as Node)) setOpen(false);
    }

    document.addEventListener('keydown', onKey);
    document.addEventListener('mousedown', onPointer);

    return () => {
      document.removeEventListener('keydown', onKey);
      document.removeEventListener('mousedown', onPointer);
    };
  }, [open]);

  return (
    <div ref={root} className="relative lg:hidden">
      <button
        type="button"
        data-testid="home-menu-button"
        aria-expanded={open}
        aria-controls="home-menu-panel"
        aria-label={open ? closeLabel : openLabel}
        onClick={() => setOpen((value) => !value)}
        className="ui-control flex size-11 items-center justify-center rounded-full border border-line bg-surface text-ink hover:bg-primary-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
      >
        <Icon name={open ? 'close' : 'list'} size={22} />
      </button>

      {open ? (
        <div
          id="home-menu-panel"
          className="absolute top-full right-0 z-20 mt-2 flex w-64 flex-col gap-1 rounded-lg border border-line bg-surface p-3 text-ink shadow-3"
        >
          {items.map((item) => (
            <a
              key={item.href}
              href={item.href}
              onClick={() => setOpen(false)}
              className="rounded-md px-3 py-2.5 text-md font-semibold text-ink hover:bg-primary-soft focus-visible:outline-2 focus-visible:outline-primary"
            >
              {item.label}
            </a>
          ))}
          <div className="mt-2 flex flex-col gap-2 border-t border-line-soft pt-3">
            <Link href={signIn.href} className={linkStyles.outline}>
              {signIn.label}
            </Link>
            <Link href={create.href} className={linkStyles.primary}>
              {create.label}
            </Link>
          </div>
        </div>
      ) : null}
    </div>
  );
}
