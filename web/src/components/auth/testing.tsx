import { render } from '@testing-library/react';
import type { ReactElement } from 'react';
import { I18nProvider } from '@/lib/i18n/client';
import type { Locale } from '@/lib/i18n/locale';
import { getMessages } from '@/lib/i18n/messages';

/** Renders a client component of the sign-in pages with the whole catalogue of a language. */
export function renderIn(locale: Locale, ui: ReactElement) {
  return render(
    <I18nProvider locale={locale} messages={getMessages(locale)}>
      {ui}
    </I18nProvider>,
  );
}
