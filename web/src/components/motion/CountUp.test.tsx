import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { CountUp } from './CountUp';

describe('CountUp', () => {
  it('has the final value in the HTML, read by a screen reader, with the counting hidden', () => {
    const { container } = render(<CountUp value={1240} locale="en" suffix=" %" data-testid="c" />);

    expect(screen.getByText('1,240 %', { selector: '.sr-only' })).toBeInTheDocument();
    expect(container.querySelector('[data-testid="c"]')).toHaveAttribute('aria-hidden', 'true');
    expect(screen.getByTestId('c')).toHaveTextContent('1,240 %');
  });

  it('writes the figure in the language of the page', () => {
    render(<CountUp value={1240} locale="fr" data-testid="c" />);

    expect(screen.getByTestId('c').textContent).toMatch(/^1\s240$/);
  });
});
