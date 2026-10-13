import type { Metadata } from 'next';
import Link from 'next/link';
import { notFound } from 'next/navigation';
import { GuideView } from '@/components/guide/GuideView';
import {
  allowedGuides,
  GUIDE_ROLES,
  guideRoleFor,
  type GuideRole,
} from '@/components/guide/guide-data';
import { PublicShell } from '@/components/public/PublicShell';
import { fetchCurrentUser } from '@/lib/api/server';
import { getGuide } from '@/lib/i18n/guide';
import { getI18n } from '@/lib/i18n/server';

export const dynamic = 'force-dynamic';

type Props = { params: Promise<{ role: string }> };

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { role } = await params;
  const { locale } = await getI18n();
  const catalog = getGuide(locale);

  return {
    title: GUIDE_ROLES.includes(role as GuideRole)
      ? `${catalog.ui.kicker} · ${catalog.roles[role as GuideRole].name}`
      : catalog.ui.kicker,
    robots: { index: false, follow: false },
  };
}

/**
 * A manual, public and without the admin frame. The voter's one is open to everybody; the others only to accounts that
 * have the tools they describe (a gate of the interface: the API enforces each tool).
 */
export default async function GuidePage({ params }: Props) {
  const { role } = await params;

  if (!GUIDE_ROLES.includes(role as GuideRole)) notFound();

  const [{ locale, t }, user] = await Promise.all([getI18n(), fetchCurrentUser()]);
  const catalog = getGuide(locale);
  const wanted = role as GuideRole;
  const reader = user && user.role !== undefined ? { role: user.role } : null;
  const allowed = allowedGuides(reader);
  const own = guideRoleFor(reader);
  const productName = t('app.name');

  if (!allowed.includes(wanted)) {
    return (
      <PublicShell
        testId="guide-denied"
        productName={productName}
        kicker={catalog.ui.kicker!}
        kickerIcon="book"
        title={catalog.ui.deniedTitle!}
        skip={catalog.ui.skip!}
      >
        <div className="rounded-lg border border-line bg-surface p-6 shadow-1">
          <p className="text-md text-ink">{catalog.ui.denied}</p>
          <Link
            href={`/guide/${own}`}
            data-testid="guide-open-mine"
            className="mt-4 inline-flex min-h-11 items-center rounded-full bg-primary px-5 font-bold text-surface"
          >
            {catalog.ui.mine}
          </Link>
        </div>
      </PublicShell>
    );
  }

  return (
    <GuideView
      role={wanted}
      allowed={allowed}
      catalog={catalog}
      productName={productName}
      own={own}
    />
  );
}
