import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { act, renderHook } from '@testing-library/react';
import {
  formatBytes,
  formatDate,
  formatDateShort,
  formatDuration,
  formatNumber,
  formatRelativeTime,
  getFileExtension,
  isImageFile,
  isTextFile,
  truncate,
  useFormat,
} from '@/lib/format';
import { locales } from '@/i18n';
import { useUiStore } from '@/stores/uiStore';

const NOW = Date.UTC(2024, 5, 15, 12, 0, 0);

beforeEach(() => {
  vi.useFakeTimers();
  vi.setSystemTime(NOW);
});

afterEach(() => {
  vi.useRealTimers();
});

describe('formatBytes', () => {
  it('formats zero and negative sizes', () => {
    expect(formatBytes(0)).toBe('0 B');
    expect(formatBytes(-1)).toBe('\u2014');
    expect(formatBytes(-1024)).toBe('\u2014');
  });

  it('walks through the size units', () => {
    expect(formatBytes(512)).toBe('512 B');
    expect(formatBytes(1024)).toBe('1 KB');
    expect(formatBytes(1024 ** 2)).toBe('1 MB');
    expect(formatBytes(1024 ** 3)).toBe('1 GB');
    expect(formatBytes(1024 ** 4)).toBe('1 TB');
    expect(formatBytes(1024 ** 5)).toBe('1 PB');
  });

  it('honours the decimal argument', () => {
    expect(formatBytes(1536)).toBe('1.5 KB');
    expect(formatBytes(1536, 0)).toBe('2 KB');
    expect(formatBytes(1536, 3)).toBe('1.5 KB');
    expect(formatBytes(1536, -1)).toBe('2 KB');
  });
});

describe('formatDate', () => {
  it('renders a date for both languages', () => {
    expect(formatDate(NOW, 'zh')).toContain('2024');
    expect(formatDate(NOW, 'en')).toContain('2024');
  });

  it('defaults to chinese', () => {
    expect(formatDate(NOW)).toBe(formatDate(NOW, 'zh'));
  });

  it('accepts seconds as well as milliseconds', () => {
    expect(formatDate(NOW / 1000, 'zh')).toBe(formatDate(NOW, 'zh'));
  });
});

describe('formatDateShort', () => {
  it('renders an iso style date for chinese', () => {
    expect(formatDateShort(NOW, 'zh')).toMatch(/^\d{4}-\d{2}-\d{2}$/);
    expect(formatDateShort(NOW)).toMatch(/^\d{4}-\d{2}-\d{2}$/);
  });

  it('renders a month name for english', () => {
    expect(formatDateShort(NOW, 'en')).toMatch(/^[A-Za-z]{3} \d{1,2}, \d{4}$/);
  });

  it('accepts seconds as well as milliseconds', () => {
    expect(formatDateShort(NOW / 1000, 'zh')).toBe(formatDateShort(NOW, 'zh'));
    expect(formatDateShort(NOW / 1000, 'en')).toMatch(/^[A-Za-z]{3} \d{1,2}, \d{4}$/);
  });
});

describe('formatRelativeTime', () => {
  it('reports fresh timestamps', () => {
    expect(formatRelativeTime(NOW - 30_000, 'en')).toBe('Just now');
  });

  it('reports minutes with the right plural', () => {
    expect(formatRelativeTime(NOW - 60_000, 'en')).toBe('1 minute ago');
    expect(formatRelativeTime(NOW - 5 * 60_000, 'en')).toBe('5 minutes ago');
  });

  it('reports hours with the right plural', () => {
    expect(formatRelativeTime(NOW - 3_600_000, 'en')).toBe('1 hour ago');
    expect(formatRelativeTime(NOW - 3 * 3_600_000, 'en')).toBe('3 hours ago');
  });

  it('reports days with the right plural', () => {
    expect(formatRelativeTime(NOW - 86_400_000, 'en')).toBe('1 day ago');
    expect(formatRelativeTime(NOW - 2 * 86_400_000, 'en')).toBe('2 days ago');
  });

  it('falls back to a date beyond thirty days', () => {
    const old = NOW - 40 * 86_400_000;
    expect(formatRelativeTime(old, 'en')).toContain('2024');
    expect(formatRelativeTime(old, 'en')).not.toContain('ago');
  });

  it('prefers the translator when one is given', () => {
    const t = vi.fn(
      (key: string, params?: Record<string, string | number>) =>
        `${key}|${params ? String(params.count) : ''}`
    );

    expect(formatRelativeTime(NOW - 1_000, 'zh', t)).toBe('common.justNow|');
    expect(formatRelativeTime(NOW - 5 * 60_000, 'zh', t)).toBe('common.minutesAgo|5');
    expect(formatRelativeTime(NOW - 2 * 3_600_000, 'zh', t)).toBe('common.hoursAgo|2');
    expect(formatRelativeTime(NOW - 3 * 86_400_000, 'zh', t)).toBe('common.daysAgo|3');

    expect(t).toHaveBeenCalledWith('common.minutesAgo', { count: 5 });
  });

  it('still falls back to a date with a translator beyond thirty days', () => {
    const t = vi.fn((key: string) => key);
    const old = NOW - 40 * 86_400_000;
    expect(formatRelativeTime(old, 'zh', t)).toContain('2024');
    expect(t).not.toHaveBeenCalled();
  });

  it('accepts seconds as well as milliseconds', () => {
    expect(formatRelativeTime(NOW / 1000 - 30, 'en')).toBe('Just now');
  });
});

