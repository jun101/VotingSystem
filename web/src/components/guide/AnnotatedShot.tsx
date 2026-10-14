'use client';

import { useState } from 'react';
import { cx } from '@/components/ui/cx';
import type { GuideShot } from './guide-data';

/**
 * A screenshot with numbered markers on the parts a section explains, and the matching numbered legend under it.
 * Pointing at, or focusing, a legend line highlights its marker on the picture, and the reverse.
 */
export function AnnotatedShot({
  shot,
  legend,
  alt,
}: {
  shot: GuideShot;
  legend: string[];
  alt: string;
}) {
  const [active, setActive] = useState<number | null>(null);
  const markers = shot.markers.slice(0, legend.length);

  return (
    <figure className="flex flex-col gap-3">
      <div
        className="relative overflow-hidden rounded-lg border border-line bg-surface shadow-1"
        style={{ aspectRatio: `${shot.width} / ${shot.height}` }}
      >
        {/* eslint-disable-next-line @next/next/no-img-element -- fixed files of /public, sized by the frame */}
        <img
          src={shot.src}
          alt={alt}
          width={shot.width}
          height={shot.height}
          className="block h-auto w-full"
        />
        {markers.map(([x, y], index) => (
          <button
            key={index}
            type="button"
            aria-label={`${index + 1}. ${legend[index]}`}
            onMouseEnter={() => setActive(index)}
            onMouseLeave={() => setActive(null)}
            onFocus={() => setActive(index)}
            onBlur={() => setActive(null)}
            className={cx(
              'absolute flex size-5 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full text-[10px] font-extrabold shadow-2 ring-2 ring-surface transition-transform focus-visible:outline-2 focus-visible:outline-primary md:size-7 md:text-xs',
              active === index ? 'z-10 scale-125 bg-ink text-surface' : 'bg-accent text-deep',
            )}
            style={{ left: `${x}%`, top: `${y}%` }}
          >
            {index + 1}
          </button>
        ))}
      </div>
      <ol className="flex flex-col gap-1">
        {legend.map((line, index) => (
          <li
            key={index}
            onMouseEnter={() => setActive(index)}
            onMouseLeave={() => setActive(null)}
            className={cx(
              'flex gap-3 rounded-md px-2 py-1.5 text-base leading-relaxed transition-colors',
              active === index ? 'bg-line-soft' : '',
            )}
          >
            <span
              aria-hidden="true"
              className={cx(
                'flex size-6 shrink-0 items-center justify-center rounded-full text-xs font-extrabold',
                active === index ? 'bg-ink text-surface' : 'bg-accent text-deep',
              )}
            >
              {index + 1}
            </span>
            <span className="text-ink">{line}</span>
          </li>
        ))}
      </ol>
    </figure>
  );
}
