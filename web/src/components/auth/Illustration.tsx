/**
 * The flat drawing of the sign-in card's panel: a ballot dropping into a box, a plant, two
 * speech bubbles. Decorative only (`aria-hidden`); every colour is a token.
 */
export function Illustration({ className }: { className?: string }) {
  return (
    <svg
      data-testid="auth-illustration"
      aria-hidden="true"
      focusable="false"
      viewBox="0 0 560 330"
      className={className}
    >
      <g className="art-blob-a">
        <path
          d="M70 250C40 170 90 90 180 80c70-8 120-60 190-30 70 30 110 100 80 170-30 70-110 90-190 90-90 0-80-20-190-60z"
          className="fill-primary-hover"
        />
      </g>
      <g className="art-blob-b">
        <path
          d="M150 262c-25-60 20-110 90-120 60-8 110 25 120 85 8 50-30 75-100 78-70 3-95-5-110-43z"
          className="fill-primary-line"
        />
      </g>
      <g className="art-leaf">
        <path
          d="M70 300c-4-40 4-80 22-112"
          strokeWidth="4"
          fill="none"
          strokeLinecap="round"
          className="stroke-deep"
        />
        <path d="M92 190c-26-6-40-30-36-52 26 4 42 24 36 52z" className="fill-deep" />
        <path d="M92 190c24-14 48-8 58 10-24 14-48 10-58-10z" className="fill-accent-light" />
        <path d="M80 236c-24-2-38-20-34-40 24 2 38 18 34 40z" className="fill-accent-light" />
      </g>
      <rect x="40" y="296" width="500" height="6" rx="3" className="fill-deep opacity-30" />
      <g>
        <rect x="250" y="206" width="170" height="92" rx="10" className="fill-accent" />
        <rect x="250" y="206" width="170" height="22" rx="10" className="fill-accent-light" />
        <rect x="298" y="212" width="74" height="9" rx="4.5" className="fill-deep" />
        <path
          d="M262 244l14 14 28-30"
          fill="none"
          strokeWidth="5"
          strokeLinecap="round"
          strokeLinejoin="round"
          className="stroke-surface"
        />
      </g>
      <g className="art-paper">
        <rect x="312" y="176" width="52" height="38" rx="5" className="fill-surface" />
        <path
          d="M322 190h32M322 199h20"
          strokeWidth="4"
          strokeLinecap="round"
          className="stroke-primary"
        />
      </g>
      <g>
        <circle cx="196" cy="212" r="17" className="fill-warm-soft" />
        <path d="M178 206c4-18 30-20 36-4-10-4-24-4-36 4z" className="fill-deep" />
        <rect x="176" y="230" width="42" height="68" rx="16" className="fill-accent-light" />
        <rect x="170" y="268" width="56" height="30" rx="10" className="fill-deep" />
      </g>
      <g className="art-bubble">
        <rect x="200" y="120" width="64" height="40" rx="12" className="fill-surface" />
        <path d="M214 160l-4 14 20-14z" className="fill-surface" />
        <path
          d="M214 134h36M214 144h24"
          strokeWidth="4"
          strokeLinecap="round"
          className="stroke-primary-line"
        />
      </g>
      <g className="art-bubble-b">
        <rect x="290" y="96" width="52" height="34" rx="12" className="fill-accent" />
        <circle cx="308" cy="113" r="4" className="fill-surface" />
        <circle cx="324" cy="113" r="4" className="fill-surface" />
      </g>
    </svg>
  );
}

/** The small ballot box that floats in the corner of the register panel. */
export function BoxArt({ className }: { className?: string }) {
  return (
    <svg
      data-testid="auth-illustration"
      aria-hidden="true"
      focusable="false"
      viewBox="0 0 150 120"
      className={className}
    >
      <g className="art-blob-a">
        <rect x="10" y="48" width="130" height="68" rx="10" className="fill-accent" />
        <rect x="10" y="48" width="130" height="18" rx="10" className="fill-accent-light" />
        <rect x="46" y="53" width="58" height="8" rx="4" className="fill-deep" />
        <g transform="rotate(-6 75 34)">
          <rect x="48" y="14" width="54" height="40" rx="5" className="fill-surface" />
          <path
            d="M58 28h34M58 38h22"
            strokeWidth="4"
            strokeLinecap="round"
            className="stroke-primary"
          />
        </g>
      </g>
    </svg>
  );
}
