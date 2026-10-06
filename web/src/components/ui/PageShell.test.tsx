import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { PageShell } from './PageShell';

describe('PageShell', () => {
  it('shows the product name as a link home, and the page in the main area', () => {
    render(
      <PageShell productName="New Voting System">
        <p>Page</p>
      </PageShell>,
    );

    expect(screen.getByRole('link', { name: 'New Voting System' })).toHaveAttribute('href', '/');
    expect(screen.getByRole('main')).toHaveTextContent('Page');
  });

  it('does not take a heading: the page has its own', () => {
    render(<PageShell productName="New Voting System">x</PageShell>);

    expect(screen.queryByRole('heading')).not.toBeInTheDocument();
  });

  it('is plain by default and names the area when given one', () => {
    const { container, rerender } = render(<PageShell productName="N">x</PageShell>);
    expect(container.firstElementChild).toHaveAttribute('data-variant', 'plain');

    rerender(
      <PageShell productName="N" variant="voter">
        x
      </PageShell>,
    );
    expect(container.firstElementChild).toHaveAttribute('data-variant', 'voter');
  });

  it('sits on the canvas colour', () => {
    const { container } = render(<PageShell productName="N">x</PageShell>);

    expect(container.firstElementChild).toHaveClass('bg-canvas');
  });
});
