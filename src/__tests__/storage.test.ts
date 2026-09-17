import { beforeEach, describe, expect, it } from 'vitest';
import {
  DEFAULT_LANGUAGE,
  DEFAULT_THEME,
  UI_STORAGE_KEY,
  detectBrowserLanguage,
  isSupportedLanguage,
  isSupportedTheme,
  normalizeLanguage,
  normalizeSelection,
  normalizeTheme,
  parseJsonRecord,
  readPersistedLanguage,
  readPersistedTheme,
  readStorageItem,
  removeStorageItem,
  sameSelection,
  writeStorageItem,
} from '@/lib/storage';

beforeEach(() => {
  localStorage.clear();
});

describe('normalization', () => {
  it('accepts only the supported languages', () => {
    expect(isSupportedLanguage('zh')).toBe(true);
    expect(isSupportedLanguage('en')).toBe(true);
    expect(isSupportedLanguage('fr')).toBe(false);
    expect(isSupportedLanguage(undefined)).toBe(false);
    expect(normalizeLanguage('en')).toBe('en');
    expect(normalizeLanguage('fr')).toBe(DEFAULT_LANGUAGE);
    expect(normalizeLanguage(null, 'en')).toBe('en');
  });

  it('accepts only the supported themes', () => {
    expect(isSupportedTheme('dark')).toBe(true);
    expect(isSupportedTheme('system')).toBe(true);
    expect(isSupportedTheme('solarized')).toBe(false);
    expect(normalizeTheme('light')).toBe('light');
    expect(normalizeTheme('neon')).toBe(DEFAULT_THEME);
  });

  it('derives the default language from the browser', () => {
    expect(detectBrowserLanguage()).toBe(
      navigator.language.toLowerCase().startsWith('zh') ? 'zh' : 'en',
    );
  });
});

describe('selection helpers', () => {
  it('drops empty and non string entries', () => {
    const selection = normalizeSelection(['/a', '/a', '', '/b']);
    expect(Array.from(selection).sort()).toEqual(['/a', '/b']);
  });

  it('compares selections by content', () => {
    expect(sameSelection(new Set(['/a']), new Set(['/a']))).toBe(true);
    expect(sameSelection(new Set(['/a']), new Set(['/b']))).toBe(false);
    expect(sameSelection(new Set(['/a']), new Set(['/a', '/b']))).toBe(false);
    const single = new Set(['/a']);
    expect(sameSelection(single, single)).toBe(true);
  });
});

describe('storage access', () => {
  it('round trips a value', () => {
    expect(writeStorageItem('gojs-test', 'value')).toBe(true);
    expect(readStorageItem('gojs-test')).toBe('value');
    expect(removeStorageItem('gojs-test')).toBe(true);
    expect(readStorageItem('gojs-test')).toBeNull();
  });

  it('parses only object payloads', () => {
    expect(parseJsonRecord(null)).toBeNull();
    expect(parseJsonRecord('not json')).toBeNull();
    expect(parseJsonRecord('[1,2]')).toBeNull();
    expect(parseJsonRecord('{"a":1}')).toEqual({ a: 1 });
  });
});

describe('persisted ui state', () => {
  it('reads theme and language from the persist envelope', () => {
    localStorage.setItem(
      UI_STORAGE_KEY,
      JSON.stringify({ state: { theme: 'dark', language: 'en' }, version: 1 }),
    );
    expect(readPersistedTheme()).toBe('dark');
    expect(readPersistedLanguage()).toBe('en');
  });

  it('also accepts a bare state object', () => {
    localStorage.setItem(UI_STORAGE_KEY, JSON.stringify({ theme: 'light', language: 'zh' }));
    expect(readPersistedTheme()).toBe('light');
    expect(readPersistedLanguage()).toBe('zh');
  });

  it('normalizes an unknown persisted theme', () => {
    localStorage.setItem(UI_STORAGE_KEY, JSON.stringify({ state: { theme: 'neon' } }));
    expect(readPersistedTheme()).toBe(DEFAULT_THEME);
  });

  it('falls back to the browser language when nothing is stored', () => {
    expect(readPersistedLanguage()).toBe(detectBrowserLanguage());
    expect(readPersistedTheme()).toBe(DEFAULT_THEME);
  });

  it('survives a corrupted payload', () => {
    localStorage.setItem(UI_STORAGE_KEY, '{{{');
    expect(readPersistedTheme()).toBe(DEFAULT_THEME);
  });
});
