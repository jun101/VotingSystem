import { cx } from '@/components/ui/cx';
import { initials } from '@/lib/public/share';

const TONES = [
  'bg-primary-soft text-primary-hover',
  'bg-teal-soft text-teal-ink',
  'bg-warm-soft text-warm-ink',
  'bg-status-published-soft text-status-published',
] as const;

function toneOf(name: string): number {
  let sum = 0;

  for (const char of name) sum = (sum * 31 + (char.codePointAt(0) ?? 0)) % 9973;

  return sum % TONES.length;
}

/**
 * A round picture of a candidate or an institution: its photo (already optimised, served by the
 * media disk) or, without one, the initials on a soft colour. Decorative: the name is written next to it.
 */
export function Avatar({
  name,
  src,
  size = 'md',
  className,
}: {
  name: string;
  src: string | null;
  size?: 'md' | 'lg';
  className?: string;
}) {
  const box = size === 'lg' ? 'size-16 text-xl' : 'size-12 text-base';

  if (src) {
    return (
      // eslint-disable-next-line @next/next/no-img-element
      <img
        src={src}
        alt=""
        loading="lazy"
        className={cx('shrink-0 rounded-full bg-line-soft object-cover', box, className)}
      />
    );
  }

  return (
    <span
      aria-hidden="true"
      className={cx(
        'flex shrink-0 items-center justify-center rounded-full font-display font-extrabold',
        box,
        TONES[toneOf(name)],
        className,
      )}
    >
      {initials(name)}
    </span>
  );
}
