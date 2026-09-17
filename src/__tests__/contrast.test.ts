import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';
import {
  contrastRatio,
  hslToRgb,
  meetsWcagAA,
  parseHslTriple,
  relativeLuminance,
} from '@/lib/contrast';

const css = readFileSync(resolve(process.cwd(), 'src/styles/index.css'), 'utf8');

function readTokens(selector: string): Map<string, string> {
  const start = css.indexOf(selector);
  const end = css.indexOf('}', start);
  const map = new Map<string, string>();
  if (start < 0 || end < 0) return map;
  for (const line of css.slice(start, end).split('\n')) {
    const match = line.match(/(--[\w-]+):\s*([\d.]+\s+[\d.]+%\s+[\d.]+%);/);
    if (match) map.set(match[1], match[2]);
  }
  return map;
}

const THEMES: Array<[string, Map<string, string>]> = [
  ['light', readTokens(':root {')],
  ['dark', readTokens('.dark {')],
];

const TEXT_ON_SURFACE: Array<[string, string]> = [
  ['--fg', '--bg'],
  ['--fg', '--bg-elevated'],
  ['--fg-muted', '--bg'],
  ['--fg-muted', '--bg-elevated'],
  ['--fg-subtle', '--bg'],
  ['--fg-subtle', '--bg-elevated'],
];

describe('contrast math', () => {
  it('converts hsl to rgb', () => {
    expect(hslToRgb({ h: 0, s: 0, l: 0 })).toEqual({ r: 0, g: 0, b: 0 });
    expect(hslToRgb({ h: 0, s: 0, l: 1 })).toEqual({ r: 255, g: 255, b: 255 });
    const red = hslToRgb({ h: 0, s: 1, l: 0.5 });
    expect(red.r).toBe(255);
    expect(red.g).toBe(0);
    expect(red.b).toBe(0);
  });

  it('computes the relative luminance', () => {
    expect(relativeLuminance({ r: 0, g: 0, b: 0 })).toBe(0);
    expect(relativeLuminance({ r: 255, g: 255, b: 255 })).toBeCloseTo(1, 5);
  });

  it('computes the contrast ratio', () => {
    const white = { r: 255, g: 255, b: 255 };
    const black = { r: 0, g: 0, b: 0 };
    expect(contrastRatio(white, black)).toBeCloseTo(21, 1);
    expect(contrastRatio(white, white)).toBeCloseTo(1, 5);
    expect(contrastRatio(white, black)).toBe(contrastRatio(black, white));
  });

  it('applies both wcag aa thresholds', () => {
    expect(meetsWcagAA(4.5)).toBe(true);
    expect(meetsWcagAA(4.49)).toBe(false);
    expect(meetsWcagAA(3, true)).toBe(true);
    expect(meetsWcagAA(2.99, true)).toBe(false);
  });

  it('parses the token format used by the stylesheet', () => {
    expect(parseHslTriple('220 25% 6%')).toEqual({ h: 220, s: 0.25, l: 0.06 });
    expect(parseHslTriple('168 100% 50%')).toEqual({ h: 168, s: 1, l: 0.5 });
    expect(parseHslTriple('#ffffff')).toBeNull();
    expect(parseHslTriple('220 25%')).toBeNull();
  });
});

describe('theme token contrast', () => {
  it('finds the design tokens in both themes', () => {
    for (const [name, tokens] of THEMES) {
      expect(tokens.size, name).toBeGreaterThan(20);
      expect(tokens.has('--fg-subtle'), name).toBe(true);
    }
  });

  for (const [name, tokens] of THEMES) {
    it(`keeps the ${name} theme text readable at WCAG AA`, () => {
      const failures: string[] = [];
      for (const [foreground, background] of TEXT_ON_SURFACE) {
        const fg = parseHslTriple(tokens.get(foreground) ?? '');
        const bg = parseHslTriple(tokens.get(background) ?? '');
        expect(fg, `${foreground} in ${name}`).not.toBeNull();
        expect(bg, `${background} in ${name}`).not.toBeNull();
        const ratio = contrastRatio(hslToRgb(fg!), hslToRgb(bg!));
        if (!meetsWcagAA(ratio)) {
          failures.push(`${foreground} on ${background} is ${ratio.toFixed(2)}:1`);
        }
      }
      expect(failures).toEqual([]);
    });
  }
});
