import type { ComponentProps } from 'react';
import { cx } from './cx';

export type ButtonVariant = 'primary' | 'secondary' | 'danger' | 'quiet' | 'accent';
export type ButtonSize = 'admin' | 'voter';

type ButtonProps = ComponentProps<'button'> & {
  variant?: ButtonVariant;
  size?: ButtonSize;
  /** Busy: announced, not pressable, and the button keeps its width. */
  loading?: boolean;
  /** A light sweeping over an accent button (the code entry screen only). */
  shimmer?: boolean;
};

const variants: Record<ButtonVariant, string> = {
  primary:
    'bg-primary text-surface hover:bg-primary-hover border-primary hover:border-primary-hover',
  secondary: 'bg-surface text-ink border-line-strong hover:bg-surface-alt',
  danger: 'bg-surface text-danger border-danger-line hover:bg-warm-softer',
  quiet: 'bg-transparent text-primary border-transparent hover:bg-primary-soft',
  // The one main action of a screen. Text is never white on this orange.
  accent: 'bg-accent-gradient text-navy-deep border-transparent hover:border-accent-light',
};

const sizes: Record<ButtonSize, string> = {
  admin: 'min-h-11 px-4 text-base',
  voter: 'min-h-12 px-6 text-md',
};

export function Button({
  variant = 'primary',
  size = 'admin',
  loading = false,
  shimmer = false,
  disabled,
  type = 'button',
  className,
  children,
  ...rest
}: ButtonProps) {
  return (
    <button
      type={type}
      disabled={disabled || loading}
      aria-busy={loading ? 'true' : undefined}
      className={cx(
        'ui-control relative inline-flex items-center justify-center rounded border font-semibold',
        'focus-visible:border-primary focus-visible:ring-4 focus-visible:ring-primary-soft focus-visible:outline-2 focus-visible:outline-primary',
        'disabled:border-line disabled:bg-line-soft disabled:bg-none disabled:text-ink-muted',
        variants[variant],
        variant === 'accent' && shimmer && 'shimmer-sweep',
        sizes[size],
        className,
      )}
      {...rest}
    >
      {/* The label stays in place (and for screen readers) so the width does not change. */}
      <span className={cx(loading && 'text-transparent')}>{children}</span>
      {loading ? (
        <span
          aria-hidden="true"
          className="absolute size-4 animate-spin rounded-full border-2 border-ink-muted border-t-transparent"
        />
      ) : null}
    </button>
  );
}
