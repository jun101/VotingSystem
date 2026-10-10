import type { ComponentProps, ReactNode } from 'react';
import { cx } from './cx';

type ShowcaseCardProps = Omit<ComponentProps<'section'>, 'children'> & {
  /** The showcase panel: the main item. */
  panel: ReactNode;
  /** The light body. */
  children: ReactNode;
  /** Classes of the panel. */
  panelClassName?: string;
};

/** A card split in a showcase panel and a light body: side by side from `md`, stacked under it. */
export function ShowcaseCard({
  panel,
  children,
  panelClassName,
  className,
  ...rest
}: ShowcaseCardProps) {
  return (
    <section
      className={cx(
        'flex flex-col overflow-hidden rounded-lg border border-line bg-surface shadow-2 md:flex-row',
        className,
      )}
      {...rest}
    >
      <div className={cx('bg-hero p-5 text-surface md:w-2/5 md:shrink-0 md:p-6', panelClassName)}>
        {panel}
      </div>
      <div className="min-w-0 flex-1 p-5 md:p-6">{children}</div>
    </section>
  );
}
