import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { describe, expect, it } from 'vitest';
import { locales } from '@/i18n';
import { buildNavItems } from '@/lib/navigation';
import { getDefaultCaps } from '@/stores/authStore';

const SRC_ROOT = resolve(process.cwd(), 'src');
const SKIPPED_DIRS = new Set(['__tests__', 'i18n']);

function sourceFiles(directory: string, collected: string[] = []): string[] {
  for (const entry of readdirSync(directory)) {
    const full = join(directory, entry);
    if (statSync(full).isDirectory()) {
      if (!SKIPPED_DIRS.has(entry)) sourceFiles(full, collected);
      continue;
    }
    if (/\.tsx?$/.test(entry)) collected.push(full);
  }
  return collected;
}

const STATIC_CALL = /\bt\(\s*(?:'([A-Za-z][\w.]*)'|"([A-Za-z][\w.]*)")/g;

function usedKeys(): Set<string> {
  const keys = new Set<string>();
  for (const file of sourceFiles(SRC_ROOT)) {
    const source = readFileSync(file, 'utf8');
    for (const match of source.matchAll(STATIC_CALL)) {
      keys.add(match[1] ?? match[2]);
    }
  }
  return keys;
}

function resolves(locale: unknown, key: string): boolean {
  let current: unknown = locale;
  for (const part of key.split('.')) {
    if (!current || typeof current !== 'object') return false;
    const record = current as Record<string, unknown>;
    if (!(part in record)) return false;
    current = record[part];
  }
  return typeof current === 'string' && current.trim().length > 0;
}

const keys = usedKeys();

describe('translation key coverage', () => {
  it('finds the keys the source asks for', () => {
    expect(keys.size).toBeGreaterThan(200);
  });

  it('resolves every key in the english catalogue', () => {
    const missing = Array.from(keys).filter((key) => !resolves(locales.en, key));
    expect(missing).toEqual([]);
  });

  it('resolves every key in the chinese catalogue', () => {
    const missing = Array.from(keys).filter((key) => !resolves(locales.zh, key));
    expect(missing).toEqual([]);
  });

  it('resolves every navigation label', () => {
    for (const item of buildNavItems({ caps: getDefaultCaps(), role: 'admin' })) {
      expect(resolves(locales.en, item.labelKey), item.labelKey).toBe(true);
      expect(resolves(locales.zh, item.labelKey), item.labelKey).toBe(true);
    }
  });
});
