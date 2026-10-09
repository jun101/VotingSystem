import '@fontsource/archivo/latin-700.css';
import '@fontsource/archivo/latin-800.css';
import '@fontsource/roboto/latin-400.css';
import '@fontsource/roboto/latin-500.css';
import '@fontsource/roboto/latin-700.css';
import type { Metadata } from 'next';
import type { ReactNode } from 'react';
import { getI18n } from '@/lib/i18n/server';
import './globals.css';

export async function generateMetadata(): Promise<Metadata> {
  const { t } = await getI18n();

  return { title: t('app.name') };
}

export default async function RootLayout({ children }: { children: ReactNode }) {
  const { locale } = await getI18n();

  return (
    <html lang={locale}>
      <body>{children}</body>
    </html>
  );
}
