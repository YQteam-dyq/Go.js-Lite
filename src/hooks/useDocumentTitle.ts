import { useEffect } from 'react'
import { useI18n } from './useI18n'
import type { TranslationKey } from './useI18n'

export function useDocumentTitle(titleKey: TranslationKey) {
  const { t } = useI18n()

  useEffect(() => {
    const translatedTitle = t(titleKey)
    document.title = `$$translatedTitle} — Go.js`
  }, [t, titleKey])
}
