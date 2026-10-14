import type { ComponentProps } from 'react';
import { Card } from '@/components/ui';

/** The card, or just its content when an account card around it already has the header. */
export function CardShell({
  plain,
  title,
  children,
  ...rest
}: Omit<ComponentProps<'section'>, 'title'> & { plain?: boolean; title?: string }) {
  return plain ? (
    <section {...rest}>{children}</section>
  ) : (
    <Card title={title} {...rest}>
      {children}
    </Card>
  );
}
