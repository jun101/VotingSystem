import { PublicUnavailable } from '@/components/public/Unavailable';
import { getI18n } from '@/lib/i18n/server';

/** Not published, unknown, malformed, archived or suspended: one page, one answer (HTTP 404). */
export default async function ResultsNotAvailable() {
  const { t } = await getI18n();

  return <PublicUnavailable t={t} />;
}
