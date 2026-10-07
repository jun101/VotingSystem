import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { Notice } from './Notice';

describe('Notice', () => {
  it('shows its title, its text and its actions', () => {
    render(
      <Notice title="Title" actions={<button type="button">Act</button>}>
        Body
      </Notice>,
    );

    expect(screen.getByText('Title')).toBeInTheDocument();
    expect(screen.getByText('Body')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Act' })).toBeInTheDocument();
  });

  it('takes the role the page gives it, so an error is announced', () => {
    render(
      <Notice tone="danger" role="alert">
        Oops
      </Notice>,
    );

    expect(screen.getByRole('alert')).toHaveTextContent('Oops');
    expect(screen.getByRole('alert')).toHaveAttribute('data-tone', 'danger');
  });

  it('has no title or actions block when it has none', () => {
    const { container } = render(<Notice>Only text</Notice>);

    expect(container.querySelectorAll('p')).toHaveLength(0);
  });
});
