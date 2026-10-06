import en from './messages/en.json';
import fr from './messages/fr.json';
import type { Locale } from './locale';

export type Messages = typeof fr;

type Paths<T, Prefix extends string = ''> = {
  [K in keyof T & string]: T[K] extends string ? `${Prefix}${K}` : Paths<T[K], `${Prefix}${K}.`>;
}[keyof T & string];

/** Dotted path of a message: `home.status.title`. */
export type MessageKey = Paths<Messages>;

const catalogs: Record<Locale, Messages> = { fr, en };

export function getMessages(locale: Locale): Messages {
  return catalogs[locale];
}

/** Looks a message up by its dotted path. A missing path gives the path itself. */
export function translate(messages: Messages, key: MessageKey): string {
  let node: unknown = messages;

  for (const part of key.split('.')) {
    if (typeof node !== 'object' || node === null) return key;
    node = (node as Record<string, unknown>)[part];
  }

  return typeof node === 'string' ? node : key;
}

export type Translate = (key: MessageKey) => string;
