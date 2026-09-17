import { describe, expect, it } from 'vitest';
import { joinPath, parentPath, patchEntry, removeEntries, renameEntry, type FileListPayload } from '@/lib/optimistic';
import type { FileEntry } from '@shared/types';

function entry(path: string, type: FileEntry['type'] = 'file'): FileEntry {
  const name = path.split('/').filter(Boolean).pop() ?? '';
  return { name, path, type, size: 10, mtime: 1700000000, perms: '0644', readable: true, writable: true };
}

function payload(paths: string[]): FileListPayload {
  return { path: '/root', files: paths.map((p) => entry(p)) };
}

describe('path helpers', () => {
  it('resolves the parent path', () => {
    expect(parentPath('/root/child.txt')).toBe('/root');
    expect(parentPath('/top.txt')).toBe('/');
    expect(parentPath('/')).toBe('/');
  });

  it('joins a name onto a parent', () => {
    expect(joinPath('/root', 'a.txt')).toBe('/root/a.txt');
    expect(joinPath('/', 'a.txt')).toBe('/a.txt');
    expect(joinPath('', 'a.txt')).toBe('/a.txt');
    expect(joinPath('/root/', 'a.txt')).toBe('/root/a.txt');
  });
});

describe('removeEntries', () => {
  it('drops the requested paths', () => {
    const data = payload(['/root/a.txt', '/root/b.txt']);
    const next = removeEntries(data, ['/root/a.txt']);
    expect(next?.files.map((f) => f.path)).toEqual(['/root/b.txt']);
  });

  it('keeps the same reference when nothing matches', () => {
    const data = payload(['/root/a.txt']);
    expect(removeEntries(data, ['/root/missing.txt'])).toBe(data);
    expect(removeEntries(data, [])).toBe(data);
  });

  it('tolerates missing data', () => {
    expect(removeEntries(undefined, ['/root/a.txt'])).toBeUndefined();
  });
});

describe('renameEntry', () => {
  it('rewrites the name and the path', () => {
    const data = payload(['/root/a.txt']);
    const next = renameEntry(data, '/root/a.txt', 'b.txt');
    expect(next?.files[0].name).toBe('b.txt');
    expect(next?.files[0].path).toBe('/root/b.txt');
  });

  it('renames an entry at the root', () => {
    const data = payload(['/a.txt']);
    expect(renameEntry(data, '/a.txt', 'b.txt')?.files[0].path).toBe('/b.txt');
  });

  it('keeps the same reference when the path is unknown', () => {
    const data = payload(['/root/a.txt']);
    expect(renameEntry(data, '/root/zzz.txt', 'b.txt')).toBe(data);
  });
});

describe('patchEntry', () => {
  it('merges the patch into the matching entry only', () => {
    const data = payload(['/root/a.txt', '/root/b.txt']);
    const next = patchEntry(data, '/root/b.txt', { perms: '0755' });
    expect(next?.files[0].perms).toBe('0644');
    expect(next?.files[1].perms).toBe('0755');
  });

  it('keeps the same reference when the path is unknown', () => {
    const data = payload(['/root/a.txt']);
    expect(patchEntry(data, '/root/zzz.txt', { perms: '0755' })).toBe(data);
  });
});
