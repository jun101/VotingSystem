import Link from 'next/link';
import { headers } from 'next/headers';
import { Icon, type IconName } from '@/components/admin/Icon';
import { HomeNav } from '@/components/home/HomeNav';
import { LiveBallot } from '@/components/home/LiveBallot';
import { linkStyles } from '@/components/home/links';
import { VoterDoor } from '@/components/home/VoterDoor';
import { LiveDot, Reveal } from '@/components/motion';
import { LogoMark, Pill } from '@/components/ui';
import { fetchHealthCached } from '@/lib/api/health';
import { formatDateTime } from '@/lib/format/dateTime';
import { I18nProvider } from '@/lib/i18n/client';
import { getI18n } from '@/lib/i18n/server';
import { getMessages, type Messages } from '@/lib/i18n/messages';

// Read at each visit: the page shows the state of the system now.
export const dynamic = 'force-dynamic';

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

  const pill = {
    ok: { tone: 'teal', text: t('home.status.online') },
    down: { tone: 'danger', text: t('home.status.offline') },
    unknown: { tone: 'neutral', text: t('home.status.unknown') },
  } as const;

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

  const steps = [
    { key: 'import', badge: 'bg-hero-mid text-surface' },
    { key: 'codes', badge: 'bg-teal text-surface' },
    { key: 'vote', badge: 'bg-primary text-surface' },
    { key: 'results', badge: 'bg-accent text-deep' },
  ] as const;

  const why: { key: 'secret' | 'slips' | 'live' | 'results'; icon: IconName; tone: string }[] = [
    { key: 'secret', icon: 'eye', tone: 'bg-primary-soft text-primary-hover' },
    { key: 'slips', icon: 'qr', tone: 'bg-teal-soft text-teal-ink' },
    { key: 'live', icon: 'chart', tone: 'bg-warm-soft text-warm-ink' },
    { key: 'results', icon: 'flag', tone: 'bg-status-published-soft text-status-published' },
  ];

  const figures = ['one', 'zero', 'two', 'full'] as const;
  const faq = ['account', 'phone', 'tie', 'results'] as const;

  return (
    <I18nProvider locale={locale} messages={clientMessages}>
      <div id="top" className="min-h-screen bg-canvas">
        <HomeNav t={t} />

        <main>
          {/* ---- Hero: light, one live ballot ---- */}
          <section
            data-testid="home-hero"
            data-live="true"
            className="relative overflow-hidden pt-12 pb-20 md:pt-16 md:pb-24"
          >
            <div
              aria-hidden="true"
              className="hero-light-a absolute -top-24 -right-20 size-96 rounded-full bg-backdrop-from/20 blur-3xl"
            />
            <div
              aria-hidden="true"
              className="hero-light-b absolute -bottom-28 -left-24 size-96 rounded-full bg-primary/20 blur-3xl"
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
                  <a href="#how" className={linkStyles.outline}>
                    {t('home.hero.how')}
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

              <div className="mx-auto w-full max-w-md">
                <LiveBallot />
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
                className="flex min-w-0 flex-col gap-4 border-t border-line-soft p-5 md:border-t-0 md:border-l sm:flex-row sm:p-7"
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

          {/* ---- Four steps: a path, not cards ---- */}
          <section id="how" aria-labelledby="home-how-title" className={`${SECTION} scroll-mt-20`}>
            <div className="text-center">
              <p className={EYEBROW}>{t('home.steps.eyebrow')}</p>
              <h2 id="home-how-title" className={TITLE}>
                {t('home.steps.title')}
              </h2>
            </div>
            <Reveal
              stagger
              className="relative mt-10 grid gap-8 md:grid-cols-2 lg:grid-cols-4 lg:gap-6"
            >
              <div
                aria-hidden="true"
                className="absolute top-7 right-[12.5%] left-[12.5%] hidden border-t-2 border-dashed border-primary-line lg:block"
              />
              {steps.map((step, index) => (
                <div key={step.key} className="relative min-w-0 px-2 text-center">
                  <span
                    aria-hidden="true"
                    className={`mx-auto mb-3.5 flex size-14 items-center justify-center rounded-full font-display text-2xl font-extrabold ring-[6px] ring-canvas ${step.badge}`}
                  >
                    {index + 1}
                  </span>
                  <h3 className="font-display text-xl font-extrabold text-ink">
                    {t(`home.steps.${step.key}.title`)}
                  </h3>
                  <p className="mt-1 text-md text-ink-soft">{t(`home.steps.${step.key}.text`)}</p>
                </div>
              ))}
            </Reveal>
          </section>

          {/* ---- Why us: a split, an open list ---- */}
          <section
            id="security"
            aria-labelledby="home-why-title"
            className={`${SECTION} scroll-mt-20`}
          >
            <Reveal className="grid items-start gap-10 lg:grid-cols-[0.9fr_1.1fr] lg:gap-16">
              <div className="flex flex-col items-start">
                <p className={EYEBROW}>{t('home.why.eyebrow')}</p>
                <h2 id="home-why-title" className={TITLE}>
                  {t('home.why.title')}
                </h2>
                <p className="mt-3 max-w-md text-lg text-ink-soft">{t('home.why.lead')}</p>
                <Link href="/register" className={`${linkStyles.primary} mt-6`}>
                  {t('home.hero.create')}
                </Link>
              </div>

              <ul className="grid gap-7 sm:grid-cols-2 sm:gap-x-9">
                {why.map((item) => (
                  <li key={item.key} className="flex min-w-0 items-start gap-3.5">
                    <span
                      aria-hidden="true"
                      className={`flex size-11 shrink-0 items-center justify-center rounded-md ${item.tone}`}
                    >
                      <Icon name={item.icon} size={22} />
                    </span>
                    <div className="min-w-0">
                      <h3 className="font-display text-lg font-extrabold text-ink">
                        {t(`home.why.${item.key}.title`)}
                      </h3>
                      <p className="mt-0.5 text-md text-ink-soft">
                        {t(`home.why.${item.key}.text`)}
                      </p>
                    </div>
                  </li>
                ))}
              </ul>
            </Reveal>
          </section>

          {/* ---- Four true figures, separated by lines, not tiles ---- */}
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
                    {t(`home.figures.${key}.value`)}
                  </span>
                  <span className="mt-1 block text-md font-semibold text-surface">
                    {t(`home.figures.${key}.label`)}
                  </span>
                </p>
              ))}
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
            <Reveal className="text-center">
              <h2 id="home-closing-title" className="text-2xl text-ink md:text-3xl">
                {t('home.closing.title')}
              </h2>
              <div className="mt-6 flex flex-col items-center justify-center gap-3 sm:flex-row">
                <Link href="/register" className={linkStyles.primary}>
                  {t('home.closing.create')}
                </Link>
                <Link href="/login" className={linkStyles.outline}>
                  {t('home.closing.signIn')}
                </Link>
              </div>
            </Reveal>
          </section>
        </main>

        {/* ---- Footer: the product, the links, the contact and the state of the system. Not behind a reveal. ---- */}
        <footer className="bg-deep text-surface">
          <div className="mx-auto grid w-full max-w-6xl gap-8 px-4 pt-10 pb-6 md:grid-cols-[1.4fr_1fr_1fr_1fr]">
            <div className="flex flex-col items-start gap-3">
              <p className="flex items-center gap-2.5 font-display text-lg font-extrabold text-surface">
                <LogoMark size={32} />
                {t('app.name')}
              </p>
              <p className="max-w-xs text-base text-hero-ink-soft">{t('home.footer.tagline')}</p>
              <p
                data-testid="home-status-chip"
                data-state={overall}
                className="inline-flex items-center gap-2 rounded-full border border-glass-line bg-glass px-3 py-1.5 text-sm font-semibold"
              >
                <LiveDot
                  className={overall === 'overallOk' ? 'text-accent-light' : 'text-accent'}
                />
                {t(`home.status.${overall}`)}
              </p>
            </div>

            <nav aria-labelledby="home-footer-product">
              <h3 id="home-footer-product" className="text-base text-surface">
                {t('home.footer.product')}
              </h3>
              <ul className="mt-2 flex flex-col gap-2">
                <li>
                  <a href="#how" className={linkStyles.footer}>
                    {t('home.nav.how')}
                  </a>
                </li>
                <li>
                  <a href="#security" className={linkStyles.footer}>
                    {t('home.nav.security')}
                  </a>
                </li>
                <li>
                  <a href="#faq" className={linkStyles.footer}>
                    {t('home.nav.faq')}
                  </a>
                </li>
              </ul>
            </nav>

            <nav aria-labelledby="home-footer-account">
              <h3 id="home-footer-account" className="text-base text-surface">
                {t('home.footer.account')}
              </h3>
              <ul className="mt-2 flex flex-col gap-2">
                <li>
                  <Link href="/login" className={linkStyles.footer}>
                    {t('home.organiser.signIn')}
                  </Link>
                </li>
                <li>
                  <Link href="/register" className={linkStyles.footer}>
                    {t('home.organiser.register')}
                  </Link>
                </li>
              </ul>
            </nav>

            <div>
              <h3 className="text-base text-surface">{t('home.footer.contact')}</h3>
              <ul className="mt-2 flex flex-col gap-2">
                <li>
                  <a href={`mailto:${t('home.footer.email')}`} className={linkStyles.footer}>
                    {t('home.footer.email')}
                  </a>
                </li>
              </ul>
            </div>
          </div>

          <div className="mx-auto w-full max-w-6xl px-4">
            <section
              aria-labelledby="home-status-title"
              className="flex flex-col gap-3 border-t border-glass-line py-4 md:flex-row md:items-center md:justify-between"
            >
              <h2
                id="home-status-title"
                className="text-sm font-bold tracking-wide text-hero-ink-soft uppercase"
              >
                {t('home.status.title')}
              </h2>
              <dl className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center sm:gap-5">
                {rows.map((row) => (
                  <div
                    key={row.id}
                    data-testid={`status-${row.id}`}
                    data-state={row.state}
                    className="flex items-center justify-between gap-3"
                  >
                    <dt className="text-base text-hero-ink-soft">{row.label}</dt>
                    <dd>
                      <Pill tone={pill[row.state].tone}>{pill[row.state].text}</Pill>
                    </dd>
                  </div>
                ))}
                <div className="flex items-center justify-between gap-3">
                  <dt className="text-base text-hero-ink-soft">{t('home.status.time')}</dt>
                  <dd className="text-base font-semibold text-surface">
                    {health.time ? (
                      <time data-testid="status-time" dateTime={health.time}>
                        {formatDateTime(health.time, locale)}
                      </time>
                    ) : (
                      <span>—</span>
                    )}
                  </dd>
                </div>
              </dl>
            </section>

            <p className="border-t border-glass-line py-4 text-sm text-hero-ink-soft">
              {t('home.footer.copy', { year: new Date().getFullYear() })}
            </p>
          </div>
        </footer>
      </div>
    </I18nProvider>
  );
}
