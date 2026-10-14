import Image from 'next/image';
import Link from 'next/link';
import type { Metadata } from 'next';
import { Icon } from '@/components/admin/Icon';
import { HomeNav } from '@/components/home/HomeNav';
import { linkStyles } from '@/components/home/links';
import { SiteFooter } from '@/components/home/SiteFooter';
import { CountUp, Reveal } from '@/components/motion';
import { I18nProvider } from '@/lib/i18n/client';
import { getMessages, type Messages } from '@/lib/i18n/messages';
import { getI18n } from '@/lib/i18n/server';
import { DEVELOPER_LINKS } from '@/lib/public/developer';

// Product and technology names: the same in every language.
const TECHNOLOGIES = [
  'Laravel',
  'PHP',
  'FrankenPHP',
  'Next.js',
  'TypeScript',
  'Tailwind CSS',
  'MariaDB',
  'Redis',
  'Docker',
  'Debian',
  'WireGuard',
  'PostgreSQL',
  'MinIO',
  'CrowdSec',
  'GitHub Actions',
];

const SECTION = 'mx-auto w-full max-w-6xl px-4 pt-16 md:pt-20';
const EYEBROW = 'text-xs font-bold tracking-wide text-primary-hover uppercase';
const TITLE = 'mt-2 text-2xl text-ink md:text-3xl';

export async function generateMetadata(): Promise<Metadata> {
  const { t } = await getI18n();

  return { title: t('developer.meta.title'), description: t('developer.meta.description') };
}

