import { fireEvent, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { renderIn } from '@/components/auth/testing';
import { RecoveryCodes } from './RecoveryCodes';

const CODES = ['aaaaa-bbbbb', 'ccccc-ddddd'];

function renderCodes() {
  return renderIn(
    'fr',
    <>
      <a href="/admin/institution" data-testid="elsewhere">
        ailleurs
      </a>
      <RecoveryCodes codes={CODES} onDone={vi.fn()} />
    </>,
  );
}

afterEach(() => vi.restoreAllMocks());

describe('RecoveryCodes', () => {
  it('asks before a link of the application is followed while the codes are not kept', () => {
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false);
    renderCodes();

    const event = new MouseEvent('click', { bubbles: true, cancelable: true });
    screen.getByTestId('elsewhere').dispatchEvent(event);

    expect(confirm).toHaveBeenCalledTimes(1);
    expect(event.defaultPrevented).toBe(true);
  });

  it('lets the person leave once they accept', () => {
    vi.spyOn(window, 'confirm').mockReturnValue(true);
    renderCodes();

    const event = new MouseEvent('click', { bubbles: true, cancelable: true });
    screen.getByTestId('elsewhere').dispatchEvent(event);

    expect(event.defaultPrevented).toBe(false);
  });

  it('stops asking once the codes are kept', () => {
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false);
    renderCodes();

    fireEvent.click(screen.getByTestId('recovery-saved'));
    const event = new MouseEvent('click', { bubbles: true, cancelable: true });
    screen.getByTestId('elsewhere').dispatchEvent(event);

    expect(confirm).not.toHaveBeenCalled();
    expect(event.defaultPrevented).toBe(false);
  });
});
