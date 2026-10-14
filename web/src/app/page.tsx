import Image from 'next/image';
import Link from 'next/link';
import { headers } from 'next/headers';
import { Icon, type IconName } from '@/components/admin/Icon';
import { HomeNav } from '@/components/home/HomeNav';
import { HeroSphere } from '@/components/home/HeroSphere';
import { LiveBallot } from '@/components/home/LiveBallot';
import { SiteFooter } from '@/components/home/SiteFooter';
import { linkStyles } from '@/components/home/links';
import { VoterDoor } from '@/components/home/VoterDoor';
import { InstallTerminal } from '@/components/home/InstallTerminal';
import { CountUp, LiveDot, Reveal } from '@/components/motion';
import { fetchHealthCached } from '@/lib/api/health';
import { I18nProvider } from '@/lib/i18n/client';
import { getI18n } from '@/lib/i18n/server';
import { getMessages, type Messages } from '@/lib/i18n/messages';

// Read at each visit: the page shows the state of the system now.
export const dynamic = 'force-dynamic';

// Heights of the five bars of the turnout card, and the delay of each.
const BARS = [
  { height: 40, delay: 0 },
  { height: 70, delay: 0.2 },
  { height: 55, delay: 0.4 },
  { height: 95, delay: 0.6 },
  { height: 35, delay: 0.8 },
];

// What follows each figure: symbols and the abbreviation "min", the same in both languages.
const FIGURE_SUFFIX = { a: '+', b: ' %', c: '', d: ' min' } as const;

// File names of the audience pictures, in `public/home/`.
const AUDIENCE_FILES = {
  school: 'ecole',
  association: 'association',
  institution: 'institution',
} as const;

const SECTION = 'mx-auto w-full max-w-6xl px-4 pt-16 md:pt-20';
const EYEBROW = 'text-xs font-bold tracking-wide text-primary-hover uppercase';
const TITLE = 'mt-2 text-2xl text-ink md:text-3xl';

