import { useId, type ComponentProps } from 'react';
import { cx } from './cx';

type SelectProps = ComponentProps<'select'> & {
  /** Always present, tied to the field. */
  label: string;
  help?: string;
  /** Sets aria-invalid and is read out with the field. */
  error?: string;
  /** `data-testid` of the error message. */
  errorTestId?: string;
};

/** A native `select` with the label, help and error of an `Input`. Its options are its children. */
export function Select({
  label,
  help,
  error,
  errorTestId,
  id,
  className,
  children,
  ...rest
}: SelectProps) {
  const generated = useId();
  const fieldId = id ?? generated;
  const errorId = `${fieldId}-error`;
  const helpId = `${fieldId}-help`;
  const describedBy = [error ? errorId : null, help ? helpId : null].filter(Boolean).join(' ');

  return (
    <div className="flex flex-col gap-1">
      <div className="field-box">
        <select
          id={fieldId}
          aria-invalid={error ? 'true' : undefined}
          aria-describedby={describedBy || undefined}
          className={cx('field-control min-h-11 rounded-t text-[16px] md:text-md', className)}
          {...rest}
        >
          {children}
        </select>
        <label htmlFor={fieldId} className="field-label">
          {label}
        </label>
        <span aria-hidden="true" className="field-bar" />
      </div>
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
