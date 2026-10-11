import Link from 'next/link';
import { headers } from 'next/headers';
import { Icon, type IconName } from '@/components/admin/Icon';
import { HeroArt } from '@/components/home/HeroArt';
import { HomeCard } from '@/components/home/HomeCard';
import { HomeNav } from '@/components/home/HomeNav';
import { ImageSlot } from '@/components/home/ImageSlot';
import { linkStyles } from '@/components/home/links';
import { VoterDoor } from '@/components/home/VoterDoor';
import { LiveDot, Reveal } from '@/components/motion';
import { LogoMark, Pill } from '@/components/ui';
import type { HeadTone } from '@/components/vote/VoteCard';
import { fetchHealthCached } from '@/lib/api/health';
import { formatDateTime } from '@/lib/format/dateTime';
import { I18nProvider } from '@/lib/i18n/client';
import { getI18n } from '@/lib/i18n/server';
import { getMessages, type Messages } from '@/lib/i18n/messages';

// Read at each visit: the page shows the state of the system now.
export const dynamic = 'force-dynamic';

const SECTION = 'mx-auto w-full max-w-6xl px-4 pt-12 md:pt-16';
const EYEBROW = 'text-xs font-bold tracking-wide text-primary-hover uppercase';
const TITLE = 'mt-2 text-2xl text-ink md:text-3xl';

