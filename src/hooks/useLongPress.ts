import { useCallback, useRef, useState } from 'react'

interface Options {
  delay?: number
  onStart?: () => void
  onEnd?: () => void
}

export function useLongPress<T extends HTMLElement>(
  callback: () => void,
  options: Options = {},
) {
  const { delay = 500, onStart, onEnd } = options
  const timerRef = useRef<number | null>(null)
  const [active, setActive] = useState(false)
  const movedRef = useRef(false)
  const startPosRef = useRef<{ x: number; y: number }>({ x: 0, y: 0 })

  const clear = useCallback(() => {
    if (timerRef.current) {
      clearTimeout(timerRef.current)
      timerRef.current = null
    }
    setActive(false)
    onEnd?.()
  }, [onEnd])

  const start = useCallback(
    (clientX: number, clientY: number) => {
      movedRef.current = false
      startPosRef.current = { x: clientX, y: clientY }
      setActive(true)
      onStart?.()
      timerRef.current = window.setTimeout(() => {
        callback()
        clear()
      }, delay)
    },
    [callback, delay, onStart, clear],
  )

  const move = useCallback(
    (clientX: number, clientY: number) => {
      const dx = Math.abs(clientX - startPosRef.current.x)
      const dy = Math.abs(clientY - startPosRef.current.y)
      if (dx > 10 || dy > 10) {
        movedRef.current = true
        clear()
      }
    },
    [clear],
  )

  const handlers = {
    onTouchStart: (e: React.TouchEvent<T>) => {
      const t = e.touches[0]
      start(t.clientX, t.clientY)
    },
    onTouchMove: (e: React.TouchEvent<T>) => {
      const t = e.touches[0]
      move(t.clientX, t.clientY)
    },
    onTouchEnd: clear,
    onTouchCancel: clear,
    onMouseDown: (e: React.MouseEvent<T>) => {
      if (e.button !== 0) return
      start(e.clientX, e.clientY)
    },
    onMouseMove: (e: React.MouseEvent<T>) => {
      move(e.clientX, e.clientY)
    },
    onMouseUp: clear,
    onMouseLeave: clear,
    onContextMenu: (e: React.MouseEvent<T>) => {
      e.preventDefault()
      callback()
    },
  }

  return {
    handlers,
    active,
  }
}
