import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';
import { describe, expect, it } from 'vitest';
import { renderIn } from '@/components/auth/testing';
import { CodeBoxes, codeCharacters } from './CodeBoxes';

function Harness({ error }: { error?: string }) {
  const [value, setValue] = useState('');

  return (
    <CodeBoxes
      testId="code"
      label="Votre code de vote"
      help="Tapez ou collez votre code."
      value={value}
      onChange={setValue}
      error={error}
      errorTestId="code-error"
    />
  );
}

describe('codeCharacters', () => {
  it('keeps letters and digits, in upper case', () => {
    expect(codeCharacters('k7m3x-9ab2c')).toEqual([
      'K',
      '7',
      'M',
      '3',
      'X',
      '9',
      'A',
      'B',
      '2',
      'C',
    ]);
  });

  it('ignores spaces and hyphens, whatever their place', () => {
    expect(codeCharacters(' k7m3 x-9ab2c ').join('')).toBe('K7M3X9AB2C');
  });

  it('shows at most ten characters', () => {
    expect(codeCharacters('K7M3X9AB2CZZZ')).toHaveLength(10);
  });

  it('is empty for an empty or symbol-only text', () => {
    expect(codeCharacters('')).toEqual([]);
    expect(codeCharacters('--- ')).toEqual([]);
  });
});

describe('CodeBoxes', () => {
  it('draws ten boxes and a real field the test and the screen reader use', () => {
    const { container } = renderIn('fr', <Harness />);

    expect(container.querySelectorAll('.code-box')).toHaveLength(10);
    expect(screen.getByLabelText('Votre code de vote')).toBe(screen.getByTestId('code'));
  });

  it('shows what is typed in the boxes, split in two groups of five, without the hyphen', async () => {
    const user = userEvent.setup();
    const { container } = renderIn('fr', <Harness />);

    await user.type(screen.getByTestId('code'), 'k7m3x-9ab');

    const boxes = [...container.querySelectorAll('.code-box')].map((box) => box.textContent);

    expect(boxes.slice(0, 5)).toEqual(['K', '7', 'M', '3', 'X']);
    expect(boxes.slice(5)).toEqual(['9', 'A', 'B', '', '']);
  });

  it('marks the next empty box as the current one', async () => {
    const user = userEvent.setup();
    const { container } = renderIn('fr', <Harness />);

    await user.type(screen.getByTestId('code'), 'ab');

    const current = [...container.querySelectorAll('.code-box')].findIndex(
      (box) => box.getAttribute('data-current') === 'true',
    );

    expect(current).toBe(2);
  });

  it('keeps the value as typed for the field, so the server reads it the same way', async () => {
    const user = userEvent.setup();

    renderIn('fr', <Harness />);

    await user.type(screen.getByTestId('code'), 'k7m3x-9ab2c');

    expect(screen.getByTestId('code')).toHaveValue('k7m3x-9ab2c');
  });

  it('reads an error out with the field and colours the boxes', () => {
    const { container } = renderIn('fr', <Harness error="Ce code n’est pas valide." />);

    const field = screen.getByTestId('code');

    expect(field).toHaveAttribute('aria-invalid', 'true');
    expect(field.getAttribute('aria-describedby')).toContain(screen.getByTestId('code-error').id);
    expect(container.querySelector('.code-field')).toHaveAttribute('data-invalid', 'true');
  });
});
