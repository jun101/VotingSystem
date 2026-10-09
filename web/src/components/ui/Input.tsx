import { useId, type ComponentProps, type ReactNode } from 'react';
import { cx } from './cx';

type InputProps = Omit<ComponentProps<'input'>, 'size'> & {
  /** Always present, tied to the field. */
  label: string;
  help?: string;
  /** Sets aria-invalid and is read out with the field. */
  error?: string;
  /** `data-testid` of the error message. */
  errorTestId?: string;
  /** A control inside the field, at its right end (the "show password" button). */
  trailing?: ReactNode;
  /** `data-testid` of the label. */
  labelTestId?: string;
};

export function Input({
  label,
  help,
  error,
  errorTestId,
  trailing,
  labelTestId,
  id,
  className,
  ...rest
}: InputProps) {
  const generated = useId();
  const fieldId = id ?? generated;
  // These show their own format or file name, so the label never sits over them.
  const floated =
    (rest.placeholder && rest.placeholder !== ' ') ||
    ['date', 'datetime-local', 'time', 'month', 'week', 'file'].includes(rest.type ?? '');
  const errorId = `${fieldId}-error`;
  const helpId = `${fieldId}-help`;

  // The error comes first, so it is the first thing read after the label.
  const describedBy = [error ? errorId : null, help ? helpId : null].filter(Boolean).join(' ');

  return (
    <div className="flex flex-col gap-1">
      <div className="field-box">
        <input
          id={fieldId}
          placeholder=" "
          aria-invalid={error ? 'true' : undefined}
          aria-describedby={describedBy || undefined}
          data-floated={floated ? '' : undefined}
          className={cx(
            'field-control min-h-11 rounded-t text-[16px] md:text-md',
            trailing ? 'pr-12' : undefined,
            className,
          )}
          {...rest}
        />
        <label htmlFor={fieldId} data-testid={labelTestId} className="field-label">
          {label}
        </label>
        <span aria-hidden="true" className="field-bar" />
        {trailing ? (
          <div className="absolute right-0 bottom-0 flex h-11 items-center">{trailing}</div>
        ) : null}
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
