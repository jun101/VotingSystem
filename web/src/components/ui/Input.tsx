import { useId, type ComponentProps } from 'react';
import { cx } from './cx';

type InputProps = Omit<ComponentProps<'input'>, 'size'> & {
  /** Always present, tied to the field. */
  label: string;
  help?: string;
  /** Sets aria-invalid and is read out with the field. */
  error?: string;
};

export function Input({ label, help, error, id, className, ...rest }: InputProps) {
  const generated = useId();
  const fieldId = id ?? generated;
  const errorId = `${fieldId}-error`;
  const helpId = `${fieldId}-help`;

  // The error comes first, so it is the first thing read after the label.
  const describedBy = [error ? errorId : null, help ? helpId : null].filter(Boolean).join(' ');

  return (
    <div className="flex flex-col gap-1">
      <label htmlFor={fieldId} className="text-base font-semibold text-ink">
        {label}
      </label>
      <input
        id={fieldId}
        aria-invalid={error ? 'true' : undefined}
        aria-describedby={describedBy || undefined}
        className={cx(
          'min-h-11 rounded border bg-surface px-3 text-[16px] text-ink md:text-base',
          error ? 'border-danger' : 'border-line-strong',
          'focus-visible:border-primary focus-visible:ring-4 focus-visible:ring-primary-soft focus-visible:outline-none',
          'disabled:bg-line-soft disabled:text-ink-muted',
          className,
        )}
        {...rest}
      />
      {error ? (
        <p id={errorId} className="text-sm font-medium text-danger">
          {error}
        </p>
      ) : null}
      {help ? (
        <p id={helpId} className="text-sm text-ink-soft">
          {help}
        </p>
      ) : null}
    </div>
  );
}
