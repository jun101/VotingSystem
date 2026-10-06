import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { Button } from './Button';

describe('Button', () => {
  it('is a real button that does not submit a form by default', () => {
    render(<Button>Go</Button>);

    const button = screen.getByRole('button', { name: 'Go' });
    expect(button.tagName).toBe('BUTTON');
    expect(button).toHaveAttribute('type', 'button');
  });

  it('applies the variant and the size', () => {
    render(
      <>
        <Button variant="secondary">a</Button>
        <Button variant="danger" size="voter">
          b
        </Button>
      </>,
    );

    expect(screen.getByText('a').closest('button')).toHaveClass('bg-surface', 'border-line-strong');
    expect(screen.getByText('b').closest('button')).toHaveClass(
      'text-danger',
      'border-danger-line',
      'min-h-12',
    );
  });

  it('is at least 44 px high for an admin and 48 px for a voter', () => {
    render(
      <>
        <Button>a</Button>
        <Button size="voter">b</Button>
      </>,
    );

    expect(screen.getByText('a').closest('button')).toHaveClass('min-h-11');
    expect(screen.getByText('b').closest('button')).toHaveClass('min-h-12');
  });

  it('calls its handler when pressed', async () => {
    const onClick = vi.fn();
    render(<Button onClick={onClick}>Go</Button>);

    await userEvent.click(screen.getByRole('button'));

    expect(onClick).toHaveBeenCalledOnce();
  });

  it('cannot be pressed when disabled', async () => {
    const onClick = vi.fn();
    render(
      <Button disabled onClick={onClick}>
        Go
      </Button>,
    );

    await userEvent.click(screen.getByRole('button'));

    expect(screen.getByRole('button')).toBeDisabled();
    expect(onClick).not.toHaveBeenCalled();
  });

  it('says it is busy, is disabled and keeps its label while loading', async () => {
    const onClick = vi.fn();
    render(
      <Button loading onClick={onClick}>
        Send
      </Button>,
    );

    const button = screen.getByRole('button', { name: 'Send' });
    await userEvent.click(button);

    expect(button).toHaveAttribute('aria-busy', 'true');
    expect(button).toBeDisabled();
    expect(onClick).not.toHaveBeenCalled();
  });

  it('is not marked busy when it is not loading', () => {
    render(<Button>Send</Button>);

    expect(screen.getByRole('button')).not.toHaveAttribute('aria-busy');
  });
});
