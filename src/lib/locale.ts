import type { Language } from '@shared/types'

export function htmlLangForLanguage(language: Language): string {
  return language === 'zh' ? 'zh-CN' : 'en'
}

export function applyDocumentLanguage(language: Language): void {
  if (typeof document === 'undefined') return
  const next = htmlLangForLanguage(language)
  const root = document.documentElement
  if (root.getAttribute('lang') !== next) root.setAttribute('lang', next)
}
