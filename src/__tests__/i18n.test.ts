import { beforeEach, describe, expect, it } from 'vitest';
import { act, renderHook } from '@testing-library/react';
import { locales } from '@/i18n';
import { useI18n } from '@/hooks/useI18n';
import { useUiStore } from '@/stores/uiStore';

type Entry = [string, string];

function walk(value: unknown, prefix: string, visit: (key: string, text: string) => void): void {
  if (value === null || typeof value !== 'object') {
    if (prefix) visit(prefix, String(value));
    return;
  }
  for (const [key, child] of Object.entries(value as Record<string, unknown>)) {
    walk(child, prefix ? `${prefix}.${key}` : key, visit);
  }
}

function collect(value: unknown): Entry[] {
  const entries: Entry[] = [];
  walk(value, '', (key, text) => entries.push([key, text]));
  return entries;
}

function placeholders(text: string): string[] {
  return (text.match(/\{(\w+)\}/g) ?? []).slice().sort();
}

function useSubject() {
  return useI18n();
}

beforeEach(() => {
  useUiStore.setState({ language: 'zh' });
});

describe('locale catalogues', () => {
  it('exposes chinese and english', () => {
    expect(Object.keys(locales)).toEqual(['zh', 'en']);
  });

  it('defines the same keys in both languages', () => {
    const zh = collect(locales.zh)
      .map(([key]) => key)
      .sort();
    const en = collect(locales.en)
      .map(([key]) => key)
      .sort();
    expect(en).toEqual(zh);
  });

  it('never leaves a message empty', () => {
    for (const [key, text] of collect(locales.zh)) {
      expect(text.trim().length, key).toBeGreaterThan(0);
    }
    for (const [key, text] of collect(locales.en)) {
      expect(text.trim().length, key).toBeGreaterThan(0);
    }
  });

  it('uses the same placeholders in both languages', () => {
    const zh = new Map(
      collect(locales.zh).map(([key, text]) => [key, placeholders(text).join('|')])
    );
    const en = new Map(
      collect(locales.en).map(([key, text]) => [key, placeholders(text).join('|')])
    );

    for (const [key, value] of zh) {
      expect(en.get(key), key).toBe(value);
    }
  });

  it('ships more than a trivial number of messages', () => {
    expect(collect(locales.zh).length).toBeGreaterThan(200);
  });
});

describe('useI18n', () => {
  it('returns the value of the active language', () => {
    const { result } = renderHook(() => useSubject());
    expect(result.current.language).toBe('zh');
    expect(result.current.t('common.save')).toBe(locales.zh.common.save);
    expect(result.current.t('errors.notFound')).toBe(locales.zh.errors.notFound);
  });

  it('follows a language switch', () => {
    useUiStore.setState({ language: 'en' });
    const { result } = renderHook(() => useSubject());
    expect(result.current.language).toBe('en');
    expect(result.current.t('common.save')).toBe(locales.en.common.save);
  });

  it('falls back to the key when it is unknown', () => {
    const { result } = renderHook(() => useSubject());
    expect(result.current.t('common.doesNotExist')).toBe('common.doesNotExist');
    expect(result.current.t('nope')).toBe('nope');
  });

  it('interpolates named params', () => {
    const { result } = renderHook(() => useSubject());
    expect(result.current.t('common.minutesAgo', { count: 5 })).toBe(
      locales.zh.common.minutesAgo.replace('{count}', '5')
    );
    expect(result.current.t('codeEditor.lineCount', { count: 12 })).toBe(
      locales.zh.codeEditor.lineCount.replace('{count}', '12')
    );
  });

  it('returns the raw message when no params are given', () => {
    const { result } = renderHook(() => useSubject());
    expect(result.current.t('common.minutesAgo')).toBe(locales.zh.common.minutesAgo);
  });

  it('leaves a placeholder untouched when the param is missing', () => {
    const { result } = renderHook(() => useSubject());
    expect(result.current.t('common.minutesAgo', { other: 1 })).toBe(locales.zh.common.minutesAgo);
  });

  it('reports whether a key exists', () => {
    const { result } = renderHook(() => useSubject());
    expect(result.current.hasKey('common.save')).toBe(true);
    expect(result.current.hasKey('errors.unauthorized')).toBe(true);
    expect(result.current.hasKey('common.doesNotExist')).toBe(false);
    expect(result.current.hasKey('nope')).toBe(false);
  });

  it('rejects a key that points at an object', () => {
    const { result } = renderHook(() => useSubject());
    expect(result.current.hasKey('common')).toBe(false);
  });

  it('updates the language through the hook', () => {
    const { result } = renderHook(() => useSubject());
    act(() => result.current.setLanguage('en'));

    expect(useUiStore.getState().language).toBe('en');
    expect(result.current.t('common.save')).toBe(locales.en.common.save);
  });

  it('falls back to chinese for an unsupported language', () => {
    useUiStore.setState({ language: 'fr' as never });
    const { result } = renderHook(() => useSubject());
    expect(result.current.t('common.save')).toBe(locales.zh.common.save);
  });
});
