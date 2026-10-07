'use client';

import { createContext, useContext, useMemo, type ReactNode } from 'react';
import type { Locale } from './locale';
import {
  translate,
  translateIfAny,
  type MessageKey,
  type MessageParams,
  type Messages,
} from './messages';

type I18n = {
  locale: Locale;
  /** A message of the slice of the catalogue the page was given. */
  t: (key: MessageKey, params?: MessageParams) => string;
  /** A message whose key is built at run time (an error code): null when there is none. */
  tIfAny: (key: string, params?: MessageParams) => string | null;
};

const Context = createContext<I18n | null>(null);

/**
 * Gives the client components of a page their language and the part of the message
 * catalogue they use, chosen by the server, so a page ships only its own texts.
 */
export function I18nProvider({
  locale,
  messages,
  children,
}: {
  locale: Locale;
  messages: Partial<Messages>;
  children: ReactNode;
}) {
  const value = useMemo<I18n>(
    () => ({
      locale,
      t: (key, params) => translate(messages as Messages, key, params),
      tIfAny: (key, params) => translateIfAny(messages, key, params),
    }),
    [locale, messages],
  );

  return <Context.Provider value={value}>{children}</Context.Provider>;
}

export function useI18n(): I18n {
  const value = useContext(Context);

  if (!value) throw new Error('useI18n needs an I18nProvider');

  return value;
}
