import { fireEvent, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { renderIn } from '@/components/auth/testing';
import { IssuedPassword } from './IssuedPassword';

describe('IssuedPassword', () => {
  it('shows the password and who it is for, once, in French', () => {
    renderIn('fr', <IssuedPassword name="Jean" password="K7MP-4QX9-RTBC" onDismiss={vi.fn()} />);

    expect(screen.getByTestId('issued-password-value')).toHaveTextContent('K7MP-4QX9-RTBC');
    expect(screen.getByTestId('issued-password')).toHaveTextContent('Jean');
    expect(screen.getByTestId('issued-password')).toHaveTextContent('qu’une seule fois');
  });

  it('calls back when the owner has noted it', () => {
    const onDismiss = vi.fn();
    renderIn('en', <IssuedPassword name="Jean" password="K7MP-4QX9-RTBC" onDismiss={onDismiss} />);

    fireEvent.click(screen.getByTestId('issued-password-dismiss'));

    expect(onDismiss).toHaveBeenCalledOnce();
  });
});
