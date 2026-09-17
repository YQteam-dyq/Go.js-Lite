import { useMemo, type ReactNode } from 'react'
import { computeVisibleRows, gridColumnsForWidth, useVirtualViewport } from '@/hooks/useVirtualList'

export interface VirtualListProps<T> {
  items: T[]
  itemHeight: number
  getKey: (item: T, index: number) => string
  renderItem: (item: T, index: number) => ReactNode
  layout?: 'list' | 'grid'
  gap?: number
  threshold?: number
  overscan?: number
  className?: string
  innerClassName?: string
  roleLabel?: string
}

export function VirtualList<T>({
  items,
  itemHeight,
  getKey,
  renderItem,
  layout = 'list',
  gap = 12,
  threshold = 60,
  overscan = 6,
  className = '',
  innerClassName = '',
  roleLabel,
}: VirtualListProps<T>) {
  const { containerRef, scrollTop, width, height } = useVirtualViewport()

  const total = items.length
  const virtual = total >= threshold
  const columns = layout === 'grid' ? gridColumnsForWidth(width) : 1
  const rowCount = Math.ceil(total / columns)
  const rowHeight = layout === 'grid' ? itemHeight + gap : itemHeight

  const rows = useMemo(() => {
    if (!virtual) return []
    return computeVisibleRows({
      rowCount,
      itemHeight: rowHeight,
      viewportHeight: height,
      scrollTop,
      overscan,
    })
  }, [virtual, rowCount, rowHeight, height, scrollTop, overscan])

  const totalSize = virtual ? rowCount * rowHeight : 0

  if (!virtual) {
    return (
      <div ref={containerRef} className={className}>
        {layout === 'grid' ? (
          <div
            role="list"
            aria-label={roleLabel}
            className={`p-4 grid gap-3 ${innerClassName}`}
          >
            {items.map((item, index) => (
              <div key={getKey(item, index)} role="listitem" aria-posinset={index + 1} aria-setsize={total}>
                {renderItem(item, index)}
              </div>
            ))}
          </div>
        ) : (
          <div role="list" aria-label={roleLabel} className={`divide-y divide-border ${innerClassName}`}>
            {items.map((item, index) => (
              <div
                key={getKey(item, index)}
                role="listitem"
                aria-posinset={index + 1}
                aria-setsize={total}
                style={{ height: itemHeight }}
              >
                {renderItem(item, index)}
              </div>
            ))}
          </div>
        )}
      </div>
    )
  }

  return (
    <div ref={containerRef} className={className}>
      <div
        role="list"
        aria-label={roleLabel}
        aria-rowcount={rowCount}
        className={`relative ${innerClassName}`}
        style={{ height: totalSize }}
      >
        {rows.map((row) => {
          const offset = { transform: `translateY(${row.start}px)` }
          if (layout === 'grid') {
            return (
              <div
                key={`row-${row.index}`}
                role="presentation"
                className="absolute left-0 top-0 w-full"
                style={{
                  ...offset,
                  height: itemHeight,
                  display: 'grid',
                  gridTemplateColumns: `repeat(${columns}, minmax(0, 1fr))`,
                  gap,
                  paddingLeft: 16,
                  paddingRight: 16,
                }}
              >
                {Array.from({ length: columns }).map((_, column) => {
                  const index = row.index * columns + column
                  if (index >= total) {
                    return <div key={`empty-${row.index}-${column}`} aria-hidden="true" />
                  }
                  return (
                    <div
                      key={getKey(items[index], index)}
                      role="listitem"
                      aria-posinset={index + 1}
                      aria-setsize={total}
                    >
                      {renderItem(items[index], index)}
                    </div>
                  )
                })}
              </div>
            )
          }
          const index = row.index
          return (
            <div
              key={getKey(items[index], index)}
              role="listitem"
              aria-posinset={index + 1}
              aria-setsize={total}
              className="absolute left-0 top-0 w-full border-b border-border"
              style={{ ...offset, height: itemHeight }}
            >
              {renderItem(items[index], index)}
            </div>
          )
        })}
      </div>
    </div>
  )
}
