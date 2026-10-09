import type { ComponentProps } from 'react';
import { cx } from './cx';

export type ButtonVariant = 'primary' | 'secondary' | 'danger' | 'quiet' | 'quietDanger' | 'accent';
export type ButtonSize = 'admin' | 'voter';
/** A pill (the default), or the compact rectangle with rounded corners for dense rows. */
export type ButtonShape = 'pill' | 'rounded';

type ButtonProps = ComponentProps<'button'> & {
  variant?: ButtonVariant;
  size?: ButtonSize;
  shape?: ButtonShape;
  /** Busy: announced, not pressable, and the button keeps its width. */
  loading?: boolean;
  /** A light sweeping over a main button (the sign-in card and the admin list). */
  shimmer?: boolean;
};

const variants: Record<ButtonVariant, string> = {
  primary:
    'bg-primary text-surface shadow-button hover:bg-primary-hover border-primary hover:border-primary-hover',
  secondary: 'bg-surface text-ink border-line-strong shadow-1 hover:bg-primary-soft',
  danger: 'bg-surface text-danger border-danger-line hover:bg-warm-softer',
  quiet: 'bg-transparent text-primary-hover border-transparent hover:bg-primary-soft',
  quietDanger: 'bg-transparent text-danger border-transparent hover:bg-warm-softer',
  // The one main action on a gradient surface. Text is never white on this coral.
  accent: 'bg-accent-gradient text-deep shadow-button border-transparent hover:border-accent-light',
};

const sizes: Record<ButtonSize, string> = {
  admin: 'min-h-11 px-5 text-base',
  voter: 'min-h-12 px-6 text-md',
};

const shapes: Record<ButtonShape, string> = {
  pill: 'rounded-full',
  rounded: 'rounded',
};

export function Button({
  variant = 'primary',
  size = 'admin',
  shape = 'pill',
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
        'ui-control lift-sm relative inline-flex items-center justify-center border font-semibold',
        shapes[shape],
        'focus-visible:border-primary focus-visible:ring-4 focus-visible:ring-primary-soft focus-visible:outline-2 focus-visible:outline-primary',
        'disabled:border-line disabled:bg-line-soft disabled:bg-none disabled:text-ink-muted disabled:shadow-none',
        variants[variant],
        (variant === 'accent' || variant === 'primary') && shimmer && 'shimmer-sweep',
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
