import type { ReactNode } from 'react';
import { Icon, type IconName } from '@/components/admin/Icon';
import { cx } from '@/components/ui/cx';

export type PanelTone = 'info' | 'calendar' | 'settings' | 'cover' | 'facts' | 'steps';

/**
 * A card of the election page or form: a 64 px header band in a gradient (its colours follow the
 * tone), with a round icon, the title and, at the right, a counter; then the body.
 */
export function Panel({
  tone,
  icon,
  title,
  id,
  testId,
  headerTestId,
  aside,
  className,
  bodyClassName,
  plain = false,
  children,
}: {
  tone: PanelTone;
  icon: IconName;
  title: string;
  /** The id of the title, which names the card. */
  id: string;
  testId?: string;
  headerTestId?: string;
  aside?: ReactNode;
  className?: string;
  bodyClassName?: string;
  /**
   * No lift on hover, no clipping and no container: for a card that holds a popover (the date
   * picker), which must not be cut off or moved by its card.
   */
  plain?: boolean;
  children: ReactNode;
}) {
  return (
    <section
      aria-labelledby={id}
      data-testid={testId}
      className={cx(
        'relative flex min-w-0 flex-col rounded-lg border border-line bg-surface shadow-2',
        plain ? null : 'lift @container overflow-hidden',
        className,
      )}
    >
      <header
        data-testid={headerTestId}
        data-tone={tone}
        className="sect-head relative flex h-16 shrink-0 items-center gap-3 overflow-hidden rounded-t-lg px-5"
      >
        <span
          aria-hidden="true"
          className="flex size-9.5 shrink-0 items-center justify-center rounded-full bg-glass-line"
        >
          <Icon name={icon} size={21} />
        </span>
        <h2 id={id} className="min-w-0 flex-1 font-display text-xl font-extrabold">
          {title}
        </h2>
        {aside}
      </header>
      <div className={cx('flex flex-1 flex-col gap-3.5 px-5 pt-4.5 pb-5', bodyClassName)}>
        {children}
      </div>
    </section>
  );
}

/** The chip on the right of a header. */
export const PANEL_ASIDE =
  'inline-flex h-7.5 items-center gap-1.5 rounded-full bg-glass-line px-3 text-sm font-bold whitespace-nowrap';
