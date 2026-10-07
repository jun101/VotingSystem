import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { Button } from './Button';
import { Band } from './Band';
import { Hero } from './Hero';
import { PageShell } from './PageShell';

// What the stylesheet does with these pieces (gradient, colours, overlap, drift) is checked
// in a real browser by the slice 01b acceptance tests; here only what the markup says.

describe('Hero', () => {
  it('holds the page title as the one h1, with the accent word inside it', () => {
    render(<Hero title="New Voting System" accent="Voting" data-testid="h" />);

    const title = screen.getByRole('heading', { level: 1, name: 'New Voting System' });
    expect(title).toHaveAttribute('data-testid', 'h-title');
    expect(within(title).getByText('Voting')).toBeInTheDocument();
  });

  it('keeps only the title and one line when compact', () => {
    render(<Hero compact title="T" lede="One line" label="Label" figures={<b>fig</b>} />);

    expect(screen.getByText('One line')).toBeInTheDocument();
    expect(screen.queryByText('Label')).not.toBeInTheDocument();
    expect(screen.queryByText('fig')).not.toBeInTheDocument();
  });

  it('shows its pill, label, lede and figures when complete', () => {
    render(<Hero title="T" pill="Open" label="Label" lede="Lede" figures={<b>fig</b>} />);

    for (const text of ['Open', 'Label', 'Lede', 'fig']) {
      expect(screen.getByText(text)).toBeInTheDocument();
    }
  });
});

describe('Button accent', () => {
  it('is a button with its name, and answers a click', async () => {
    const onClick = vi.fn();
    render(
      <Button variant="accent" onClick={onClick}>
        Go
      </Button>,
    );

    await userEvent.click(screen.getByRole('button', { name: 'Go' }));
    expect(onClick).toHaveBeenCalledOnce();
  });
});

describe('Band', () => {
  it('shows its name, label and highlighted figure', () => {
    render(<Band name="Marie" label="Leading" figure="64 %" data-testid="b" />);

    expect(screen.getByText('Marie')).toBeInTheDocument();
    expect(screen.getByText('Leading')).toBeInTheDocument();
    expect(screen.getByTestId('b-figure')).toHaveTextContent('64 %');
  });
});

describe('PageShell with a hero', () => {
  it('puts the hero and the page in the main area, with the product name outside it', () => {
    render(
      <PageShell productName="N" hero={<Hero title="T" />}>
        <p>Page</p>
      </PageShell>,
    );

    const main = screen.getByRole('main');
    expect(within(main).getByRole('heading', { level: 1, name: 'T' })).toBeInTheDocument();
    expect(within(main).getByText('Page')).toBeInTheDocument();
    expect(main.firstElementChild).toContainElement(screen.getByRole('heading', { level: 1 }));
    expect(main).not.toContainElement(screen.getByRole('link', { name: 'N' }));
  });
});