export default async function HomePage() {
  const { locale, t } = await getI18n();
  const forwardedFor = (await headers()).get('x-forwarded-for');
  const health = await fetchHealthCached({ forwardedFor });
  const all = getMessages(locale);
  // The client parts of the page (the voter's field, the language switch) ship only these texts.
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

  const features: {
    key: 'secret' | 'slips' | 'live' | 'results';
    icon: IconName;
    tone: HeadTone;
  }[] = [
    { key: 'secret', icon: 'lock', tone: 'activity' },
    { key: 'slips', icon: 'qr', tone: 'figures' },
    { key: 'live', icon: 'chart', tone: 'latest' },
    { key: 'results', icon: 'flag', tone: 'todo' },
  ];

  const offers: { key: 'import' | 'codes' | 'page' | 'results'; icon: IconName; tone: HeadTone }[] =
    [
      { key: 'import', icon: 'people', tone: 'latest' },
      { key: 'codes', icon: 'mail', tone: 'activity' },
      { key: 'page', icon: 'phone', tone: 'figures' },
      { key: 'results', icon: 'chart', tone: 'todo' },
    ];

  const steps = [
    { key: 'import', badge: 'bg-hero-mid text-surface' },
    { key: 'codes', badge: 'bg-teal text-surface' },
    { key: 'vote', badge: 'bg-primary text-surface' },
    { key: 'results', badge: 'bg-accent text-deep' },
  ] as const;

  const trust: { key: 'secret' | 'once' | 'languages' | 'audit'; icon: IconName; tone: string }[] =
    [
      { key: 'secret', icon: 'eye', tone: 'bg-primary-soft text-primary-hover' },
      { key: 'once', icon: 'check', tone: 'bg-teal-soft text-teal-ink' },
      { key: 'languages', icon: 'lang', tone: 'bg-warm-soft text-warm-ink' },
      { key: 'audit', icon: 'log', tone: 'bg-status-published-soft text-status-published' },
    ];

  const faq = ['account', 'phone', 'tie', 'results'] as const;

  return (
    <I18nProvider locale={locale} messages={clientMessages}>
      <div id="top" className="min-h-screen bg-canvas">
        <div className="relative">
          <HomeNav t={t} />

          <main>
            {/* ---- Hero ---- */}
            <section
              data-testid="home-hero"
              data-live="true"
              className="bg-hero relative overflow-hidden pt-28 pb-40 text-surface md:pt-32 md:pb-44"
            >
              <div
                aria-hidden="true"
                className="hero-light hero-light-a -top-24 -right-16 size-96"
              />
              <div
                aria-hidden="true"
                className="hero-light hero-light-b -bottom-32 left-1/4 size-80"
              />

              <div className="relative mx-auto grid w-full max-w-6xl items-center gap-8 px-4 lg:grid-cols-[minmax(0,1.05fr)_minmax(0,1fr)] lg:gap-10">
                <div className="flex min-w-0 flex-col items-start gap-5">
                  <span className="inline-flex items-center gap-2 rounded-full border border-glass-line bg-glass px-3.5 py-1.5 text-sm font-semibold text-surface">
                    <LiveDot className="text-accent-light" />
                    {t('home.hero.pill')}
                  </span>
                  <h1
                    data-testid="home-hero-title"
                    className="text-4xl text-surface md:text-[44px] md:leading-[50px]"
                  >
                    <span className="sr-only">{t('app.name')}</span> {t('home.hero.title')}
                    <br />
                    <span className="accent-word">{t('home.hero.accent')}</span>
                  </h1>
                  <p className="max-w-xl text-lg text-hero-ink-soft">{t('home.hero.lede')}</p>
                  <div className="flex flex-col gap-3 sm:flex-row">
                    <Link
                      href="/register"
                      data-testid="home-hero-create"
                      className={linkStyles.white}
                    >
                      {t('home.hero.create')}
                    </Link>
                    <a href="#how" className={linkStyles.glass}>
                      {t('home.hero.how')}
                    </a>
                  </div>
                </div>

                <HeroArt t={t} />
              </div>
            </section>

            {/* ---- The two doors, over the hero's bottom edge ---- */}
            <section className="relative z-10 mx-auto -mt-24 w-full max-w-6xl px-4">
              <div className="rise-stagger grid gap-5 md:grid-cols-2">
                <div
                  data-testid="home-voter-door"
                  className="flex min-w-0 flex-col gap-4 rounded-xl bg-surface p-5 shadow-3 sm:flex-row sm:p-6"
                >
                  <span
                    aria-hidden="true"
                    className="flex size-16 shrink-0 items-center justify-center rounded-lg bg-primary-soft text-primary"
                  >
                    <Icon name="lock" size={34} />
                  </span>
                  <div className="min-w-0 flex-1">
                    <h2 className="text-xl text-ink md:text-2xl">{t('home.voter.title')}</h2>
                    <p className="mt-1 mb-3 text-md text-ink-soft">{t('home.voter.text')}</p>
                    <VoterDoor />
                  </div>
                </div>

                <div
                  data-testid="home-organiser-door"
                  className="flex min-w-0 flex-col gap-4 rounded-xl bg-surface p-5 shadow-3 sm:flex-row sm:p-6"
                >
                  <span
                    aria-hidden="true"
                    className="flex size-16 shrink-0 items-center justify-center rounded-lg bg-teal-soft text-teal"
                  >
                    <Icon name="ballot" size={34} />
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
                        className={linkStyles.soft}
                      >
                        {t('home.organiser.register')}
                      </Link>
                    </div>
                  </div>
                </div>
              </div>
            </section>

            {/* ---- Four reasons ---- */}
            <section aria-labelledby="home-features-title" className={SECTION}>
              <h2 id="home-features-title" className="sr-only">
                {t('home.features.title')}
              </h2>
              <Reveal stagger className="grid gap-4 lg:grid-cols-4 sm:grid-cols-2">
                {features.map((item) => (
                  <HomeCard
                    key={item.key}
                    tone={item.tone}
                    icon={item.icon}
                    head={t(`home.features.${item.key}.head`)}
                  >
                    <p className="font-display text-lg font-extrabold text-ink">
                      {t(`home.features.${item.key}.title`)}
                    </p>
                    <p>{t(`home.features.${item.key}.text`)}</p>
                  </HomeCard>
                ))}
              </Reveal>
            </section>

            {/* ---- About ---- */}
            <section aria-labelledby="home-about-title" className={SECTION}>
              <Reveal className="grid items-center gap-6 lg:grid-cols-[1.1fr_1fr] lg:gap-8">
                <div className="flex flex-col items-start">
                  <p className={EYEBROW}>{t('home.about.eyebrow')}</p>
                  <h2 id="home-about-title" className={TITLE}>
                    {t('home.about.title')}
                  </h2>
                  <p className="mt-3 max-w-xl text-lg text-ink-soft">{t('home.about.lead')}</p>
                  <a href="#security" className={`${linkStyles.primary} mt-5`}>
                    {t('home.about.more')}
                  </a>
                </div>
                <ImageSlot placeholder={t('home.about.image')}>
                  <p className="rounded-md bg-surface px-4 py-3 shadow-3">
                    <span className="block font-display text-2xl font-extrabold text-primary-hover">
                      {t('home.about.statFigure')}
                    </span>
                    <span className="text-base font-semibold text-ink">
                      {t('home.about.statText')}
                    </span>
                  </p>
                </ImageSlot>
              </Reveal>
            </section>

            {/* ---- What we offer, then the dark band ---- */}
            <section aria-labelledby="home-offer-title" className={SECTION}>
              <p className={EYEBROW}>{t('home.offer.eyebrow')}</p>
              <h2 id="home-offer-title" className={TITLE}>
                {t('home.offer.title')}
              </h2>
              <Reveal stagger className="mt-6 grid gap-4 lg:grid-cols-4 sm:grid-cols-2">
                {offers.map((item) => (
                  <HomeCard
                    key={item.key}
                    tone={item.tone}
                    icon={item.icon}
                    head={t(`home.offer.${item.key}.title`)}
                  >
                    <p>{t(`home.offer.${item.key}.text`)}</p>
                  </HomeCard>
                ))}
              </Reveal>

              <Reveal className="bg-band relative mt-12 grid items-center gap-5 overflow-hidden rounded-xl p-6 text-surface md:mt-16 md:grid-cols-[1.3fr_1fr] md:p-10">
                <div aria-hidden="true" className="hero-light -top-16 -right-10 size-72" />
                <div className="relative flex flex-col items-start gap-3">
                  <h2 className="text-2xl text-surface md:text-3xl">{t('home.band.title')}</h2>
                  <p className="text-lg text-hero-ink-soft">{t('home.band.text')}</p>
                  <Link href="/register" className={`${linkStyles.white} mt-1`}>
                    {t('home.band.action')}
                  </Link>
                </div>
                <p className="relative flex items-center gap-2 justify-self-start rounded-md bg-surface px-4 py-3 text-base font-semibold text-ink shadow-3 md:justify-self-end">
                  <span
                    aria-hidden="true"
                    className="flex size-6 items-center justify-center rounded-full bg-teal text-surface"
                  >
                    <Icon name="check" size={14} />
                  </span>
                  {t('home.band.chip')}
                </p>
              </Reveal>
            </section>

            {/* ---- Four steps ---- */}
            <section id="how" aria-labelledby="home-how-title" className={`${SECTION} scroll-mt-4`}>
              <p className={EYEBROW}>{t('home.steps.eyebrow')}</p>
              <h2 id="home-how-title" className={TITLE}>
                {t('home.steps.title')}
              </h2>
              <Reveal stagger className="mt-6 grid gap-4 lg:grid-cols-4 sm:grid-cols-2">
                {steps.map((step, index) => (
                  <article
                    key={step.key}
                    className="min-w-0 rounded-lg border border-line bg-surface p-5 shadow-2"
                  >
                    <span
                      aria-hidden="true"
                      className={`mb-3 flex size-11 items-center justify-center rounded-full font-display text-xl font-extrabold ${step.badge}`}
                    >
                      {index + 1}
                    </span>
                    <h3 className="font-display text-lg font-extrabold text-ink">
                      {t(`home.steps.${step.key}.title`)}
                    </h3>
                    <p className="mt-1 text-md text-ink-soft">{t(`home.steps.${step.key}.text`)}</p>
                  </article>
                ))}
              </Reveal>
            </section>

            {/* ---- Trust and questions ---- */}
            <section
              id="security"
              aria-labelledby="home-trust-title"
              className={`${SECTION} scroll-mt-4`}
            >
              <p className={EYEBROW}>{t('home.trust.eyebrow')}</p>
              <h2 id="home-trust-title" className={TITLE}>
                {t('home.trust.title')}
              </h2>
              <Reveal stagger className="mt-6 grid gap-4 lg:grid-cols-4 sm:grid-cols-2">
                {trust.map((item) => (
                  <article
                    key={item.key}
                    className="flex min-w-0 items-start gap-3 rounded-lg border border-line bg-surface p-5 shadow-1"
                  >
                    <span
                      aria-hidden="true"
                      className={`flex size-11 shrink-0 items-center justify-center rounded-md ${item.tone}`}
                    >
                      <Icon name={item.icon} size={22} />
                    </span>
                    <div className="min-w-0">
                      <h3 className="font-display text-lg font-extrabold text-ink">
                        {t(`home.trust.${item.key}.title`)}
                      </h3>
                      <p className="text-base text-ink-soft">{t(`home.trust.${item.key}.text`)}</p>
                    </div>
                  </article>
                ))}
              </Reveal>

              <div id="faq" className="mt-4 scroll-mt-4">
                <Reveal stagger className="grid gap-4 md:grid-cols-2">
                  {faq.map((key) => (
                    <article
                      key={key}
                      className="min-w-0 rounded-md border border-line bg-surface px-5 py-4 shadow-1"
                    >
                      <h3 className="font-display text-lg font-extrabold text-ink">
                        {t(`home.faq.${key}.q`)}
                      </h3>
                      <p className="mt-1 text-md text-ink-soft">{t(`home.faq.${key}.a`)}</p>
                    </article>
                  ))}
                </Reveal>
              </div>
            </section>

            {/* ---- Closing band ---- */}
            <section aria-labelledby="home-closing-title" className={`${SECTION} pb-12 md:pb-16`}>
              <Reveal className="bg-hero rounded-xl px-6 py-10 text-center text-surface md:p-12">
                <h2 id="home-closing-title" className="text-2xl text-surface md:text-3xl">
                  {t('home.closing.title')}
                </h2>
                <div className="mt-5 flex flex-col items-center justify-center gap-3 sm:flex-row">
                  <Link href="/register" className={linkStyles.white}>
                    {t('home.closing.create')}
                  </Link>
                  <Link href="/login" className={linkStyles.glass}>
                    {t('home.closing.signIn')}
                  </Link>
                </div>
              </Reveal>
            </section>
          </main>
        </div>

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