describe('formatNumber', () => {
  it('groups thousands', () => {
    expect(formatNumber(1234567, 'en')).toBe('1,234,567');
    expect(formatNumber(42, 'en')).toBe('42');
  });
});

describe('formatDuration', () => {
  it('rejects invalid input', () => {
    expect(formatDuration(-1)).toBe('-');
    expect(formatDuration(Number.POSITIVE_INFINITY)).toBe('-');
    expect(formatDuration(Number.NaN)).toBe('-');
  });

  it('formats seconds, minutes, hours and days', () => {
    expect(formatDuration(0)).toBe('0s');
    expect(formatDuration(45)).toBe('45s');
    expect(formatDuration(60)).toBe('1m 0s');
    expect(formatDuration(61)).toBe('1m 1s');
    expect(formatDuration(3599)).toBe('59m 59s');
    expect(formatDuration(3600)).toBe('1h 0m');
    expect(formatDuration(86399)).toBe('23h 59m');
    expect(formatDuration(86400)).toBe('1d 0h');
    expect(formatDuration(90000)).toBe('1d 1h');
    expect(formatDuration(172800)).toBe('2d 0h');
  });

  it('truncates fractions', () => {
    expect(formatDuration(59.9)).toBe('59s');
  });
});

describe('string helpers', () => {
  it('truncates only when needed', () => {
    expect(truncate('hello', 10)).toBe('hello');
    expect(truncate('hello world', 5)).toBe('hell\u2026');
    expect(truncate('', 3)).toBe('');
  });

  it('reads a lowercased extension', () => {
    expect(getFileExtension('file.txt')).toBe('txt');
    expect(getFileExtension('archive.tar.gz')).toBe('gz');
    expect(getFileExtension('PHOTO.JPG')).toBe('jpg');
    expect(getFileExtension('README')).toBe('');
    expect(getFileExtension('.env')).toBe('');
  });

  it('detects image files', () => {
    expect(isImageFile('a.png')).toBe(true);
    expect(isImageFile('a.PNG')).toBe(true);
    expect(isImageFile('a.svg')).toBe(true);
    expect(isImageFile('a.txt')).toBe(false);
    expect(isImageFile('noext')).toBe(false);
  });

  it('detects text files', () => {
    expect(isTextFile('index.php')).toBe(true);
    expect(isTextFile('App.vue')).toBe(true);
    expect(isTextFile('a.TS')).toBe(true);
    expect(isTextFile('a.png')).toBe(false);
    expect(isTextFile('noext')).toBe(false);
  });
});

function useSubject() {
  return useFormat();
}

describe('useFormat', () => {
  it('binds every formatter to the active language', () => {
    useUiStore.setState({ language: 'zh' });
    const { result } = renderHook(() => useSubject());

    expect(result.current.formatDateShort(NOW)).toMatch(/^\d{4}-\d{2}-\d{2}$/);
    expect(result.current.formatDate(NOW)).toBe(formatDate(NOW, 'zh'));
    expect(result.current.formatNumber(1234567)).toBe(formatNumber(1234567, 'zh'));
    expect(result.current.formatBytes(1024)).toBe('1 KB');
    expect(result.current.formatDuration(61)).toBe('1m 1s');
    expect(result.current.formatRelativeTime(NOW - 30_000)).toBe(locales.zh.common.justNow);
  });

  it('follows a language switch', () => {
    useUiStore.setState({ language: 'zh' });
    const { result } = renderHook(() => useSubject());

    act(() => useUiStore.getState().setLanguage('en'));

    expect(result.current.formatDateShort(NOW)).toMatch(/^[A-Za-z]{3} \d{1,2}, \d{4}$/);
    expect(result.current.formatRelativeTime(NOW - 30_000)).toBe(locales.en.common.justNow);
  });
});
