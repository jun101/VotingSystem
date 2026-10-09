import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { Pill, type PillTone } from './Pill';

describe('Pill', () => {
  it('always shows its text', () => {
    render(<Pill>Open</Pill>);

    expect(screen.getByText('Open')).toBeInTheDocument();
  });

  it('is fully rounded', () => {
    render(<Pill>Open</Pill>);

    expect(screen.getByText('Open')).toHaveClass('rounded-full');
  });

  it.each<[PillTone, string, string]>([
    ['neutral', 'bg-line-soft', 'text-ink-2'],
    ['primary', 'bg-primary-soft', 'text-primary-hover'],
    ['teal', 'bg-teal-soft', 'text-teal-ink'],
    ['warm', 'bg-warm-soft', 'text-warm-ink'],
    ['danger', 'bg-warm-soft', 'text-danger'],
  ])('tone %s has a soft background and its own ink', (tone, background, ink) => {
    render(<Pill tone={tone}>Text</Pill>);

    expect(screen.getByText('Text')).toHaveClass(background, ink);
  });
});
