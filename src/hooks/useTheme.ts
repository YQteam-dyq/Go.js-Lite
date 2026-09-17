import { useCallback, useEffect, useMemo } from 'react'
import { useUiStore } from '@/stores/uiStore'
import type { ThemeMode } from '@shared/types'

export function useTheme() {
  const theme = useUiStore((s) => s.theme)
  const setTheme = useUiStore((s) => s.setTheme)

  useEffect(() => {
    const root = document.documentElement
    const isDark =
      theme === 'dark' ||
      (theme === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches)

    root.classList.toggle('dark', isDark)
  }, [theme])

  useEffect(() => {
    if (theme !== 'system') return

    const mq = window.matchMedia('(prefers-color-scheme: dark)')
    const handler = (e: MediaQueryListEvent) => {
      document.documentElement.classList.toggle('dark', e.matches)
    }
    mq.addEventListener('change', handler)
    return () => mq.removeEventListener('change', handler)
  }, [theme])

  const resolvedTheme: 'light' | 'dark' =
    theme === 'dark' ||
    (theme === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches)
      ? 'dark'
      : 'light'

  const changeTheme = useCallback((next: ThemeMode) => setTheme(next), [setTheme])

  return useMemo(
    () => ({
      theme,
      setTheme: changeTheme,
      resolvedTheme,
    }),
    [theme, changeTheme, resolvedTheme],
  )
}
