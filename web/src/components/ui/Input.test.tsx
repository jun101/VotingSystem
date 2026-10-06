import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { Input } from './Input';

describe('Input', () => {
  it('has a label tied to the field', () => {
    render(<Input label="First name" />);

    expect(screen.getByLabelText('First name')).toBeInTheDocument();
  });

  it('describes the field with its help text', () => {
    render(<Input label="First name" help="As on the list" />);

    expect(screen.getByLabelText('First name')).toHaveAccessibleDescription('As on the list');
    expect(screen.getByLabelText('First name')).not.toHaveAttribute('aria-invalid');
  });

  it('marks the field invalid and ties the error message to it', () => {
    render(<Input label="Email" error="Not valid" help="Like a@b.c" />);

    const field = screen.getByLabelText('Email');

    expect(field).toHaveAttribute('aria-invalid', 'true');
    expect(field).toHaveAccessibleDescription('Not valid Like a@b.c');
    // The error is the first thing described.
    const firstId = field.getAttribute('aria-describedby')!.split(' ')[0]!;
    expect(document.getElementById(firstId)).toHaveTextContent('Not valid');
  });

  it('has no description when there is neither help nor error', () => {
    render(<Input label="Name" />);

    expect(screen.getByLabelText('Name')).not.toHaveAttribute('aria-describedby');
  });

  it('keeps an id given by the caller', () => {
    render(<Input label="Name" id="name-field" />);

    expect(screen.getByLabelText('Name')).toHaveAttribute('id', 'name-field');
  });

  it('is 44 px high at least, with 16 px text on a phone and 14 px from md', () => {
    render(<Input label="Name" />);

    expect(screen.getByLabelText('Name')).toHaveClass('min-h-11', 'text-[16px]', 'md:text-base');
  });
});
