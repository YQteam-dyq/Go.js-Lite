import { useRef } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import { getFocusableElements, useFocusTrap } from '@/hooks/useFocusTrap';

function Harness({ onEscape }: { onEscape?: () => void }) {
  const ref = useRef<HTMLDivElement>(null);
  useFocusTrap(ref, { active: true, onEscape });
  return (
    <div ref={ref} tabIndex={-1} data-testid="trap">
      <button type="button">first</button>
      <button type="button" disabled>
        disabled
      </button>
      <div aria-hidden="true">
        <button type="button">hidden</button>
      </div>
      <button type="button">last</button>
    </div>
  );
}

describe('getFocusableElements', () => {
  it('skips disabled and hidden controls', () => {
    render(<Harness />);
    const trap = screen.getByTestId('trap');
    const labels = getFocusableElements(trap).map((node) => node.textContent);
    expect(labels).toEqual(['first', 'last']);
  });

  it('returns nothing without a container', () => {
    expect(getFocusableElements(null)).toEqual([]);
  });
});

describe('useFocusTrap', () => {
  it('focuses the first control on mount', () => {
    render(<Harness />);
    expect(document.activeElement).toBe(screen.getByRole('button', { name: 'first' }));
  });

  it('wraps backwards from the first control', () => {
    render(<Harness />);
    fireEvent.keyDown(document, { key: 'Tab', shiftKey: true });
    expect(document.activeElement).toBe(screen.getByRole('button', { name: 'last' }));
  });

  it('wraps forwards from the last control', () => {
    render(<Harness />);
    screen.getByRole('button', { name: 'last' }).focus();
    fireEvent.keyDown(document, { key: 'Tab' });
    expect(document.activeElement).toBe(screen.getByRole('button', { name: 'first' }));
  });

  it('pulls the focus back when it sits outside the trap', () => {
    render(<Harness />);
    document.body.focus();
    fireEvent.keyDown(document, { key: 'Tab' });
    expect(document.activeElement).toBe(screen.getByRole('button', { name: 'first' }));
  });

  it('keeps the focus inside the middle of the trap', () => {
    render(<Harness />);
    const first = screen.getByRole('button', { name: 'first' });
    first.focus();
    fireEvent.keyDown(document, { key: 'Tab' });
    expect(first.contains(document.activeElement)).toBe(true);
  });

  it('ignores other keys', () => {
    render(<Harness />);
    const first = screen.getByRole('button', { name: 'first' });
    fireEvent.keyDown(document, { key: 'a' });
    expect(document.activeElement).toBe(first);
  });

  it('reports an escape request', () => {
    const onEscape = vi.fn();
    render(<Harness onEscape={onEscape} />);
    fireEvent.keyDown(document, { key: 'Escape' });
    expect(onEscape).toHaveBeenCalledTimes(1);
  });

  it('restores the previous focus on unmount', () => {
    const outside = document.createElement('button');
    document.body.appendChild(outside);
    outside.focus();

    const view = render(<Harness />);
    expect(document.activeElement).not.toBe(outside);
    view.unmount();

    expect(document.activeElement).toBe(outside);
    outside.remove();
  });
});
