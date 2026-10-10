import Link from 'next/link';
import type { ReactNode } from 'react';
import { LogoMark } from '@/components/ui';
import { BoxArt, Illustration } from './Illustration';

type AuthLayoutProps = {
  productName: string;
  /** The promise on the panel, in the display font. */
  promise: string;
  /** The steps of the register panel (the first one is the current one); without them, the illustration. */
  steps?: string[];
  children: ReactNode;
};

/**
 * Screen A01, the sign-in card: one white card on the drifting backdrop, a periwinkle panel on
 * its left (logo, promise, and the illustration that overhangs the card, or the four steps of
 * the register page) and the form on its right. It stacks on a phone, where the illustration
 * stays inside the panel. The form's own heading is the page's `h1`.
 */
export function AuthLayout({ productName, promise, steps, children }: AuthLayoutProps) {
  return (
    <div
      data-testid="auth-backdrop"
      className="bg-backdrop flex min-h-screen items-center justify-center px-4 py-8 md:px-6 md:py-12"
    >
      <main
        data-testid="auth-card"
        className="card-rise relative flex w-full max-w-[1000px] flex-col rounded-xl bg-surface shadow-3 md:flex-row"
      >
        <aside
          data-testid="auth-panel"
          className="bg-panel relative flex min-w-0 flex-col overflow-hidden rounded-t-xl px-7 pt-8 text-surface md:min-h-[480px] md:flex-[340_1_0%] md:overflow-visible md:rounded-t-none md:rounded-l-xl md:px-9 md:pt-9"
        >
          <Link
            href="/"
            aria-label={productName}
            className="w-fit rounded-sm focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-accent-light"
          >
            <LogoMark size={48} />
          </Link>
          <p
            data-testid="auth-promise"
            className="mt-6 max-w-[300px] font-display text-[22px] leading-tight font-extrabold tracking-tight text-surface md:text-[26px]"
          >
            {promise}
          </p>

          {steps ? (
            <>
              <ol className="mt-7 flex flex-col gap-5 pb-8 md:pb-24">
                {steps.map((step, index) => (
                  <li
                    key={step}
                    data-testid={`register-step-${index + 1}`}
                    aria-current={index === 0 ? 'step' : undefined}
                    className="flex items-start gap-3.5"
                  >
                    <span
                      aria-hidden="true"
                      className={`flex size-[34px] shrink-0 items-center justify-center rounded-full bg-surface font-display text-md font-extrabold text-primary-hover shadow-2 ${
                        index === 0 ? 'step-current' : ''
                      }`}
                    >
                      {index + 1}
                    </span>
                    <span className="pt-1.5 text-md font-medium">{step}</span>
                  </li>
                ))}
              </ol>
              <BoxArt className="pointer-events-none absolute right-6 bottom-5 hidden w-[150px] md:block" />
            </>
          ) : (
            <Illustration className="pointer-events-none relative mt-4 w-full md:absolute md:bottom-[18px] md:left-3 md:mt-0 md:w-[116%] md:max-w-[560px]" />
          )}
        </aside>

        <section
          data-testid="auth-form-side"
          className="flex min-w-0 flex-col justify-center px-6 py-8 md:flex-[380_1_0%] md:px-14 md:py-11"
        >
          <div className="mx-auto flex w-full max-w-md flex-col gap-5">{children}</div>
        </section>
      </main>
    </div>
  );
}
