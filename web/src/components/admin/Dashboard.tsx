'use client';

import Link from 'next/link';
import { Reveal } from '@/components/motion';
import { Card } from '@/components/ui';
import { useI18n } from '@/lib/i18n/client';
import type { MessageKey } from '@/lib/i18n/messages';
import { useAdminUser } from './AdminUser';
import { linkPrimary } from './classes';

type Block = {
  key: 'open-election' | 'todo' | 'figures' | 'activity' | 'latest';
  title: MessageKey;
  empty: MessageKey;
  action?: boolean;
};

/** The blocks the dashboard will hold; every one says honestly that it is empty. */
const BLOCKS: readonly Block[] = [
  {
    key: 'open-election',
    title: 'admin.dashboard.openElection.title',
    empty: 'admin.dashboard.openElection.empty',
    action: true,
  },
  { key: 'todo', title: 'admin.dashboard.todo.title', empty: 'admin.dashboard.todo.empty' },
  {
    key: 'figures',
    title: 'admin.dashboard.figures.title',
    empty: 'admin.dashboard.figures.empty',
  },
  {
    key: 'activity',
    title: 'admin.dashboard.activity.title',
    empty: 'admin.dashboard.activity.empty',
  },
  { key: 'latest', title: 'admin.dashboard.latest.title', empty: 'admin.dashboard.latest.empty' },
];

/** The empty dashboard (FR-NAV-01): a welcome and one card for each block it will hold. */
export function Dashboard() {
  const user = useAdminUser();
  const { t } = useI18n();

  return (
    <div data-testid="dashboard" className="flex flex-col gap-6">
      <section className="bg-hero rounded-lg p-6 text-surface md:p-8">
        <h2 data-testid="dashboard-welcome" className="text-2xl md:text-3xl">
          {t('admin.dashboard.welcome', { name: user.name })}
        </h2>
        {user.institution ? (
          <p data-testid="dashboard-institution" className="mt-1 font-display text-xl font-bold">
            {t('admin.dashboard.institution', { name: user.institution.name })}
          </p>
        ) : null}
        <p className="mt-2 max-w-2xl text-surface">{t('admin.dashboard.lede')}</p>
      </section>

      <Reveal stagger className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        {BLOCKS.map((block) => (
          <Card
            key={block.key}
            title={t(block.title)}
            data-testid={`dashboard-card-${block.key}`}
            className="flex flex-col"
          >
            <p className="text-ink-soft">{t(block.empty)}</p>
            {block.action ? (
              <Link
                href="/admin/elections/new"
                data-testid="dashboard-create-election"
                className={`${linkPrimary} mt-4 self-start`}
              >
                {t('admin.dashboard.create')}
              </Link>
            ) : null}
          </Card>
        ))}
      </Reveal>
    </div>
  );
}
