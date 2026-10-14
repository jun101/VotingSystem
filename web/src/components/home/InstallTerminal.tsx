'use client';

import { useEffect, useRef, useState } from 'react';
import { useI18n } from '@/lib/i18n/client';

const KEYS = ['l1', 'l2', 'l3', 'l4', 'l5', 'l6', 'l7'] as const;

/**
 * An example of the installer's output that types itself when it comes into view. A picture of
 * what the installer prints, not a real terminal. The whole text is in the page from the start
 * for a screen reader and for a visitor who asked for less motion.
 */
export function InstallTerminal() {
  const { t } = useI18n();
  const root = useRef<HTMLDivElement>(null);
  const lines = KEYS.map((key) => t(`home.install.terminal.${key}`));
  const [typed, setTyped] = useState<string[] | null>(null);

  useEffect(() => {
    const element = root.current;

    if (!element || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    if (typeof IntersectionObserver === 'undefined') return;

    let timer = 0;
    const observer = new IntersectionObserver((entries) => {
      if (!entries.some((entry) => entry.isIntersecting)) return;

      observer.disconnect();

      let line = 0;
      let char = 0;
      const out: string[] = [''];

      setTyped([...out]);

      const step = () => {
        const text = lines[line] ?? '';

        char += 1;
        out[line] = text.slice(0, char);
        setTyped([...out]);

        if (char >= text.length) {
          line += 1;
          char = 0;

          if (line >= lines.length) return;

          out.push('');
          timer = window.setTimeout(step, 260);
        } else {
          timer = window.setTimeout(step, 22);
        }
      };

      timer = window.setTimeout(step, 200);
    });

    observer.observe(element);

    return () => {
      observer.disconnect();
      window.clearTimeout(timer);
    };
    // The lines come from the page's language, fixed for the life of this component.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const shown = typed ?? lines;

  return (
    <div
      ref={root}
      role="img"
      aria-label={t('home.install.terminal.label')}
      className="min-h-64 rounded-xl bg-night p-5 font-mono text-base leading-8 text-line shadow-3"
    >
      <div aria-hidden="true" className="mb-3 flex gap-2">
        <i className="size-3 rounded-full bg-accent" />
        <i className="size-3 rounded-full bg-accent-light" />
        <i className="size-3 rounded-full bg-teal-soft" />
      </div>
      <div aria-hidden="true">
        {shown.map((text, index) => (
          <div key={index} className={text.startsWith('✓') ? 'text-teal-soft' : undefined}>
            {text}
          </div>
        ))}
        <span className="home-caret inline-block h-4 w-2 bg-line align-middle" />
      </div>
    </div>
  );
}
