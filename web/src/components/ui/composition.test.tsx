import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { Button } from './Button';
import { Band } from './Band';
import { Hero } from './Hero';
import { PageShell } from './PageShell';

describe('Hero', () => {
  it('holds the page title as the one h1, with the accent word inside it', () => {
    render(<Hero title="New Voting System" accent="Voting" data-testid="h" />);

    const title = screen.getByRole('heading', { level: 1, name: 'New Voting System' });
    expect(title).toHaveAttribute('data-testid', 'h-title');
    expect(title.querySelector('.accent-word')).toHaveTextContent('Voting');
  });

  it('keeps only the title and one line when compact', () => {
    render(<Hero compact title="T" lede="One line" label="Label" figures={<b>fig</b>} />);

    expect(screen.getByText('One line')).toBeInTheDocument();
    expect(screen.queryByText('Label')).not.toBeInTheDocument();
    expect(screen.queryByText('fig')).not.toBeInTheDocument();
  });

  it('lets the lights drift only when live', () => {
    const { container, rerender } = render(<Hero title="T" />);
    expect(container.firstElementChild).toHaveAttribute('data-live', 'false');

    rerender(<Hero title="T" live />);
    expect(container.firstElementChild).toHaveAttribute('data-live', 'true');
  });
});

describe('Button accent', () => {
  it('uses the accent gradient with deep navy text, never white', () => {
    render(<Button variant="accent">Go</Button>);

    expect(screen.getByRole('button', { name: 'Go' })).toHaveClass(
      'bg-accent-gradient',
      'text-navy-deep',
    );
  });
});

describe('Band', () => {
  it('shows its figure in the light accent', () => {
    render(<Band name="Name" figure="64 %" data-testid="b" />);

    expect(screen.getByTestId('b-figure')).toHaveClass('text-accent-light');
  });
});

describe('PageShell with a hero', () => {
  it('shows the hero across the page and lets the content climb onto it', () => {
    render(
      <PageShell productName="N" hero={<Hero title="T" />}>
        <p>Page</p>
      </PageShell>,
    );

    expect(screen.getByRole('main').parentElement).toHaveClass('-mt-12');
  });
});
