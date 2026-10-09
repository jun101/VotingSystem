import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { Card } from './Card';

describe('Card', () => {
  it('holds its content on a bordered surface with a resting shadow', () => {
    render(<Card data-testid="card">Content</Card>);

    expect(screen.getByTestId('card')).toHaveClass(
      'bg-surface',
      'border-line',
      'rounded-lg',
      'shadow-1',
    );
    expect(screen.getByTestId('card')).toHaveTextContent('Content');
  });

  it('is flat inside another surface: smaller corners and no shadow', () => {
    render(
      <Card flat data-testid="card">
        Content
      </Card>,
    );

    expect(screen.getByTestId('card')).toHaveClass('rounded-md');
    expect(screen.getByTestId('card').className).not.toMatch(/shadow|ring/);
  });

  it('shows its title as a level 2 heading', () => {
    render(<Card title="Title">Content</Card>);

    expect(screen.getByRole('heading', { level: 2, name: 'Title' })).toBeInTheDocument();
  });

  it('shows its actions next to the title', () => {
    render(
      <Card title="Title" actions={<button type="button">Edit</button>}>
        Content
      </Card>,
    );

    expect(screen.getByRole('button', { name: 'Edit' })).toBeInTheDocument();
  });

  it('has no heading without a title', () => {
    render(<Card>Content</Card>);

    expect(screen.queryByRole('heading')).not.toBeInTheDocument();
  });
});
