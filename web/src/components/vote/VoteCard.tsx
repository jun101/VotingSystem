import type { ReactNode } from 'react';
import { Icon, type IconName } from '@/components/admin/Icon';
import { cx } from '@/components/ui/cx';

/** The gradients of a card's header: the names of the dashboard's headers. */
export type HeadTone =
  'open-election' | 'todo' | 'getting-started' | 'activity' | 'latest' | 'figures';

/**
 * A white card with a coloured header band (a round icon and the title), as the admin dashboard's
 * cards: the voter's pages use the same language. Decoration is `aria-hidden`; the title is the text.
 */
export function VoteCard({
  tone,
  icon,
  title,
  headingLevel = 2,
  aside,
  children,
  className,
  bodyClassName,
  ...rest
}: {
  tone: HeadTone;
  icon: IconName;
  title: string;
  headingLevel?: 2 | 3;
  aside?: ReactNode;
  children: ReactNode;
  className?: string;
  bodyClassName?: string;
  'data-testid'?: string;
}) {
  const Heading = headingLevel === 2 ? 'h2' : 'h3';

  return (
    <section
      className={cx(
        'flex min-w-0 flex-col overflow-hidden rounded-lg border border-line bg-surface shadow-2',
        className,
      )}
      {...rest}
    >
      <header
        data-head={tone}
        className="dash-head relative flex h-16 shrink-0 items-center gap-3 overflow-hidden px-5"
      >
        <span
          aria-hidden="true"
          className="flex size-10 shrink-0 items-center justify-center rounded-full bg-glass-line"
        >
          <Icon name={icon} size={22} />
        </span>
        <Heading className="min-w-0 flex-1 truncate font-display text-xl font-extrabold">
          {title}
        </Heading>
        {aside}
      </header>
      <div className={cx('flex flex-1 flex-col gap-4 p-5', bodyClassName)}>{children}</div>
    </section>
  );
}
