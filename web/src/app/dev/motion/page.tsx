import { notFound } from 'next/navigation';
import {
  ActionBar,
  Band,
  Button,
  Card,
  ClosingBand,
  GlassTile,
  Hero,
  HeroPill,
  PageShell,
  ShowcaseCard,
} from '@/components/ui';
import {
  Check,
  CountUp,
  Float,
  GrowBar,
  LiveDot,
  Reveal,
  Shimmer,
  Slide,
} from '@/components/motion';
import { getI18n } from '@/lib/i18n/server';

export const dynamic = 'force-dynamic';

/** Every composition piece and every motion effect. Development only. */
export default async function MotionPage() {
  // Not available when the application runs in production mode.
  if (process.env.NODE_ENV === 'production') notFound();

  const { locale, t } = await getI18n();

  const avatar = (
    <span
      aria-hidden="true"
      className="block size-10 rounded-full border border-glass-line bg-glass"
    />
  );

  return (
    <PageShell
      productName={t('app.name')}
      withActionBar
      hero={
        <Hero
          data-testid="demo-hero"
          live
          pill={
            <HeroPill>
              <LiveDot className="text-accent-light" />
              {t('dev.motion.pill')}
            </HeroPill>
          }
          label={t('dev.motion.label')}
          title={t('dev.motion.title')}
          accent={t('dev.motion.accent')}
          lede={t('dev.motion.lede')}
          figures={
            <>
              <GlassTile data-testid="demo-glass" figure="1 240" label={t('dev.motion.tileA')} />
              <GlassTile data-testid="demo-glass" figure="87 %" label={t('dev.motion.tileB')} />
              <GlassTile data-testid="demo-glass" figure="1 078" label={t('dev.motion.tileC')} />
            </>
          }
          stage={
            <>
              <Float tilt="a" className="self-start">
                <Card className="w-72">
                  <p className="font-bold text-ink">{t('dev.motion.stageTitle')}</p>
                  <p className="text-ink-soft">{t('dev.motion.stageLine')}</p>
                </Card>
              </Float>
              <Float tilt="b" className="self-end">
                <Card className="w-72">
                  <p className="font-bold text-ink">{t('dev.motion.stageTitleB')}</p>
                  <p className="text-ink-soft">{t('dev.motion.stageLineB')}</p>
                </Card>
              </Float>
            </>
          }
        />
      }
    >
      <ShowcaseCard
        data-testid="demo-showcase-card"
        panel={
          <>
            <p className="text-sm font-semibold tracking-wide text-hero-ink-soft uppercase">
              {t('dev.motion.showcasePanel')}
            </p>
            <h2 className="mt-2 text-xl text-surface md:text-2xl">
              {t('dev.motion.showcaseTitle')}
            </h2>
          </>
        }
      >
        <p className="text-ink-soft">{t('dev.motion.showcaseText')}</p>
      </ShowcaseCard>

      <div className="grid gap-4 md:grid-cols-2">
        <Band
          data-testid="demo-band"
          avatar={avatar}
          label={t('dev.motion.bandLabel')}
          name={t('dev.motion.bandName')}
          figure={t('dev.motion.bandFigure')}
        />
        <Band
          tone="warm"
          avatar={avatar}
          label={t('dev.motion.bandWarmLabel')}
          name={t('dev.motion.bandWarmName')}
          figure={t('dev.motion.bandWarmFigure')}
        />
      </div>

      <div className="grid gap-4 md:grid-cols-2">
        <Card title={t('dev.motion.countTitle')}>
          <p className="font-display text-4xl font-bold text-primary">
            <CountUp
              data-testid="demo-countup"
              value={87}
              suffix={t('common.percent')}
              locale={locale}
            />
          </p>
          <p className="text-ink-soft">{t('dev.motion.countLabel')}</p>
        </Card>
        <Card title={t('dev.motion.growTitle')}>
          <GrowBar data-testid="demo-growbar" value={0.87} />
          <p className="mt-3 text-ink-soft">{t('dev.motion.growLabel')}</p>
        </Card>
      </div>

      <Reveal data-testid="demo-reveal">
        <Card title={t('dev.motion.revealTitle')}>
          <p className="text-ink-soft">{t('dev.motion.revealText')}</p>
        </Card>
      </Reveal>

      <Card title={t('dev.motion.staggerTitle')}>
        <Reveal stagger className="grid gap-3 md:grid-cols-3">
          {[t('dev.motion.stagger1'), t('dev.motion.stagger2'), t('dev.motion.stagger3')].map(
            (text) => (
              <p
                key={text}
                className="rounded border border-line-soft bg-surface-alt p-3 text-ink-2"
              >
                {text}
              </p>
            ),
          )}
        </Reveal>
      </Card>

      <div className="grid gap-4 md:grid-cols-2">
        <Card title={t('dev.motion.liveTitle')}>
          <p className="flex items-center gap-3 text-ink-soft">
            <span data-testid="demo-livedot" className="inline-flex p-2 text-teal">
              <LiveDot />
            </span>
            {t('dev.motion.liveLabel')}
          </p>
        </Card>
        <Card title={t('dev.motion.shimmerTitle')}>
          <div className="flex flex-col gap-3">
            <Shimmer data-testid="demo-shimmer" className="h-4 w-full" />
            <Shimmer className="h-4 w-2/3" />
            <div>
              <Button variant="accent" shimmer data-testid="demo-button-accent">
                {t('dev.motion.shimmerButton')}
              </Button>
            </div>
          </div>
        </Card>
      </div>

      <div className="grid gap-4 md:grid-cols-2">
        <Float data-testid="demo-float">
          <Card title={t('dev.motion.floatTitle')}>
            <p className="text-ink-soft">{t('dev.motion.floatText')}</p>
          </Card>
        </Float>
        <Card title={t('dev.motion.checkTitle')}>
          <div className="flex items-center gap-3">
            <Check
              data-testid="demo-check"
              label={t('dev.motion.checkLabel')}
              className="text-surface"
            />
            <p className="text-ink-soft">{t('dev.motion.checkLabel')}</p>
          </div>
        </Card>
      </div>

      <div className="grid gap-4 md:grid-cols-2">
        <div>
          <h2 className="mb-3 text-lg font-bold text-ink">{t('dev.motion.selectTitle')}</h2>
          <label
            data-testid="demo-select"
            className="select-ring block cursor-pointer rounded-lg bg-surface p-5 has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-primary"
          >
            <input type="radio" name="demo-select" className="sr-only" />
            <span className="block text-lg font-bold text-ink">{t('dev.motion.selectCard')}</span>
            <span className="mt-1 block text-ink-soft">{t('dev.motion.selectHelp')}</span>
          </label>
        </div>
        <div>
          <h2 className="mb-3 text-lg font-bold text-ink">{t('dev.motion.slideTitle')}</h2>
          <div className="flex flex-col gap-3">
            <Slide from="left">
              <Card>
                <p className="text-ink-soft">{t('dev.motion.slideLeft')}</p>
              </Card>
            </Slide>
            <Slide from="right">
              <Card>
                <p className="text-ink-soft">{t('dev.motion.slideRight')}</p>
              </Card>
            </Slide>
          </div>
        </div>
      </div>

      <ClosingBand
        data-testid="demo-closing-band"
        title={t('dev.motion.closingTitle')}
        line={t('dev.motion.closingLine')}
        action={<Button variant="accent">{t('dev.motion.closingAction')}</Button>}
      />

      <ActionBar data-testid="demo-action-bar" reminder={t('dev.motion.barReminder')}>
        <Button variant="accent" size="voter">
          {t('dev.motion.barAction')}
        </Button>
      </ActionBar>
    </PageShell>
  );
}
