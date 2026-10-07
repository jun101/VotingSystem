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

/** Values for the `{name}` placeholders of a message. */
export type MessageParams = Record<string, string | number>;

/** The text at a dotted path, or null when there is none (or it is not a text). */
export function lookup(messages: unknown, key: string): string | null {
  let node: unknown = messages;

  for (const part of key.split('.')) {
    if (typeof node !== 'object' || node === null) return null;
    node = (node as Record<string, unknown>)[part];
  }

  return typeof node === 'string' ? node : null;
}

function fill(text: string, params?: MessageParams): string {
  if (!params) return text;

  return text.replace(/\{(\w+)\}/g, (placeholder, name: string) =>
    name in params ? String(params[name]) : placeholder,
  );
}

/**
 * Looks a message up by its dotted path and fills its `{name}` placeholders. A missing path
 * gives the path itself.
 */
export function translate(messages: Messages, key: MessageKey, params?: MessageParams): string {
  const text = lookup(messages, key);

  return text === null ? key : fill(text, params);
}

/** The same for a key built at run time (an error code): null when no message exists. */
export function translateIfAny(
  messages: unknown,
  key: string,
  params?: MessageParams,
): string | null {
  const text = lookup(messages, key);

  return text === null ? null : fill(text, params);
}

export type Translate = (key: MessageKey, params?: MessageParams) => string;

/**
 * The part of the catalogue a page's client components use, by top-level name
 * (`pick(messages, 'auth', 'errors')`): the page ships those texts and no others.
 */
export function pick<K extends keyof Messages>(
  messages: Messages,
  ...names: K[]
): Pick<Messages, K> {
  const part = {} as Pick<Messages, K>;

  for (const name of names) part[name] = messages[name];

  return part;
}
