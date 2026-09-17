import { describe, expect, it } from 'vitest';
import { applyDocumentLanguage, htmlLangForLanguage } from '@/lib/locale';

describe('htmlLangForLanguage', () => {
  it('maps the supported languages onto html lang codes', () => {
    expect(htmlLangForLanguage('zh')).toBe('zh-CN');
    expect(htmlLangForLanguage('en')).toBe('en');
  });
});

describe('applyDocumentLanguage', () => {
  it('writes the language onto the document element', () => {
    applyDocumentLanguage('en');
    expect(document.documentElement.getAttribute('lang')).toBe('en');
    applyDocumentLanguage('zh');
    expect(document.documentElement.getAttribute('lang')).toBe('zh-CN');
  });

  it('leaves the attribute alone when it already matches', () => {
    applyDocumentLanguage('en');
    const before = document.documentElement.getAttribute('lang');
    applyDocumentLanguage('en');
    expect(document.documentElement.getAttribute('lang')).toBe(before);
  });
});
