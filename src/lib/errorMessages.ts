import { useUiStore } from '@/stores/uiStore'
import { locales } from '@/i18n'
import type { LocaleKey } from '@/i18n'
import type { TranslationKey } from '@/hooks/useI18n'

/**
 * Maps error codes to i18n translation keys. Displayed errors prefer the i18n
 * text keyed by error.code, falling back to the backend message when not found,
 * keeping backward compatibility with the backend JSON.
 */
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

/**
 * Resolves the localized text to show for an error object (ApiError, backend error
 * field, or plain string), preferring the i18n key by code and falling back to the
 * backend message.
 */
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