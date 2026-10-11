'use client';

import { useMemo } from 'react';
import type { TurnoutBucket, TurnoutPoint } from '@/lib/api/turnout';
import { pluralForm } from '@/lib/format/electionDates';
import { useI18n } from '@/lib/i18n/client';
import { bucketLabel, chartShape, labelIndexes } from './turnoutModel';

const BOX = { width: 720, height: 280, left: 46, right: 18, top: 16, bottom: 40 } as const;

/**
 * The turnout over time as hand-made SVG, tokens only: bars for the votes of each period, a line
 * and a soft area for the running total. The picture has a text alternative (the total and the
 * latest period), and the same numbers are never the only way to read it. Nothing here knows
 * what anyone voted.
 */
export function TurnoutChart({
  points,
  bucket,
  zone,
}: {
  points: readonly TurnoutPoint[];
  bucket: TurnoutBucket;
  zone: string;
}) {
  const { t, locale } = useI18n();
  const shape = useMemo(() => chartShape(points, BOX), [points]);
  const last = points[points.length - 1];
  const unit = t(`turnout.chart.unit.${bucket}`);
  const label = last
    ? t(`turnout.chart.label.${pluralForm(locale, last.total)}`, {
        unit,
        total: last.total,
        last: last.voted,
        at: bucketLabel(last.at, bucket, locale, zone),
      })
    : t('turnout.chart.labelEmpty');
  const labels = labelIndexes(points.length);

  return (
    <div className="flex flex-col gap-3">
      <svg
        data-testid="turnout-chart-time"
        role="img"
        aria-label={label}
        viewBox={`0 0 ${BOX.width} ${BOX.height}`}
        className="h-auto w-full"
      >
        {shape.ticks.map((tick) => (
          <g key={tick.value}>
            <line
              x1={BOX.left}
              x2={BOX.width - BOX.right}
              y1={tick.y}
              y2={tick.y}
              className={tick.value === 0 ? 'stroke-line-strong' : 'stroke-line-soft'}
              strokeWidth={1}
            />
            <text
              x={BOX.left - 8}
              y={tick.y + 4}
              textAnchor="end"
              className="fill-ink-soft"
              fontSize={12}
            >
              {tick.value}
            </text>
          </g>
        ))}

        {shape.bars.map((bar, index) => (
          <rect
            key={points[index]!.at}
            x={bar.x}
            y={bar.y}
            width={bar.width}
            height={bar.height}
            rx={3}
            className="fill-primary-line"
          />
        ))}

        {shape.area ? <path d={shape.area} className="fill-primary-soft" opacity={0.7} /> : null}
        {shape.line ? (
          <path
            d={shape.line}
            fill="none"
            className="stroke-primary"
            strokeWidth={3}
            strokeLinejoin="round"
            strokeLinecap="round"
          />
        ) : null}
        {shape.dots.length <= 48
          ? shape.dots.map((dot) => (
              <circle
                key={dot.at}
                cx={dot.x}
                cy={dot.y}
                r={4}
                className="fill-surface stroke-primary"
                strokeWidth={2}
              />
            ))
          : null}

        {labels.map((index) => (
          <text
            key={points[index]!.at}
            x={shape.dots[index]!.x}
            y={BOX.height - 14}
            textAnchor="middle"
            className="fill-ink-soft"
            fontSize={12}
          >
            {bucketLabel(points[index]!.at, bucket, locale, zone)}
          </text>
        ))}

        {points.length === 0 ? (
          <text
            x={(BOX.left + BOX.width - BOX.right) / 2}
            y={BOX.top + (BOX.height - BOX.top - BOX.bottom) / 2}
            textAnchor="middle"
            className="fill-ink-soft"
            fontSize={14}
          >
            {t('turnout.chart.empty')}
          </text>
        ) : null}
      </svg>
      <ul className="flex flex-wrap gap-x-5 gap-y-1.5 text-sm text-ink-soft">
        <li className="inline-flex items-center gap-2">
          <span aria-hidden="true" className="h-1 w-5 rounded-full bg-primary" />
          {t('turnout.chart.legendTotal')}
        </li>
        <li className="inline-flex items-center gap-2">
          <span aria-hidden="true" className="size-3 rounded-sm bg-primary-line" />
          {t('turnout.chart.legendBucket', { unit })}
        </li>
      </ul>
    </div>
  );
}
