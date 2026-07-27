import zh from './locales/zh'
import en from './locales/en'
import type { Translation } from './locales/zh'

export const locales = { zh, en }
export type { Translation }
export type LocaleKey = keyof typeof locales
