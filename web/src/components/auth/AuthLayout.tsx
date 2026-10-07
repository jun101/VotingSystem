import type { ReactNode } from 'react';
import { Reveal } from '@/components/motion';
import { PageShell, ShowcaseCard } from '@/components/ui';

type AuthLayoutProps = {
  productName: string;
  /** The line on the showcase panel. */
  tagline: string;
  /** What the product does, one line each (shown from the `md` breakpoint). */
  points: string[];
  children: ReactNode;
};

/**
 * Screen A01: a showcase panel and the light panel that holds the form, side by side from
 * `md` and stacked under it. The form's own heading is the page's `h1`.
 */
export function AuthLayout({ productName, tagline, points, children }: AuthLayoutProps) {
  return (
    <PageShell productName={productName} width="wide">
      <Reveal>
        <ShowcaseCard
          panelClassName="flex flex-col justify-center gap-6 md:p-10"
          panel={
            <>
              <p className="font-display text-2xl leading-tight font-bold md:text-4xl">{tagline}</p>
              <ul className="hidden flex-col gap-3 text-md text-hero-ink-soft md:flex">
                {points.map((point) => (
                  <li key={point}>{point}</li>
                ))}
              </ul>
            </>
          }
        >
          <div className="mx-auto flex w-full max-w-md flex-col gap-5 md:py-4">{children}</div>
        </ShowcaseCard>
      </Reveal>
    </PageShell>
  );
}