export default async function DeveloperPage() {
  const { locale, t } = await getI18n();
  // Only the language switch is a client part of this page.
  const clientMessages = {
    vote: { language: getMessages(locale).vote.language },
  } as unknown as Partial<Messages>;

  const figures = [
    { key: 'years', value: 8, suffix: '+' },
    { key: 'uptime', value: 4, suffix: '' },
    { key: 'certs', value: 7, suffix: '' },
    { key: 'students', value: 500, suffix: '+' },
  ] as const;

  const promises = [
    { key: 'secret', tone: 'bg-surface text-ink' },
    { key: 'tested', tone: 'bg-band text-surface' },
    { key: 'install', tone: 'bg-hero text-surface' },
  ] as const;

  const stack = ['api', 'web', 'data', 'ops'] as const;
  const path = ['now', 'cert', 'work', 'teach'] as const;
  const langs = [
    { key: 'creole', share: 1 },
    { key: 'french', share: 0.95 },
    { key: 'english', share: 0.85 },
  ] as const;

  return (
    <I18nProvider locale={locale} messages={clientMessages}>
      <div id="top" className="min-h-screen bg-canvas">
        <HomeNav t={t} onHome={false} />

        <main>
          {/* ---- Hero: the name, the pitch, the portrait inside two slow rings ---- */}
          <section
            data-testid="developer-hero"
            className="relative overflow-hidden pt-12 pb-16 md:pt-16 md:pb-20"
          >
            <div
              aria-hidden="true"
              className="hero-light-a absolute -top-24 -right-20 hidden size-96 rounded-full bg-backdrop-from/20 blur-3xl md:block"
            />
            <div className="relative mx-auto grid w-full max-w-6xl items-center gap-12 px-4 lg:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)]">
              <div className="flex min-w-0 flex-col items-start gap-5">
                <p className={EYEBROW}>{t('developer.eyebrow')}</p>
                <h1 className="text-4xl text-ink md:text-[56px] md:leading-[60px]">
                  {t('developer.name')}
                </h1>
                <p className="font-display text-xl font-extrabold text-primary-hover md:text-2xl">
                  {t('developer.role')}
                </p>
                <p className="max-w-xl text-lg text-ink-soft md:text-xl">{t('developer.lede')}</p>
                <div className="flex flex-col gap-3 sm:flex-row">
                  <a
                    href={DEVELOPER_LINKS.linkedin}
                    rel="noopener noreferrer"
                    className={linkStyles.primary}
                  >
                    {t('developer.linkedin')}
                  </a>
                  <a
                    href={DEVELOPER_LINKS.github}
                    rel="noopener noreferrer"
                    className={linkStyles.outline}
                  >
                    {t('developer.github')}
                  </a>
                  <a
                    href={DEVELOPER_LINKS.site}
                    rel="noopener noreferrer"
                    className={linkStyles.outline}
                  >
                    {t('developer.site')}
                  </a>
                </div>
              </div>

              <div className="relative mx-auto aspect-square w-full max-w-[340px]">
                <div
                  aria-hidden="true"
                  className="dev-ring-outer absolute -inset-10 rounded-full"
                />
                <div aria-hidden="true" className="dev-ring absolute -inset-4 rounded-full" />
                <Image
                  src="/developer/josue.webp"
                  alt={t('developer.avatarAlt')}
                  width={480}
                  height={480}
                  priority
                  unoptimized
                  className="dev-portrait bg-hero relative size-full rounded-full object-cover shadow-3"
                />
                <p className="dev-badge absolute top-6 -left-4 rounded-md bg-surface px-3.5 py-2 text-base font-semibold text-ink shadow-3 sm:-left-10">
                  {t('developer.badgePlace')}
                </p>
                <p
                  className="dev-badge absolute right-0 bottom-10 rounded-md bg-surface px-3.5 py-2 text-base font-semibold text-ink shadow-3 sm:-right-8"
                  style={{ animationDelay: '-2s' }}
                >
                  {t('developer.badgeUptime')}
                </p>
              </div>
            </div>
          </section>

          {/* ---- The technologies, sliding ---- */}
          <section
            aria-label={t('developer.stackLabel')}
            className="overflow-hidden bg-surface py-5"
          >
            <div className="dev-marquee" aria-hidden="true">
              <div className="dev-marquee-track">
                {[...TECHNOLOGIES, ...TECHNOLOGIES].map((name, index) => (
                  <span
                    key={`${name}-${index}`}
                    className="rounded-full bg-primary-soft px-4 py-2 text-md font-semibold whitespace-nowrap text-primary-hover"
                  >
                    {name}
                  </span>
                ))}
              </div>
            </div>
            <ul className="sr-only">
              {TECHNOLOGIES.map((name) => (
                <li key={name}>{name}</li>
              ))}
            </ul>
          </section>

          {/* ---- Four figures ---- */}
          <section aria-labelledby="developer-figures-title" className="bg-surface pb-12">
            <h2 id="developer-figures-title" className="sr-only">
              {t('developer.figures.title')}
            </h2>
            <Reveal
              stagger
              className="mx-auto grid w-full max-w-6xl gap-y-8 px-4 pt-6 text-center md:grid-cols-4"
            >
              {figures.map((figure) => (
                <p key={figure.key} className="px-4">
                  <span className="block font-display text-4xl font-extrabold text-ink md:text-[52px] md:leading-[58px]">
                    <CountUp value={figure.value} suffix={figure.suffix} locale={locale} />
                  </span>
                  <span className="mt-1 block text-md font-semibold text-ink-soft">
                    {t(`developer.figures.${figure.key}`)}
                  </span>
                </p>
              ))}
            </Reveal>
          </section>

          {/* ---- Why: a quote and the story ---- */}
          <section aria-labelledby="developer-story-title" className={SECTION}>
            <Reveal className="grid items-start gap-8 lg:grid-cols-[0.9fr_1.1fr] lg:gap-16">
              <div>
                <p className={EYEBROW}>{t('developer.story.eyebrow')}</p>
                <h2 id="developer-story-title" className="mt-2 text-2xl text-ink md:text-3xl">
                  {t('developer.story.quote')}
                </h2>
              </div>
              <div className="flex flex-col gap-4 text-lg text-ink-soft">
                <p>{t('developer.story.p1')}</p>
                <p>{t('developer.story.p2')}</p>
              </div>
            </Reveal>
            <Reveal stagger className="mt-10 grid gap-5 md:grid-cols-3">
              {promises.map((item) => (
                <div key={item.key} className={`dev-lift rounded-xl p-6 shadow-1 ${item.tone}`}>
                  <h3 className="font-display text-xl font-extrabold">
                    {t(`developer.promises.${item.key}.title`)}
                  </h3>
                  <p className="mt-2 text-md opacity-85">
                    {t(`developer.promises.${item.key}.text`)}
                  </p>
                </div>
              ))}
            </Reveal>
          </section>

          {/* ---- The stack ---- */}
          <section aria-labelledby="developer-stack-title" className={SECTION}>
            <p className={EYEBROW}>{t('developer.stack.eyebrow')}</p>
            <h2 id="developer-stack-title" className={TITLE}>
              {t('developer.stack.title')}
            </h2>
            <Reveal stagger className="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
              {stack.map((key) => (
                <div key={key} className="dev-lift rounded-lg bg-surface p-5 shadow-1">
                  <h3 className="font-display text-lg font-extrabold text-ink">
                    {t(`developer.stack.${key}.title`)}
                  </h3>
                  <p className="mt-1 text-md text-ink-soft">{t(`developer.stack.${key}.text`)}</p>
                </div>
              ))}
            </Reveal>
          </section>

          {/* ---- The path, a line that draws itself ---- */}
          <section aria-labelledby="developer-path-title" className={SECTION}>
            <p className={EYEBROW}>{t('developer.path.eyebrow')}</p>
            <h2 id="developer-path-title" className={TITLE}>
              {t('developer.path.title')}
            </h2>
            <Reveal className="relative mt-8 ml-3 pl-9">
              <span
                aria-hidden="true"
                className="dev-line bg-hero absolute top-2 bottom-2 left-0 w-[3px] rounded-full"
              />
              <ol className="flex flex-col gap-4">
                {path.map((key) => (
                  <li key={key} className="relative rounded-lg bg-surface p-5 shadow-1">
                    <span
                      aria-hidden="true"
                      className="absolute top-6 -left-[44px] size-4 rounded-full border-4 border-primary bg-surface"
                    />
                    <p className="text-xs font-bold tracking-wide text-primary-hover uppercase">
                      {t(`developer.path.${key}.when`)}
                    </p>
                    <h3 className="mt-1 font-display text-lg font-extrabold text-ink">
                      {t(`developer.path.${key}.title`)}
                    </h3>
                    <p className="mt-1 text-md text-ink-soft">{t(`developer.path.${key}.text`)}</p>
                  </li>
                ))}
              </ol>
            </Reveal>
          </section>

          {/* ---- Languages ---- */}
          <section aria-labelledby="developer-langs-title" className={SECTION}>
            <h2 id="developer-langs-title" className={TITLE}>
              {t('developer.langs.title')}
            </h2>
            <Reveal stagger className="mt-6 grid gap-4 md:grid-cols-3">
              {langs.map((lang) => (
                <div key={lang.key} className="rounded-lg bg-surface p-5 shadow-1">
                  <p className="flex items-baseline justify-between gap-3">
                    <span className="font-display text-lg font-extrabold text-ink">
                      {t(`developer.langs.${lang.key}.name`)}
                    </span>
                    <span className="text-base text-ink-soft">
                      {t(`developer.langs.${lang.key}.level`)}
                    </span>
                  </p>
                  <div
                    aria-hidden="true"
                    className="mt-3 h-2 overflow-hidden rounded-full bg-primary-soft"
                  >
                    <div
                      className="dev-bar bg-hero h-full w-full rounded-full"
                      style={{ '--w': lang.share } as React.CSSProperties}
                    />
                  </div>
                </div>
              ))}
            </Reveal>
          </section>

          {/* ---- Contact ---- */}
          <section
            aria-labelledby="developer-contact-title"
            className={`${SECTION} pb-16 md:pb-20`}
          >
            <Reveal className="bg-hero grid items-center gap-8 rounded-2xl p-8 text-surface md:grid-cols-[1.2fr_1fr] md:p-12">
              <div>
                <h2 id="developer-contact-title" className="text-2xl text-surface md:text-3xl">
                  {t('developer.contact.title')}
                </h2>
                <p className="mt-2 text-lg text-hero-ink-soft">{t('developer.contact.text')}</p>
              </div>
              <ul className="flex flex-col gap-3">
                {[
                  {
                    href: `mailto:${t('developer.contact.email')}`,
                    label: t('developer.contact.email'),
                  },
                  { href: DEVELOPER_LINKS.linkedin, label: t('developer.linkedin') },
                  { href: DEVELOPER_LINKS.github, label: t('developer.github') },
                ].map((item) => (
                  <li key={item.href}>
                    <a
                      href={item.href}
                      rel="noopener noreferrer"
                      className="flex items-center justify-between gap-3 rounded-lg border border-glass-line bg-glass px-4 py-3 font-semibold text-surface transition-transform hover:translate-x-1.5 focus-visible:outline-2 focus-visible:outline-accent-light"
                    >
                      {item.label}
                      <Icon name="open" size={18} />
                    </a>
                  </li>
                ))}
              </ul>
            </Reveal>
            <p className="mt-6 text-center">
              <Link href="/" className={linkStyles.text}>
                {t('home.nav.home')}
              </Link>
            </p>
          </section>
        </main>

        <SiteFooter t={t} locale={locale} onHome={false} />
      </div>
    </I18nProvider>
  );
}
