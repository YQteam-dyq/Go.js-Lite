import { describe, expect, it } from 'vitest';
import {
  clampPercent,
  deriveOverallStatus,
  usageLevel,
  usagePercent,
  type HealthSummary,
} from '@/lib/usage';

function summary(pass: number, warning: number, danger: number): HealthSummary {
  return { pass, warning, danger, total: pass + warning + danger };
}

describe('clampPercent', () => {
  it('keeps the value inside the range', () => {
    expect(clampPercent(-5)).toBe(0);
    expect(clampPercent(42)).toBe(42);
    expect(clampPercent(140)).toBe(100);
    expect(clampPercent(Number.NaN)).toBe(0);
  });
});

describe('usagePercent', () => {
  it('computes a percentage', () => {
    expect(usagePercent(50, 100)).toBe(50);
    expect(usagePercent(1, 3)).toBeCloseTo(33.33, 1);
  });

  it('returns zero when the total is unusable', () => {
    expect(usagePercent(10, 0)).toBe(0);
    expect(usagePercent(10, -1)).toBe(0);
    expect(usagePercent(10, Number.NaN)).toBe(0);
  });

  it('caps the result at one hundred', () => {
    expect(usagePercent(200, 100)).toBe(100);
  });
});

describe('usageLevel', () => {
  it('maps the percentage onto thresholds', () => {
    expect(usageLevel(10)).toBe('healthy');
    expect(usageLevel(75)).toBe('warning');
    expect(usageLevel(89.9)).toBe('warning');
    expect(usageLevel(90)).toBe('critical');
    expect(usageLevel(120)).toBe('critical');
  });

  it('accepts custom thresholds', () => {
    expect(usageLevel(50, 40, 60)).toBe('warning');
    expect(usageLevel(70, 40, 60)).toBe('critical');
  });
});

describe('deriveOverallStatus', () => {
  it('reports down when the panel does not answer', () => {
    expect(deriveOverallStatus({ reachable: false })).toBe('down');
    expect(deriveOverallStatus({ reachable: false, summary: summary(5, 0, 0) })).toBe('down');
  });

  it('reports unknown when no signal is available', () => {
    expect(deriveOverallStatus({ reachable: true })).toBe('unknown');
  });

  it('reports degraded on warnings and failures', () => {
    expect(deriveOverallStatus({ reachable: true, summary: summary(5, 1, 0) })).toBe('degraded');
    expect(deriveOverallStatus({ reachable: true, summary: summary(5, 0, 2) })).toBe('degraded');
  });

  it('reports degraded when storage crosses a threshold', () => {
    expect(deriveOverallStatus({ reachable: true, percent: 95 })).toBe('degraded');
    expect(deriveOverallStatus({ reachable: true, percent: 80 })).toBe('degraded');
  });

  it('reports operational when every signal is healthy', () => {
    expect(deriveOverallStatus({ reachable: true, summary: summary(5, 0, 0) })).toBe('operational');
    expect(deriveOverallStatus({ reachable: true, percent: 12 })).toBe('operational');
    expect(
      deriveOverallStatus({ reachable: true, summary: summary(5, 0, 0), percent: 12 }),
    ).toBe('operational');
  });
});
