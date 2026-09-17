import { describe, expect, it } from 'vitest';
import { formatFileSize, validateFileName } from '@/lib/validate';

describe('validateFileName', () => {
  it('rejects empty names', () => {
    expect(validateFileName('')).toEqual({ valid: false, error: 'files.nameRequired' });
    expect(validateFileName('   ')).toEqual({ valid: false, error: 'files.nameRequired' });
  });

  it('rejects reserved path separators and shell characters', () => {
    for (const name of ['a/b', 'a\\b', 'a:b', 'a*b', 'a?b', 'a"b', 'a<b', 'a>b', 'a|b']) {
      expect(validateFileName(name), name).toEqual({ valid: false, error: 'files.nameInvalid' });
    }
  });

  it('rejects dot entries', () => {
    expect(validateFileName('.')).toEqual({ valid: false, error: 'files.nameInvalid' });
    expect(validateFileName('..')).toEqual({ valid: false, error: 'files.nameInvalid' });
  });

  it('accepts regular names', () => {
    expect(validateFileName('report.pdf')).toEqual({ valid: true });
    expect(validateFileName('my folder')).toEqual({ valid: true });
    expect(validateFileName('a-b_c.txt')).toEqual({ valid: true });
    expect(validateFileName('name with (brackets)')).toEqual({ valid: true });
  });

  it('never reports an error for a valid name', () => {
    const result = validateFileName('ok.txt');
    expect(result.valid).toBe(true);
    expect(result.error).toBeUndefined();
  });
});

describe('formatFileSize', () => {
  it('formats zero', () => {
    expect(formatFileSize(0)).toBe('0 B');
  });

  it('formats the supported units', () => {
    expect(formatFileSize(512)).toBe('512 B');
    expect(formatFileSize(1024)).toBe('1 KB');
    expect(formatFileSize(1024 ** 2)).toBe('1 MB');
    expect(formatFileSize(1024 ** 3)).toBe('1 GB');
    expect(formatFileSize(1024 ** 4)).toBe('1 TB');
  });

  it('rounds to one decimal', () => {
    expect(formatFileSize(1536)).toBe('1.5 KB');
    expect(formatFileSize(5 * 1024 ** 2)).toBe('5 MB');
  });
});
