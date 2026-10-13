'use client';

import { useId, type ChangeEvent } from 'react';

const LENGTH = 10;
const GROUP = 5;

/** What the boxes show: the letters and digits of the typed text, upper case, at most ten. */
export function codeCharacters(raw: string): string[] {
  return raw
    .replace(/[^a-z0-9]/gi, '')
    .toUpperCase()
    .slice(0, LENGTH)
    .split('');
}

/**
 * The voting code as two groups of five boxes. A real, transparent input lies over the boxes, so
 * typing, pasting, autofill and screen readers all use one ordinary field; the boxes only show it.
 */
export function CodeBoxes({
  label,
  help,
  value,
  onChange,
  error,
  errorTestId,
  testId,
}: {
  label: string;
  help: string;
  value: string;
  onChange: (value: string) => void;
  error?: string;
  errorTestId?: string;
  testId?: string;
}) {
  const id = useId();
  const chars = codeCharacters(value);
  const errorId = `${id}-error`;
  const helpId = `${id}-help`;

  return (
    <div className="flex w-full flex-col items-center gap-3">
      <label htmlFor={id} className="text-md font-semibold text-ink">
        {label}
      </label>
      <div className="code-field relative w-full max-w-2xl" data-invalid={error ? 'true' : undefined}>
        <div aria-hidden="true" className="flex w-full items-center gap-1 sm:gap-2">
          {Array.from({ length: LENGTH }, (_, index) => (
            <span key={index} className="contents">
              {index === GROUP ? (
                <span className="h-0.5 w-2 rounded-full bg-ink-muted sm:w-3" />
              ) : null}
              <span
                className="code-box"
                data-filled={index < chars.length ? 'true' : undefined}
                data-current={index === Math.min(chars.length, LENGTH - 1) ? 'true' : undefined}
              >
                {chars[index] ?? ''}
              </span>
            </span>
          ))}
        </div>
        <input
          id={id}
          data-testid={testId}
          value={value}
          onChange={(event: ChangeEvent<HTMLInputElement>) => onChange(event.target.value)}
          autoComplete="off"
          autoCapitalize="characters"
          autoCorrect="off"
          spellCheck={false}
          maxLength={32}
          aria-invalid={error ? 'true' : undefined}
          aria-describedby={[error ? errorId : null, helpId].filter(Boolean).join(' ')}
          className="absolute inset-0 size-full cursor-text bg-transparent text-[16px] text-transparent caret-transparent outline-none selection:bg-transparent"
        />
      </div>
      {error ? (
        <p
          id={errorId}
          data-testid={errorTestId}
          className="text-center text-base font-medium text-danger"
        >
          {error}
        </p>
      ) : null}
      <p id={helpId} className="text-center text-sm text-ink-soft">
        {help}
      </p>
    </div>
  );
}
