import { useEffect, useRef } from 'react'

const FOCUSABLE_SELECTOR = [
  'a[href]',
  'area[href]',
  'button:not([disabled])',
  'input:not([disabled]):not([type="hidden"])',
  'select:not([disabled])',
  'textarea:not([disabled])',
  'iframe',
  'audio[controls]',
  'video[controls]',
  '[contenteditable]:not([contenteditable="false"])',
  '[tabindex]:not([tabindex="-1"])',
].join(',')

export function getFocusableElements(container: HTMLElement | null): HTMLElement[] {
  if (!container) return []
  const nodes = Array.from(container.querySelectorAll<HTMLElement>(FOCUSABLE_SELECTOR))
  return nodes.filter((node) => {
    if (node.hasAttribute('disabled')) return false
    if (node.getAttribute('aria-hidden') === 'true') return false
    if (node.closest('[aria-hidden="true"]') && node !== container) return false
    return true
  })
}

export interface FocusTrapOptions {
  active?: boolean
  restoreFocus?: boolean
  onEscape?: () => void
}

export function useFocusTrap<T extends HTMLElement>(
  containerRef: React.RefObject<T | null>,
  { active = true, restoreFocus = true, onEscape }: FocusTrapOptions = {},
): void {
  const escapeRef = useRef(onEscape)
  escapeRef.current = onEscape

  useEffect(() => {
    if (!active) return
    const container = containerRef.current
    if (!container) return

    const previouslyFocused =
      typeof document !== 'undefined' ? (document.activeElement as HTMLElement | null) : null

    const focusables = getFocusableElements(container)
    const initial = focusables[0] ?? container
    initial.focus?.()

    const handleKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        escapeRef.current?.()
        return
      }
      if (event.key !== 'Tab') return

      const items = getFocusableElements(container)
      if (items.length === 0) {
        event.preventDefault()
        container.focus?.()
        return
      }

      const first = items[0]
      const last = items[items.length - 1]
      const current = typeof document !== 'undefined' ? (document.activeElement as HTMLElement | null) : null
      const inside = current !== null && container.contains(current)

      if (event.shiftKey) {
        if (!inside || current === first) {
          event.preventDefault()
          last.focus()
        }
        return
      }

      if (!inside || current === last) {
        event.preventDefault()
        first.focus()
      }
    }

    document.addEventListener('keydown', handleKeyDown, true)

    return () => {
      document.removeEventListener('keydown', handleKeyDown, true)
      if (restoreFocus && previouslyFocused && typeof previouslyFocused.focus === 'function') {
        previouslyFocused.focus()
      }
    }
  }, [active, containerRef, restoreFocus])
}
