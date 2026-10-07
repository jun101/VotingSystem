/*
 * Reads the emails the stack sends from Mailpit's HTTP interface (the emails are queued and
 * sent by the `queue` service, so they arrive a moment after the page's answer).
 * Part of the acceptance harness.
 */

const MAILPIT = process.env.MAILPIT_URL ?? 'http://localhost:8025';

export type Mail = { subject: string; text: string; html: string };

/** An address nobody else uses, so tests in parallel never read each other's emails. */
export function uniqueEmail(prefix = 'person'): string {
  return `${prefix}.${Date.now().toString(36)}${Math.random().toString(36).slice(2, 8)}@example.test`;
}

async function messagesTo(address: string): Promise<{ ID: string; Subject: string }[]> {
  const response = await fetch(`${MAILPIT}/api/v1/search?query=${encodeURIComponent(`to:"${address}"`)}`);
  if (!response.ok) throw new Error(`Mailpit answered ${response.status}`);
  const body = (await response.json()) as { messages: { ID: string; Subject: string }[] };

  return body.messages ?? [];
}

/** Waits until `count` emails to this address have arrived; returns them oldest first. */
export async function waitForMails(address: string, count = 1, timeoutMs = 30_000): Promise<Mail[]> {
  const deadline = Date.now() + timeoutMs;

  for (;;) {
    const found = await messagesTo(address);
    if (found.length >= count) {
      const mails: Mail[] = [];
      for (const { ID } of [...found].reverse()) {
        const message = (await (await fetch(`${MAILPIT}/api/v1/message/${ID}`)).json()) as {
          Subject: string;
          Text: string;
          HTML: string;
        };
        mails.push({ subject: message.Subject, text: message.Text, html: message.HTML });
      }
      return mails;
    }
    if (Date.now() > deadline) throw new Error(`No ${count} email(s) to ${address} after ${timeoutMs} ms`);
    await new Promise((resolve) => setTimeout(resolve, 500));
  }
}

/**
 * The path and query of the link `…/{path}?token=<64 hex>` in an email, without the origin
 * (the link carries the address the stack knows itself by, which a test container may not
 * reach). Null when there is none.
 */
export function linkIn(mail: Mail, path: string): string | null {
  const pattern = new RegExp(`https?://[^\\s"<>]*?(${path.replace('/', '\\/')}\\?token=[0-9a-f]{64})(?![0-9a-f])`);
  const found = (mail.text.match(pattern) ?? mail.html.match(pattern))?.[1];

  return found ?? null;
}
