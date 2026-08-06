import { useUiStore } from '@/stores/uiStore'
import { locales } from '@/i18n'
import type { LocaleKey } from '@/i18n'
import type { TranslationKey } from '@/hooks/useI18n'


export const errorCodeToI18nKey: Record<string, TranslationKey> = {
  unauthorized: 'errors.unauthorized',
  forbidden: 'errors.forbidden',
  not_found: 'errors.notFound',
  server_error: 'errors.serverError',
  network_error: 'errors.network',
  rate_limited: 'errors.rateLimited',
  validation_error: 'errors.validationError',
  bad_request: 'errors.badRequest',
  upload_failed: 'errors.uploadFailed',
  aborted: 'errors.aborted',
  timeout: 'errors.timeout',
}

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


export function resolveErrorText(err: unknown): string {
  if (typeof err === 'string') {
    return err
  }

  let code: string | undefined
  let message = ''
  if (err && typeof err === 'object') {
    const e = err as { code?: unknown; message?: unknown }
    if (typeof e.code === 'string') code = e.code
    if (typeof e.message === 'string') message = e.message
  }

  if (code) {
    const i18nKey = errorCodeToI18nKey[code]
    if (i18nKey) {
      const language = useUiStore.getState().language
      const locale = locales[language as LocaleKey] ?? locales.zh
      const text = getNestedValue(locale, i18nKey)
      if (text) return text
    }
  }

  return message
}