import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { EMPTY_MULTI_SELECTION, useUiStore } from '@/stores/uiStore';
import { UI_STORAGE_KEY } from '@/lib/storage';

beforeEach(() => {
  localStorage.clear();
  useUiStore.setState({
    language: 'zh',
    theme: 'system',
    multiSelection: EMPTY_MULTI_SELECTION,
    toasts: [],
  });
});

describe('preference normalization', () => {
  it('rejects an unsupported language', () => {
    useUiStore.getState().setLanguage('fr' as never);
    expect(useUiStore.getState().language).toBe('zh');
    useUiStore.getState().setLanguage('en');
    expect(useUiStore.getState().language).toBe('en');
  });

  it('rejects an unsupported theme', () => {
    useUiStore.getState().setTheme('neon' as never);
    expect(useUiStore.getState().theme).toBe('system');
    useUiStore.getState().setTheme('dark');
    expect(useUiStore.getState().theme).toBe('dark');
  });

  it('persists both preferences under the shared key', () => {
    useUiStore.getState().setLanguage('en');
    useUiStore.getState().setTheme('dark');
    const raw = localStorage.getItem(UI_STORAGE_KEY);
    expect(raw).toBeTruthy();
    const envelope = JSON.parse(raw as string);
    expect(envelope.state).toEqual({ language: 'en', theme: 'dark' });
  });

  it('stores the preferences where the pre paint script reads them', () => {
    expect(useUiStore.persist.getOptions().name).toBe(UI_STORAGE_KEY);
    const html = readFileSync(resolve(process.cwd(), 'index.html'), 'utf8');
    expect(html).toContain(`localStorage.getItem('${UI_STORAGE_KEY}')`);
    expect(html).toContain('parsed.state');
  });
});

describe('multi selection', () => {
  it('drops duplicates and empty entries', () => {
    useUiStore.getState().setSelection(['/a', '/a', '', '/b']);
    expect(Array.from(useUiStore.getState().multiSelection).sort()).toEqual(['/a', '/b']);
  });

  it('keeps the current reference when nothing changes', () => {
    useUiStore.getState().setSelection(['/a']);
    const before = useUiStore.getState().multiSelection;
    useUiStore.getState().setSelection(['/a']);
    expect(useUiStore.getState().multiSelection).toBe(before);
  });

  it('reuses the shared empty set so ids stay stable', () => {
    useUiStore.getState().setSelection(['/a']);
    useUiStore.getState().clearSelection();
    expect(useUiStore.getState().multiSelection).toBe(EMPTY_MULTI_SELECTION);
    useUiStore.getState().clearSelection();
    expect(useUiStore.getState().multiSelection).toBe(EMPTY_MULTI_SELECTION);
    useUiStore.getState().setSelection([]);
    expect(useUiStore.getState().multiSelection).toBe(EMPTY_MULTI_SELECTION);
  });

  it('toggles a path in and out', () => {
    useUiStore.getState().toggleSelection('/a');
    expect(useUiStore.getState().multiSelection.has('/a')).toBe(true);
    useUiStore.getState().toggleSelection('/a');
    expect(useUiStore.getState().multiSelection.size).toBe(0);
  });

  it('ignores an empty path', () => {
    useUiStore.getState().toggleSelection('');
    expect(useUiStore.getState().multiSelection.size).toBe(0);
  });
});

describe('toasts', () => {
  it('adds and removes a toast', () => {
    const id = useUiStore.getState().addToast({ type: 'info', title: 'hello', duration: 0 });
    expect(useUiStore.getState().toasts).toHaveLength(1);
    useUiStore.getState().removeToast(id);
    expect(useUiStore.getState().toasts).toHaveLength(0);
  });

  it('ignores an unknown toast id', () => {
    useUiStore.getState().addToast({ type: 'info', title: 'hello', duration: 0 });
    useUiStore.getState().removeToast('toast-missing');
    expect(useUiStore.getState().toasts).toHaveLength(1);
  });

  it('removes itself after the duration', () => {
    vi.useFakeTimers();
    useUiStore.getState().addToast({ type: 'info', title: 'hello', duration: 100 });
    expect(useUiStore.getState().toasts).toHaveLength(1);
    vi.advanceTimersByTime(150);
    expect(useUiStore.getState().toasts).toHaveLength(0);
    vi.useRealTimers();
  });
});
