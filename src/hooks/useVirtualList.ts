import { useEffect, useRef, useState } from 'react'

export interface VirtualRow {
  index: number
  start: number
  size: number
}

export interface ComputeRowsInput {
  rowCount: number
  itemHeight: number
  viewportHeight: number
  scrollTop: number
  overscan: number
  fallbackViewportRows?: number
}

export function computeVisibleRows({
  rowCount,
  itemHeight,
  viewportHeight,
  scrollTop,
  overscan,
  fallbackViewportRows = 10,
}: ComputeRowsInput): VirtualRow[] {
  if (rowCount <= 0 || itemHeight <= 0) return []

  const safeOverscan = Math.max(0, Math.floor(overscan))
  const viewport = viewportHeight > 0 ? viewportHeight : itemHeight * fallbackViewportRows
  const visibleRows = Math.max(1, Math.ceil(viewport / itemHeight))
  const lastStart = Math.max(0, rowCount - visibleRows - safeOverscan)
  const requested = Math.floor(Math.max(0, scrollTop) / itemHeight) - safeOverscan
  const start = Math.min(Math.max(0, requested), lastStart)
  const end = Math.min(rowCount, start + visibleRows + safeOverscan * 2)

  const rows: VirtualRow[] = []
  for (let index = start; index < end; index += 1) {
    rows.push({ index, start: index * itemHeight, size: itemHeight })
  }
  return rows
}

export function gridColumnsForWidth(width: number, minCellWidth = 120): number {
  if (!Number.isFinite(width) || width <= 0) return 2
  const byBreakpoint = width < 640 ? 2 : width < 768 ? 3 : width < 1024 ? 4 : width < 1280 ? 5 : 6
  const bySpace = Math.max(1, Math.floor(width / minCellWidth))
  return Math.max(1, Math.min(byBreakpoint, bySpace))
}

export interface VirtualViewport {
  containerRef: React.MutableRefObject<HTMLDivElement | null>
  scrollTop: number
  width: number
  height: number
}

export function useVirtualViewport(): VirtualViewport {
  const containerRef = useRef<HTMLDivElement | null>(null)
  const [scrollTop, setScrollTop] = useState(0)
  const [size, setSize] = useState({ width: 0, height: 0 })
  const frame = useRef(0)

  useEffect(() => {
    const element = containerRef.current
    if (!element) return

    const syncSize = () => {
      setSize((previous) => {
        const width = element.clientWidth
        const height = element.clientHeight
        if (previous.width === width && previous.height === height) return previous
        return { width, height }
      })
    }

    syncSize()

    const handleScroll = () => {
      if (frame.current) return
      frame.current = requestAnimationFrame(() => {
        frame.current = 0
        setScrollTop(element.scrollTop)
      })
    }

    element.addEventListener('scroll', handleScroll, { passive: true })

    let observer: ResizeObserver | null = null
    if (typeof ResizeObserver !== 'undefined') {
      observer = new ResizeObserver(syncSize)
      observer.observe(element)
    } else if (typeof window !== 'undefined') {
      window.addEventListener('resize', syncSize)
    }

    return () => {
      element.removeEventListener('scroll', handleScroll)
      if (frame.current) cancelAnimationFrame(frame.current)
      frame.current = 0
      if (observer) observer.disconnect()
      else if (typeof window !== 'undefined') window.removeEventListener('resize', syncSize)
    }
  }, [])

  return { containerRef, scrollTop, width: size.width, height: size.height }
}
