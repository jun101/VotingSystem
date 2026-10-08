import { useId, type ComponentProps } from 'react';
import { cx } from './cx';

type TextareaProps = ComponentProps<'textarea'> & {
  /** Always present, tied to the field. */
  label: string;
  help?: string;
  /** Sets aria-invalid and is read out with the field. */
  error?: string;
  /** `data-testid` of the error message. */
  errorTestId?: string;
};

/** A multi-line field with the label, help and error of an `Input`. */
export function Textarea({
  label,
  help,
  error,
  errorTestId,
  id,
  className,
  ...rest
}: TextareaProps) {
  const generated = useId();
  const fieldId = id ?? generated;
  const errorId = `${fieldId}-error`;
  const helpId = `${fieldId}-help`;
  const describedBy = [error ? errorId : null, help ? helpId : null].filter(Boolean).join(' ');

  return (
    <div className="flex flex-col gap-1">
      <label htmlFor={fieldId} className="text-base font-semibold text-ink">
        {label}
      </label>
      <textarea
        id={fieldId}
        aria-invalid={error ? 'true' : undefined}
        aria-describedby={describedBy || undefined}
        className={cx(
          'min-h-24 w-full rounded border bg-surface px-3 py-2 text-[16px] text-ink md:text-base',
          error ? 'border-danger' : 'border-line-strong',
          'focus-visible:border-primary focus-visible:ring-4 focus-visible:ring-primary-soft focus-visible:outline-none',
          'disabled:bg-line-soft disabled:text-ink-2',
          className,
        )}
        {...rest}
      />
      {error ? (
        <p id={errorId} data-testid={errorTestId} className="text-sm font-medium text-danger">
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
