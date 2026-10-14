import Link from 'next/link';
import { Icon } from '@/components/admin/Icon';
import { PublicShell } from '@/components/public/PublicShell';
import { cx } from '@/components/ui/cx';
import type { GuideCatalog } from '@/lib/i18n/guide';
import { AnnotatedShot } from './AnnotatedShot';
import { GUIDE_ROLES, GUIDE_SECTIONS, shotOf, type GuideRole } from './guide-data';

/**
 * One manual: the pills of the manuals the reader may open, the contents on the left, then the numbered sections, each
 * with its numbered steps, its annotated picture and an optional "good to know".
 */
export function GuideView({
  role,
  allowed,
  catalog,
  productName,
  own,
}: {
  role: GuideRole;
  allowed: GuideRole[];
  catalog: GuideCatalog;
  productName: string;
  /** The manual of the reader's own account: the pill is marked and the "not for you" panel points to it. */
  own: GuideRole;
}) {
  const ui = catalog.ui;
  const manual = catalog.roles[role];
  const sections = GUIDE_SECTIONS[role];

  const pills = (
    <nav aria-label={ui.manuals} className="flex flex-wrap gap-2">
      {GUIDE_ROLES.filter((item) => allowed.includes(item)).map((item) => (
        <Link
          key={item}
          href={`/guide/${item}`}
          data-testid={`guide-tab-${item}`}
          aria-current={item === role ? 'page' : undefined}
          className={cx(
            'inline-flex min-h-10 items-center rounded-full px-4 text-sm font-bold focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-surface',
            item === role ? 'bg-surface text-ink' : 'bg-glass text-surface',
          )}
        >
          {catalog.roles[item].name}
        </Link>
      ))}
    </nav>
  );

  return (
    <PublicShell
      testId="guide-page"
      productName={productName}
      kicker={ui.kicker!}
      kickerIcon="book"
      title={manual.name}
      titleTestId="guide-title"
      skip={ui.skip!}
      hero={
        <>
          <p className="max-w-3xl text-md text-hero-ink-soft">{manual.lead}</p>
          {pills}
        </>
      }
    >
      <div className="grid gap-6 lg:grid-cols-[15rem_1fr]">
        <nav
          aria-label={ui.contents}
          className="h-fit rounded-lg border border-line bg-surface p-4 shadow-1 lg:sticky lg:top-4"
        >
          <p className="mb-2 text-xs font-extrabold tracking-wide text-ink-soft uppercase">
            {ui.contents}
          </p>
          <ol className="flex flex-col gap-1">
            {sections.map((section, index) => (
              <li key={section.id}>
                <a
                  href={`#${section.id}`}
                  className="flex gap-2 rounded-md px-2 py-1 text-sm text-ink hover:bg-line-soft focus-visible:outline-2 focus-visible:outline-primary"
                >
                  <span className="font-extrabold text-ink-soft tabular-nums">
                    {String(index + 1).padStart(2, '0')}
                  </span>
                  {manual.sections[section.id]?.title}
                </a>
              </li>
            ))}
          </ol>
        </nav>

        <div className="flex min-w-0 flex-col gap-6">
          {sections.map((section, index) => {
            const text = manual.sections[section.id];
            const shot = shotOf(section.shot);

            if (!text) return null;

            return (
              <section
                key={section.id}
                id={section.id}
                data-testid={`guide-section-${section.id}`}
                className="scroll-mt-4 rounded-lg border border-line bg-surface p-5 shadow-1 md:p-6"
              >
                <p className="text-xs font-extrabold tracking-wide text-ink-soft uppercase">
                  {String(index + 1).padStart(2, '0')}
                </p>
                <h2 className="mt-1 font-display text-2xl font-extrabold text-ink">{text.title}</h2>
                <p className="mt-2 text-md text-ink-2">{text.lead}</p>

                <ol className="mt-4 flex flex-col gap-3">
                  {text.steps.map((step, stepIndex) => (
                    <li key={stepIndex} className="flex gap-3 text-base leading-relaxed text-ink">
                      <span
                        aria-hidden="true"
                        className="flex size-7 shrink-0 items-center justify-center rounded-full bg-ink text-sm font-extrabold text-surface"
                      >
                        {stepIndex + 1}
                      </span>
                      <span className="pt-0.5">{step}</span>
                    </li>
                  ))}
                </ol>

                {text.commands ? (
                  <pre className="mt-4 overflow-x-auto rounded-md bg-ink p-4 text-sm leading-relaxed text-surface">
                    <code>{text.commands.join('\n')}</code>
                  </pre>
                ) : null}

                {shot && text.legend ? (
                  <div className="mt-5">
                    <AnnotatedShot shot={shot} legend={text.legend} alt={text.title} />
                  </div>
                ) : null}

                {text.tip ? (
                  <aside className="mt-5 flex gap-3 rounded-md border-l-4 border-accent bg-line-soft p-4 text-base text-ink">
                    <Icon name="info" size={20} />
                    <p>
                      <strong>{ui.tip}</strong> {text.tip}
                    </p>
                  </aside>
                ) : null}
              </section>
            );
          })}
          <p className="text-center text-sm text-ink-soft">{ui.demo}</p>
          {own !== role ? (
            <p className="text-center text-sm">
              <Link className="font-bold text-primary underline" href={`/guide/${own}`}>
                {ui.mine}
              </Link>
            </p>
          ) : null}
        </div>
      </div>
    </PublicShell>
  );
}
