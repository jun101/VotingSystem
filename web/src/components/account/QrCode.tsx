'use client';

import { create } from 'qrcode';
import { useMemo } from 'react';

/** Blank modules around the code, as the standard asks (4 modules). */
const QUIET_ZONE = 4;

/**
 * A QR code, drawn here in the browser from the text it holds: nothing is sent to any other
 * service. It is an SVG with a text alternative; the colours are tokens (dark modules on the
 * white surface, which scanners need).
 */
export function QrCode({ value, label }: { value: string; label: string }) {
  const { size, path } = useMemo(() => {
    const { modules } = create(value, { errorCorrectionLevel: 'M' });
    const runs: string[] = [];

    // One rectangle per run of dark modules in a row keeps the drawing small.
    for (let row = 0; row < modules.size; row++) {
      let start = -1;

      for (let column = 0; column <= modules.size; column++) {
        const dark = column < modules.size && modules.data[row * modules.size + column] === 1;

        if (dark && start < 0) start = column;

        if (!dark && start >= 0) {
          runs.push(
            `M${start + QUIET_ZONE} ${row + QUIET_ZONE}h${column - start}v1h-${column - start}z`,
          );
          start = -1;
        }
      }
    }

    return { size: modules.size + QUIET_ZONE * 2, path: runs.join('') };
  }, [value]);

  return (
    <svg
      role="img"
      aria-label={label}
      data-testid="two-factor-qr"
      viewBox={`0 0 ${size} ${size}`}
      shapeRendering="crispEdges"
      className="size-48 shrink-0 rounded border border-line md:size-56"
    >
      <title>{label}</title>
      <rect width={size} height={size} className="fill-surface" />
      <path d={path} className="fill-ink" />
    </svg>
  );
}
