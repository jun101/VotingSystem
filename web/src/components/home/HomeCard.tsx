import type { ReactNode } from 'react';
import { Icon, type IconName } from '@/components/admin/Icon';
import type { HeadTone } from '@/components/vote/VoteCard';

/**
 * A white card under a coloured header (round icon, title that wraps). The header is the dashboard's
 * `dash-head`, so the colours are the same tokens.
 */
export function HomeCard({
  tone,
  icon,
  head,
  children,
}: {
  tone: HeadTone;
  icon: IconName;
  head: string;
  children: ReactNode;
}) {
  return (
    <article className="flex min-w-0 flex-col overflow-hidden rounded-lg border border-line bg-surface shadow-2">
      <header
        data-head={tone}
        className="dash-head relative flex min-h-16 shrink-0 items-center gap-3 overflow-hidden px-5 py-2"
      >
        <span
          aria-hidden="true"
          className="flex size-10 shrink-0 items-center justify-center rounded-full bg-glass-line"
        >
          <Icon name={icon} size={22} />
        </span>
        <h3 className="min-w-0 flex-1 font-display text-lg font-extrabold">{head}</h3>
      </header>
      <div className="flex flex-1 flex-col gap-1 p-5 text-md text-ink-soft">{children}</div>
    </article>
  );
}
