/** The product's mark: a white tile with a check. Decorative unless it is given a `label`. */
export function LogoMark({ size = 48, label }: { size?: number; label?: string }) {
  return (
    <svg
      width={size}
      height={size}
      viewBox="0 0 48 48"
      role={label ? 'img' : undefined}
      aria-label={label}
      aria-hidden={label ? undefined : 'true'}
      focusable="false"
      className="shrink-0"
    >
      <rect width="48" height="48" rx="14" className="fill-surface" />
      <path
        d="M13 25l7 7 15-17"
        fill="none"
        strokeWidth="5"
        strokeLinecap="round"
        strokeLinejoin="round"
        className="stroke-primary"
      />
    </svg>
  );
}
