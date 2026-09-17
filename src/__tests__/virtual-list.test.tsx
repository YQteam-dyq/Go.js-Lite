import { describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import { computeVisibleRows, gridColumnsForWidth } from '@/hooks/useVirtualList';
import { VirtualList } from '@/components/ui/VirtualList';

function makeItems(count: number): string[] {
  return Array.from({ length: count }, (_, index) => `file-${index}.txt`);
}

describe('computeVisibleRows', () => {
  it('returns nothing for an empty list', () => {
    expect(computeVisibleRows({ rowCount: 0, itemHeight: 40, viewportHeight: 400, scrollTop: 0, overscan: 4 })).toEqual([]);
    expect(computeVisibleRows({ rowCount: 10, itemHeight: 0, viewportHeight: 400, scrollTop: 0, overscan: 4 })).toEqual([]);
  });

  it('windows the first rows and applies the overscan', () => {
    const rows = computeVisibleRows({
      rowCount: 1000,
      itemHeight: 40,
      viewportHeight: 400,
      scrollTop: 0,
      overscan: 3,
    });
    expect(rows[0].index).toBe(0);
    expect(rows[0].start).toBe(0);
    expect(rows[rows.length - 1].index).toBe(10 + 3 * 2 - 1);
  });

  it('moves the window with the scroll offset', () => {
    const rows = computeVisibleRows({
      rowCount: 1000,
      itemHeight: 40,
      viewportHeight: 400,
      scrollTop: 4000,
      overscan: 2,
    });
    expect(rows[0].index).toBe(98);
    expect(rows[rows.length - 1].index).toBe(111);
  });

  it('clamps the window at the end of the list', () => {
    const rows = computeVisibleRows({
      rowCount: 120,
      itemHeight: 40,
      viewportHeight: 400,
      scrollTop: 100000,
      overscan: 4,
    });
    expect(rows[rows.length - 1].index).toBe(119);
    expect(rows.length).toBeLessThanOrEqual(20);
  });

  it('falls back to a sane viewport when the height is unknown', () => {
    const rows = computeVisibleRows({
      rowCount: 500,
      itemHeight: 40,
      viewportHeight: 0,
      scrollTop: 0,
      overscan: 0,
      fallbackViewportRows: 4,
    });
    expect(rows).toHaveLength(4);
  });

  it('treats a negative scroll offset as the top', () => {
    const rows = computeVisibleRows({
      rowCount: 50,
      itemHeight: 40,
      viewportHeight: 200,
      scrollTop: -500,
      overscan: 1,
    });
    expect(rows[0].index).toBe(0);
  });
});

describe('gridColumnsForWidth', () => {
  it('follows the responsive breakpoints', () => {
    expect(gridColumnsForWidth(360)).toBe(2);
    expect(gridColumnsForWidth(700)).toBe(3);
    expect(gridColumnsForWidth(900)).toBe(4);
    expect(gridColumnsForWidth(1100)).toBe(5);
    expect(gridColumnsForWidth(1600)).toBe(6);
  });

  it('never drops below one column', () => {
    expect(gridColumnsForWidth(0)).toBe(2);
    expect(gridColumnsForWidth(40)).toBe(1);
    expect(gridColumnsForWidth(Number.NaN)).toBe(2);
  });
});

describe('VirtualList', () => {
  it('renders every row below the threshold', () => {
    render(
      <VirtualList
        items={makeItems(5)}
        itemHeight={48}
        getKey={(item) => item}
        renderItem={(item) => <span>{item}</span>}
      />,
    );
    expect(screen.getAllByRole('listitem')).toHaveLength(5);
    expect(screen.getByText('file-4.txt')).toBeTruthy();
  });

  it('windows a long list and keeps the total height', () => {
    const { container } = render(
      <VirtualList
        items={makeItems(2000)}
        itemHeight={48}
        getKey={(item) => item}
        renderItem={(item) => <span>{item}</span>}
      />,
    );
    const rendered = screen.getAllByRole('listitem');
    expect(rendered.length).toBeGreaterThan(0);
    expect(rendered.length).toBeLessThan(100);
    expect(rendered[0].getAttribute('aria-setsize')).toBe('2000');
    expect(rendered[0].getAttribute('aria-posinset')).toBe('1');

    const list = container.querySelector('[role="list"]') as HTMLElement;
    expect(list.style.height).toBe(`${2000 * 48}px`);
  });

  it('windows a grid by row', () => {
    render(
      <VirtualList
        items={makeItems(600)}
        itemHeight={116}
        layout="grid"
        gap={12}
        getKey={(item) => item}
        renderItem={(item) => <span>{item}</span>}
      />,
    );
    const rendered = screen.getAllByRole('listitem');
    expect(rendered.length).toBeGreaterThan(0);
    expect(rendered.length).toBeLessThan(200);
    expect(rendered[0].getAttribute('aria-setsize')).toBe('600');
  });

  it('renders an aria label on the list', () => {
    render(
      <VirtualList
        items={makeItems(3)}
        itemHeight={48}
        roleLabel="Files"
        getKey={(item) => item}
        renderItem={(item) => <span>{item}</span>}
      />,
    );
    expect(screen.getByRole('list').getAttribute('aria-label')).toBe('Files');
  });
});
