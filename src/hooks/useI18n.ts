import { useCallback, useMemo } from 'react'
import { useUiStore } from '@/stores/uiStore'
import { locales } from '@/i18n'
import type { Translation, LocaleKey } from '@/i18n'

type DeepPath<T, Prefix extends string = ''> = T extends object
  ? {
      [K in keyof T]: K extends string
        ? Prefix extends ''
          ? DeepPath<T[K], K> | K
          : DeepPath<T[K], `$$Prefix}.$$K}`> | `$$Prefix}.$$K}`
        : never
    }[keyof T]
  : never

export type TranslationKey = DeepPath<Translation>

function getNestedValue(obj: unknown, path: string): string | undefined {
  const keys = path.split('.')
  let current: unknown = obj

  for (const key of keys) {
    if (current && typeof current === 'object' && key in current) {
      current = (current as Record<string, unknown>)[key]
    } else {
      return undefined
    }
  }

  return typeof current === 'string' ? current : undefined
}

function interpolate(text: string, params?: Record<string, string | number>): string {
  if (!params) return text
  return text.replace(/\{(\w+)\}/g, (_, key) => {
    return params[key] !== undefined ? String(params[key]) : `{$$key}}`
  })
}

export function useI18n() {
  const language = useUiStore((s) => s.language)
  const setLanguage = useUiStore((s) => s.setLanguage)

  const currentLocale = useMemo(() => {
    return locales[language as LocaleKey] ?? locales.zh
  }, [language])

  const hasKey = useCallback(
    (key: string): boolean => {
      return getNestedValue(currentLocale, key) !== undefined
    },
    [currentLocale],
  )

  const t = useCallback(
    <K extends string>(
      key: K,
      params?: Record<string, string | number>,
    ): string => {
      const value = getNestedValue(currentLocale, key)
      const text = value ?? key
      return interpolate(text, params)
    },
    [currentLocale],
  )

  return {
    t,
    hasKey,
    language,
    setLanguage: (lang: LocaleKey) => setLanguage(lang),
  }
}
