import { notFound } from 'next/navigation';
import { Button, Card, Input, PageShell, Pill } from '@/components/ui';
import { getI18n } from '@/lib/i18n/server';

export const dynamic = 'force-dynamic';

/** Every base component in every variant and state. Development only. */
export default async function ComponentsPage() {
  // Not available when the application runs in production mode.
  if (process.env.NODE_ENV === 'production') notFound();

  const { t } = await getI18n();

  return (
    <PageShell productName={t('app.name')}>
      <h1 className="text-3xl text-ink">{t('dev.components.title')}</h1>

      <Card title={t('dev.components.buttons')}>
        <div className="flex flex-wrap items-center gap-3">
          <Button data-testid="demo-button-pill">{t('dev.components.primary')}</Button>
          <Button shape="rounded" data-testid="demo-button-primary">
            {t('dev.components.primary')}
          </Button>
          <Button variant="secondary" data-testid="demo-button-secondary">
            {t('dev.components.secondary')}
          </Button>
          <Button variant="danger" data-testid="demo-button-danger">
            {t('dev.components.danger')}
          </Button>
          <Button variant="quiet" data-testid="demo-button-quiet">
            {t('dev.components.quiet')}
          </Button>
          <Button variant="accent" data-testid="demo-button-accent">
            {t('dev.components.accent')}
          </Button>
          <Button size="voter" data-testid="demo-button-voter">
            {t('dev.components.voter')}
          </Button>
          <Button disabled data-testid="demo-button-disabled">
            {t('dev.components.disabled')}
          </Button>
          <Button loading data-testid="demo-button-loading">
            {t('dev.components.loading')}
          </Button>
        </div>
      </Card>

      <Card title={t('dev.components.inputs')}>
        <div className="flex flex-col gap-4">
          <Input
            data-testid="demo-input"
            name="first_name"
            label={t('dev.components.inputLabel')}
            help={t('dev.components.inputHelp')}
          />
          <Input
            data-testid="demo-input-error"
            name="email"
            type="email"
            defaultValue="a@"
            label={t('dev.components.errorLabel')}
            error={t('dev.components.errorMessage')}
          />
        </div>
      </Card>

      <Card
        flat
        data-testid="demo-card"
        title={t('dev.components.cardTitle')}
        actions={<Button variant="quiet">{t('dev.components.cardAction')}</Button>}
      >
        <p className="text-ink-soft">{t('dev.components.cardText')}</p>
      </Card>

      <Card title={t('dev.components.pills')}>
        <div className="flex flex-wrap gap-3">
          <Pill tone="neutral" data-testid="demo-pill-neutral">
            {t('dev.components.neutral')}
          </Pill>
          <Pill tone="primary" data-testid="demo-pill-primary">
            {t('dev.components.tonePrimary')}
          </Pill>
          <Pill tone="teal" data-testid="demo-pill-teal">
            {t('dev.components.teal')}
          </Pill>
          <Pill tone="warm" data-testid="demo-pill-warm">
            {t('dev.components.warm')}
          </Pill>
          <Pill tone="danger" data-testid="demo-pill-danger">
            {t('dev.components.toneDanger')}
          </Pill>
        </div>
      </Card>
    </PageShell>
  );
}