export default async function HomePage() {
  const { locale, t } = await getI18n();
  const forwardedFor = (await headers()).get('x-forwarded-for');
  const health = await fetchHealthCached({ forwardedFor });
  const all = getMessages(locale);
  // The client parts of the page (the voter's field, the live ballot, the language switch) ship only these texts.
  const clientMessages = {
    home: all.home,
    vote: { language: all.vote.language },
  } as unknown as Partial<Messages>;

  const rows = [
    { id: 'api', label: t('home.status.api'), state: health.api },
    { id: 'database', label: t('home.status.database'), state: health.database },
    { id: 'redis', label: t('home.status.redis'), state: health.redis },
  ] as const;

  const overall = rows.every((row) => row.state === 'ok')
    ? 'overallOk'
    : rows.some((row) => row.state === 'down')
      ? 'overallDown'
      : 'overallUnknown';

  const assure: { key: 'secret' | 'once' | 'phone'; icon: IconName }[] = [
    { key: 'secret', icon: 'lock' },
    { key: 'once', icon: 'check' },
    { key: 'phone', icon: 'phone' },
  ];

  const steps = ['prepare', 'distribute', 'publish'] as const;

  const figures = ['a', 'b', 'c', 'd'] as const;
  const faq = ['account', 'phone', 'tie', 'results'] as const;

  return (
    <I18nProvider locale={locale} messages={clientMessages}>
      <div id="top" className="min-h-screen bg-canvas">
        <HomeNav t={t} />

        <main>
          {/* ---- Hero: a turning sphere of ballots with three floating cards ---- */}
          <section
            data-testid="home-hero"
            data-live="true"
            className="relative overflow-hidden pt-12 pb-24 md:pt-16 md:pb-28"
          >
            <div
              aria-hidden="true"
              className="hero-light-a absolute -top-24 -right-20 hidden size-96 rounded-full bg-backdrop-from/20 blur-3xl md:block"
            />
            <div
              aria-hidden="true"
              className="hero-light-b absolute -bottom-28 -left-24 hidden size-96 rounded-full bg-primary/20 blur-3xl md:block"
            />

            <div className="relative mx-auto grid w-full max-w-6xl items-center gap-10 px-4 lg:grid-cols-[minmax(0,1.05fr)_minmax(0,1fr)] lg:gap-14">
              <div className="flex min-w-0 flex-col items-start gap-5">
                <span className="inline-flex items-center gap-2 rounded-full bg-primary-soft px-3.5 py-1.5 text-sm font-bold tracking-wide text-primary-hover uppercase">
                  <LiveDot className="text-accent" />
                  {t('home.hero.pill')}
                </span>
                <h1
                  data-testid="home-hero-title"
                  className="text-4xl text-ink md:text-[56px] md:leading-[60px]"
                >
                  <span className="sr-only">{t('app.name')}</span> {t('home.hero.title')}
                  <br />
                  <span className="hero-word">{t('home.hero.accent')}</span>
                </h1>
                <p className="max-w-xl text-lg text-ink-soft md:text-xl">{t('home.hero.lede')}</p>
                <div className="flex flex-col gap-3 sm:flex-row">
                  <Link
                    href="/register"
                    data-testid="home-hero-create"
                    className={linkStyles.primary}
                  >
                    {t('home.hero.create')}
                    <Icon name="open" size={18} />
                  </Link>
                  <a href="#try" className={linkStyles.outline}>
                    {t('home.hero.try')}
                  </a>
                </div>
                <ul className="mt-2 flex flex-wrap gap-x-6 gap-y-2 text-base font-semibold text-ink-soft">
                  {assure.map((item) => (
                    <li key={item.key} className="flex items-center gap-2">
                      <span aria-hidden="true" className="text-teal">
                        <Icon name={item.icon} size={18} />
                      </span>
                      {t(`home.hero.assure.${item.key}`)}
                    </li>
                  ))}
                </ul>
              </div>

              <div
                aria-hidden="true"
                className="relative mx-auto hidden h-[460px] w-full max-w-lg md:block"
              >
                <HeroSphere className="pointer-events-none absolute top-1/2 left-1/2 h-[760px] w-[760px] max-w-none -translate-x-1/2 -translate-y-1/2" />
                <div className="home-float absolute top-4 right-0 w-64 rounded-lg bg-surface p-3.5 shadow-3">
                  <p className="bg-hero flex justify-between rounded-md px-3 py-2 font-display text-base font-extrabold text-surface">
                    <span>{t('home.heroCards.ballot')}</span>
                    <span>{t('home.heroCards.ballotStep')}</span>
                  </p>
                  <div className="mt-2.5 grid grid-cols-3 gap-2">
                    {[0, 1, 2].map((index) => (
                      <span
                        key={index}
                        className={`relative flex h-14 items-center justify-center rounded-md bg-primary-soft ${index === 1 ? 'outline-[3px] outline-primary' : ''}`}
                      >
                        <span className="size-8 rounded-full bg-primary-line" />
                        {index === 1 ? (
                          <span className="absolute -top-2 -right-1 flex size-5 items-center justify-center rounded-full bg-teal text-surface ring-2 ring-surface">
                            <Icon name="check" size={12} />
                          </span>
                        ) : null}
                      </span>
                    ))}
                  </div>
                </div>
                <div className="home-float absolute bottom-10 left-2 w-44 rounded-lg bg-primary p-4 text-surface shadow-3">
                  <p className="text-xs font-bold tracking-wide uppercase">
                    {t('home.heroCards.turnout')}
                  </p>
                  <p className="font-display text-4xl font-extrabold">
                    {t('home.heroCards.turnoutValue')}
                  </p>
                  <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-glass-line">
                    <div className="home-turn-bar h-full w-full rounded-full bg-surface" />
                  </div>
                </div>
                <div className="home-float absolute right-4 bottom-20 flex items-center gap-2.5 rounded-lg bg-surface px-4 py-3 text-base font-semibold text-ink shadow-3">
                  <span className="flex size-7 items-center justify-center rounded-full bg-teal-soft text-teal">
                    <Icon name="check" size={16} />
                  </span>
                  {t('home.heroCards.toast')}
                </div>
              </div>
            </div>
          </section>

          {/* ---- The two doors: one panel, two halves, over the hero's bottom edge ---- */}
          <section className="relative z-10 mx-auto -mt-10 w-full max-w-6xl px-4">
            <div className="rise-stagger grid rounded-xl bg-surface shadow-3 md:grid-cols-2">
              <div
                data-testid="home-voter-door"
                className="flex min-w-0 flex-col gap-4 p-5 sm:flex-row sm:p-7"
              >
                <span
                  aria-hidden="true"
                  className="flex size-14 shrink-0 items-center justify-center rounded-lg bg-primary-soft text-primary"
                >
                  <Icon name="lock" size={30} />
                </span>
                <div className="min-w-0 flex-1">
                  <h2 className="text-xl text-ink md:text-2xl">{t('home.voter.title')}</h2>
                  <p className="mt-1 mb-3 text-md text-ink-soft">{t('home.voter.text')}</p>
                  <VoterDoor />
                </div>
              </div>

              <div
                data-testid="home-organiser-door"
                className="flex min-w-0 flex-col gap-4 border-t border-line-soft p-5 sm:flex-row sm:p-7 md:border-t-0 md:border-l"
              >
                <span
                  aria-hidden="true"
                  className="flex size-14 shrink-0 items-center justify-center rounded-lg bg-teal-soft text-teal"
                >
                  <Icon name="ballot" size={30} />
                </span>
                <div className="flex min-w-0 flex-1 flex-col">
                  <h2 className="text-xl text-ink md:text-2xl">{t('home.organiser.title')}</h2>
                  <p className="mt-1 mb-4 text-md text-ink-soft">{t('home.organiser.text')}</p>
                  <div className="mt-auto flex flex-col gap-3 sm:flex-row">
                    <Link href="/login" data-testid="home-signin" className={linkStyles.primary}>
                      {t('home.organiser.signIn')}
                    </Link>
                    <Link
                      href="/register"
                      data-testid="home-register"
                      className={linkStyles.outline}
                    >
                      {t('home.organiser.register')}
                    </Link>
                  </div>
                </div>
              </div>
            </div>
          </section>

          {/* ---- Four figures, counting up, on the hero gradient ---- */}
          <section
            aria-labelledby="home-figures-title"
            className="bg-hero relative mt-16 overflow-hidden py-12 text-surface md:mt-20 md:py-14"
          >
            <div aria-hidden="true" className="hero-light -top-32 -right-24 size-96" />
            <h2 id="home-figures-title" className="sr-only">
              {t('home.figures.title')}
            </h2>
            <Reveal
              stagger
              className="relative mx-auto grid w-full max-w-6xl gap-y-8 px-4 text-center md:grid-cols-2 lg:grid-cols-4 lg:divide-x lg:divide-glass-line"
            >
              {figures.map((key, index) => (
                <p key={key} className="px-4">
                  <span
                    className={`block font-display text-4xl font-extrabold md:text-[52px] md:leading-[58px] ${index === 0 ? 'text-accent-light' : 'text-surface'}`}
                  >
                    <CountUp
                      value={all.home.figures[key].value}
                      suffix={FIGURE_SUFFIX[key]}
                      locale={locale}
                    />
                  </span>
                  <span className="mt-1 block text-md font-semibold text-surface">
                    {t(`home.figures.${key}.label`)}
                  </span>
                </p>
              ))}
            </Reveal>
          </section>

          {/* ---- Bento: five cards, each with its own small animation ---- */}
          <section
            id="security"
            aria-labelledby="home-bento-title"
            className={`${SECTION} scroll-mt-20`}
          >
            <Reveal>
              <p className={EYEBROW}>{t('home.bento.eyebrow')}</p>
              <h2 id="home-bento-title" className={`${TITLE} max-w-2xl`}>
                {t('home.bento.title')}
              </h2>
              <p className="mt-3 max-w-xl text-lg text-ink-soft">{t('home.bento.lead')}</p>
            </Reveal>
            <Reveal stagger className="mt-10 grid gap-5 md:grid-cols-6">
              <article className="dev-lift relative min-h-56 overflow-hidden rounded-xl bg-surface p-6 shadow-1 md:col-span-2">
                <h3 className="font-display text-xl font-extrabold text-ink">
                  {t('home.bento.secret.title')}
                </h3>
                <p className="mt-1.5 max-w-xs text-md text-ink-soft">
                  {t('home.bento.secret.text')}
                </p>
                <svg
                  aria-hidden="true"
                  viewBox="0 0 24 24"
                  className="absolute right-4 bottom-4 size-20 text-primary"
                  fill="none"
                  stroke="currentColor"
                  strokeWidth="1.8"
                  strokeLinecap="round"
                  strokeLinejoin="round"
                >
                  <rect
                    x="4"
                    y="10"
                    width="16"
                    height="11"
                    rx="2.5"
                    className="fill-primary-soft"
                  />
                  <g className="home-lock-shackle">
                    <path d="M8 10V7a4 4 0 0 1 8 0v3" />
                  </g>
                  <circle cx="12" cy="15.5" r="1.4" fill="currentColor" />
                </svg>
              </article>

              <article className="dev-lift relative min-h-56 overflow-hidden rounded-xl bg-surface p-6 shadow-1 md:col-span-2">
                <h3 className="font-display text-xl font-extrabold text-ink">
                  {t('home.bento.live.title')}
                </h3>
                <p className="mt-1.5 max-w-xs text-md text-ink-soft">{t('home.bento.live.text')}</p>
                <div
                  aria-hidden="true"
                  className="absolute right-5 bottom-4 flex h-24 items-end gap-2"
                >
                  {BARS.map((bar) => (
                    <i
                      key={bar.delay}
                      className="home-bar w-5 rounded-t-md rounded-b-sm bg-primary"
                      style={{ height: `${bar.height}%`, animationDelay: `${bar.delay}s` }}
                    />
                  ))}
                </div>
              </article>

              <article className="dev-lift bg-hero relative min-h-56 overflow-hidden rounded-xl p-6 text-surface shadow-1 md:col-span-2">
                <h3 className="font-display text-xl font-extrabold">
                  {t('home.bento.slips.title')}
                </h3>
                <p className="mt-1.5 max-w-xs text-md text-hero-ink-soft">
                  {t('home.bento.slips.text')}
                </p>
                <div
                  aria-hidden="true"
                  className="home-slip absolute right-5 -bottom-3 w-28 rotate-6 rounded-md bg-surface p-3 text-xs text-ink shadow-3"
                >
                  <p className="font-bold uppercase">{t('home.bento.slips.label')}</p>
                  <p className="font-mono">{t('home.bento.slips.code')}</p>
                  <span className="mt-2 block size-12 rounded-sm bg-ink" />
                </div>
              </article>

              <article className="dev-lift bg-band relative min-h-56 overflow-hidden rounded-xl p-6 text-surface shadow-1 md:col-span-3">
                <h3 className="font-display text-xl font-extrabold">
                  {t('home.bento.audit.title')}
                </h3>
                <p className="mt-1.5 max-w-sm text-md text-hero-ink-soft">
                  {t('home.bento.audit.text')}
                </p>
                <ul className="mt-4 font-mono text-base leading-7 text-accent-light">
                  {(['l1', 'l2', 'l3', 'l4'] as const).map((key) => (
                    <li key={key}>{t(`home.bento.audit.${key}`)}</li>
                  ))}
                </ul>
              </article>

              <article className="dev-lift relative min-h-56 overflow-hidden rounded-xl bg-surface p-6 shadow-1 md:col-span-3">
                <h3 className="font-display text-xl font-extrabold text-ink">
                  {t('home.bento.school.title')}
                </h3>
                <p className="mt-1.5 max-w-sm text-md text-ink-soft">
                  {t('home.bento.school.text')}
                </p>
                <div
                  aria-hidden="true"
                  className="absolute right-6 bottom-6 flex items-center gap-2.5"
                >
                  {[0, 0.3, 0.6].map((delay) => (
                    <span
                      key={delay}
                      className="home-ring size-5 rounded-full border-[3px] border-primary"
                      style={{ animationDelay: `${delay}s` }}
                    />
                  ))}
                </div>
              </article>
            </Reveal>
          </section>

          {/* ---- Audience: three photo cards ---- */}
          <section aria-labelledby="home-audience-title" className={`${SECTION} scroll-mt-20`}>
            <Reveal>
              <p className={EYEBROW}>{t('home.audience.eyebrow')}</p>
              <h2 id="home-audience-title" className={TITLE}>
                {t('home.audience.title')}
              </h2>
            </Reveal>
            <Reveal stagger className="mt-8 grid gap-5 md:grid-cols-3">
              {(['school', 'association', 'institution'] as const).map((key, index) => (
                <figure
                  key={key}
                  className="home-photo relative aspect-[4/5] overflow-hidden rounded-xl bg-primary-soft shadow-1 max-md:aspect-[16/10]"
                >
                  <Image
                    src={`/home/${AUDIENCE_FILES[key]}.webp`}
                    alt={t(`home.audience.${key}.alt`)}
                    fill
                    unoptimized
                    sizes="(min-width: 768px) 33vw, 100vw"
                    loading={index === 0 ? 'eager' : 'lazy'}
                    className="object-cover"
                  />
                  <figcaption className="absolute inset-x-0 bottom-0 bg-deep/80 px-5 pt-12 pb-4 font-display text-lg font-extrabold tracking-wide text-surface uppercase">
                    {t(`home.audience.${key}.title`)}
                  </figcaption>
                </figure>
              ))}
            </Reveal>
          </section>

          {/* ---- Try a vote: the live ballot on a dark band ---- */}
          <section id="try" aria-labelledby="home-try-title" className={`${SECTION} scroll-mt-20`}>
            <Reveal className="bg-band grid items-center gap-10 overflow-hidden rounded-2xl p-8 text-surface md:p-12 lg:grid-cols-[1fr_1.1fr]">
              <div>
                <p className="text-xs font-bold tracking-wide text-accent-light uppercase">
                  {t('home.try.eyebrow')}
                </p>
                <h2 id="home-try-title" className="mt-2 text-2xl text-surface md:text-3xl">
                  {t('home.try.title')}
                </h2>
                <p className="mt-3 max-w-md text-lg text-hero-ink-soft">{t('home.try.text')}</p>
              </div>
              <div className="mx-auto w-full max-w-md">
                <LiveBallot />
              </div>
            </Reveal>
          </section>

          {/* ---- Three steps ---- */}
          <section id="how" aria-labelledby="home-how-title" className={`${SECTION} scroll-mt-20`}>
            <Reveal>
              <p className={EYEBROW}>{t('home.steps.eyebrow')}</p>
              <h2 id="home-how-title" className={TITLE}>
                {t('home.steps.title')}
              </h2>
            </Reveal>
            <Reveal stagger className="mt-8 grid gap-5 md:grid-cols-3">
              {steps.map((step, index) => (
                <div key={step} className="dev-lift rounded-xl bg-surface p-6 shadow-1">
                  <span
                    aria-hidden="true"
                    className="block font-display text-[54px] leading-none font-extrabold text-primary-line"
                  >
                    {index + 1}
                  </span>
                  <h3 className="mt-3 font-display text-xl font-extrabold text-ink">
                    {t(`home.steps.${step}.title`)}
                  </h3>
                  <p className="mt-1.5 text-md text-ink-soft">{t(`home.steps.${step}.text`)}</p>
                </div>
              ))}
            </Reveal>
          </section>

          {/* ---- Install: the terminal and the editions ---- */}
          <section
            id="install"
            aria-labelledby="home-install-title"
            className={`${SECTION} scroll-mt-20`}
          >
            <Reveal>
              <p className={EYEBROW}>{t('home.install.eyebrow')}</p>
              <h2 id="home-install-title" className={TITLE}>
                {t('home.install.title')}
              </h2>
              <p className="mt-3 max-w-xl text-lg text-ink-soft">{t('home.install.lead')}</p>
            </Reveal>
            <Reveal stagger className="mt-8 grid items-start gap-5 md:grid-cols-2">
              <InstallTerminal />
              <ul className="flex flex-col gap-4 rounded-xl bg-surface p-5 shadow-1">
                {(['school', 'online', 'backup'] as const).map((key) => (
                  <li key={key} className="rounded-lg bg-primary-soft p-4">
                    <h3 className="font-display text-lg font-extrabold text-ink">
                      {t(`home.install.${key}.title`)}
                    </h3>
                    <p className="mt-0.5 text-md text-ink-soft">{t(`home.install.${key}.text`)}</p>
                  </li>
                ))}
              </ul>
            </Reveal>
          </section>

          {/* ---- Questions: a native accordion ---- */}
          <section id="faq" aria-labelledby="home-faq-title" className={`${SECTION} scroll-mt-20`}>
            <Reveal className="grid items-start gap-8 lg:grid-cols-[0.8fr_1.2fr] lg:gap-16">
              <div className="flex flex-col items-start">
                <p className={EYEBROW}>{t('home.faq.eyebrow')}</p>
                <h2 id="home-faq-title" className={TITLE}>
                  {t('home.faq.title')}
                </h2>
                <p className="mt-3 text-lg text-ink-soft">
                  {t('home.faq.lead')}{' '}
                  <a
                    href={`mailto:${t('home.footer.email')}`}
                    className="rounded-sm font-semibold text-primary-hover underline focus-visible:outline-2 focus-visible:outline-primary"
                  >
                    {t('home.footer.email')}
                  </a>
                </p>
              </div>

              <div>
                {faq.map((key, index) => (
                  <details
                    key={key}
                    open={index === 0}
                    className="group border-b border-line first:border-t"
                  >
                    <summary className="flex cursor-pointer list-none items-center justify-between gap-4 rounded-sm py-5 font-display text-lg font-extrabold text-ink focus-visible:outline-2 focus-visible:outline-primary [&::-webkit-details-marker]:hidden">
                      {t(`home.faq.${key}.q`)}
                      <span
                        aria-hidden="true"
                        className="flex size-8 shrink-0 items-center justify-center rounded-full bg-primary-soft text-primary-hover transition-transform group-open:rotate-45"
                      >
                        <Icon name="plus" size={16} />
                      </span>
                    </summary>
                    <p className="pb-5 text-md text-ink-soft">{t(`home.faq.${key}.a`)}</p>
                  </details>
                ))}
              </div>
            </Reveal>
          </section>

          {/* ---- Closing ---- */}
          <section aria-labelledby="home-closing-title" className={`${SECTION} pb-16 md:pb-20`}>
            <Reveal className="bg-hero relative overflow-hidden rounded-2xl px-6 py-14 text-center text-surface md:py-16">
              <span
                aria-hidden="true"
                className="home-float absolute -top-16 -left-14 size-56 rounded-full bg-hero-light"
              />
              <span
                aria-hidden="true"
                className="home-float absolute -right-6 -bottom-12 size-36 rounded-full bg-hero-light"
              />
              <h2
                id="home-closing-title"
                className="relative mx-auto max-w-2xl text-3xl text-surface md:text-4xl"
              >
                {t('home.closing.title')}
              </h2>
              <p className="relative mx-auto mt-3 max-w-lg text-lg text-hero-ink-soft">
                {t('home.closing.text')}
              </p>
              <div className="relative mt-7 flex flex-col items-center justify-center gap-3 sm:flex-row">
                <Link
                  href="/register"
                  className={`${linkStyles.outline} border-surface text-primary-hover`}
                >
                  {t('home.closing.create')}
                  <Icon name="open" size={18} />
                </Link>
                <Link
                  href="/login"
                  className="ui-control inline-flex min-h-11 items-center justify-center rounded-full border border-glass-line bg-glass px-5 text-md font-semibold text-surface focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-light"
                >
                  {t('home.closing.signIn')}
                </Link>
              </div>
            </Reveal>
          </section>
        </main>

        <SiteFooter
          t={t}
          locale={locale}
          onHome
          status={{ rows: [...rows], overall, time: health.time }}
        />
      </div>
    </I18nProvider>
  );
}
