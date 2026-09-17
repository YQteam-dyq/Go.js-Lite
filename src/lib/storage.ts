import type { Language, ThemeMode } from '@shared/types'

export const UI_STORAGE_KEY = 'gojs-ui'

export const SUPPORTED_LANGUAGES: readonly Language[] = ['zh', 'en']
export const SUPPORTED_THEMES: readonly ThemeMode[] = ['light', 'dark', 'system']

export const DEFAULT_LANGUAGE: Language = 'zh'
export const DEFAULT_THEME: ThemeMode = 'system'

export function isSupportedLanguage(value: unknown): value is Language {
  return typeof value === 'string' && (SUPPORTED_LANGUAGES as readonly string[]).includes(value)
}

export function isSupportedTheme(value: unknown): value is ThemeMode {
  return typeof value === 'string' && (SUPPORTED_THEMES as readonly string[]).includes(value)
}

export function normalizeLanguage(value: unknown, fallback: Language = DEFAULT_LANGUAGE): Language {
  return isSupportedLanguage(value) ? value : fallback
}

export function normalizeTheme(value: unknown, fallback: ThemeMode = DEFAULT_THEME): ThemeMode {
  return isSupportedTheme(value) ? value : fallback
}

export function detectBrowserLanguage(): Language {
  if (typeof navigator === 'undefined') return DEFAULT_LANGUAGE
  const raw = navigator.language || ''
  return raw.toLowerCase().startsWith('zh') ? 'zh' : 'en'
}

function getStorage(): Storage | null {
  try {
    if (typeof localStorage === 'undefined') return null
    return localStorage
  } catch {
    return null
  }
}

export function readStorageItem(key: string): string | null {
  const storage = getStorage()
  if (!storage) return null
  try {
    return storage.getItem(key)
  } catch {
    return null
  }
}

export function writeStorageItem(key: string, value: string): boolean {
  const storage = getStorage()
  if (!storage) return false
  try {
    storage.setItem(key, value)
    return true
  } catch {
    return false
  }
}

export function removeStorageItem(key: string): boolean {
  const storage = getStorage()
  if (!storage) return false
  try {
    storage.removeItem(key)
    return true
  } catch {
    return false
  }
}

export function parseJsonRecord(raw: string | null): Record<string, unknown> | null {
  if (!raw) return null
  try {
    const parsed: unknown = JSON.parse(raw)
    if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
      return parsed as Record<string, unknown>
    }
    return null
  } catch {
    return null
  }
}

export function readPersistedUiState(): Record<string, unknown> | null {
  const envelope = parseJsonRecord(readStorageItem(UI_STORAGE_KEY))
  if (!envelope) return null
  const state = envelope.state
  if (state && typeof state === 'object' && !Array.isArray(state)) {
    return state as Record<string, unknown>
  }
  return envelope
}

export function readPersistedTheme(): ThemeMode {
  const state = readPersistedUiState()
  if (!state) return DEFAULT_THEME
  return normalizeTheme(state.theme, DEFAULT_THEME)
}

export function readPersistedLanguage(): Language {
  const state = readPersistedUiState()
  if (!state) return detectBrowserLanguage()
  return normalizeLanguage(state.language, detectBrowserLanguage())
}

export function normalizeSelection(paths: Iterable<string>): Set<string> {
  const unique = new Set<string>()
  for (const path of paths) {
    if (typeof path === 'string' && path.length > 0) unique.add(path)
  }
  return unique
}

export function sameSelection(a: ReadonlySet<string>, b: ReadonlySet<string>): boolean {
  if (a === b) return true
  if (a.size !== b.size) return false
  for (const value of a) {
    if (!b.has(value)) return false
  }
  return true
}
