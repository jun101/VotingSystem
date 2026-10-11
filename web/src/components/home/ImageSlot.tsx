import type { ReactNode } from 'react';
import { cx } from '@/components/ui/cx';

type ImageSlotProps = {
  /** The real picture, when there is one: a path under `public/` or a full address. Nothing else changes. */
  src?: string;
  /** What the picture shows, for a screen reader. Empty when it is only decoration. */
  alt?: string;
  /** Shown on the striped placeholder only: what picture belongs here. */
  placeholder: string;
  /** Laid over the picture, at its bottom left (the stat chip). */
  children?: ReactNode;
  className?: string;
};

/**
 * A named place for a picture. Without `src` it is a striped placeholder with a line saying what
 * picture is wanted; give it `src` and `alt` and the picture fills the same place.
 */
export function ImageSlot({ src, alt = '', placeholder, children, className }: ImageSlotProps) {
  return (
    <div
      data-testid="image-slot"
      data-filled={src ? 'true' : 'false'}
      className={cx(
        'relative grid min-h-72 place-items-center overflow-hidden rounded-xl border border-line',
        src ? undefined : 'bg-stripes',
        className,
      )}
    >
      {src ? (
        // A plain element: the picture is chosen later and its size is not known here.
        // eslint-disable-next-line @next/next/no-img-element
        <img src={src} alt={alt} className="absolute inset-0 size-full object-cover" />
      ) : (
        <p className="max-w-xs px-6 text-center text-md font-semibold text-ink-soft">
          {placeholder}
        </p>
      )}
      {children ? <div className="absolute bottom-4 left-4">{children}</div> : null}
    </div>
  );
}
