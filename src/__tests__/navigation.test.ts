import { describe, expect, it } from 'vitest';
import { buildNavItems, filterCommands, isNavItemActive } from '@/lib/navigation';
import { getDefaultCaps } from '@/stores/authStore';
import { locales } from '@/i18n';

function walkKeys(value: unknown, prefix: string, out: Set<string>): void {
  if (value === null || typeof value !== 'object') {
    if (prefix) out.add(prefix);
    return;
  }
  for (const [key, child] of Object.entries(value as Record<string, unknown>)) {
    walkKeys(child, prefix ? `${prefix}.${key}` : key, out);
  }
}

const englishKeys = new Set<string>();
walkKeys(locales.en, '', englishKeys);

describe('buildNavItems', () => {
  it('keeps the routes unique', () => {
    const routes = buildNavItems({ caps: getDefaultCaps() }).map((item) => item.to);
    expect(new Set(routes).size).toBe(routes.length);
  });

  it('hides the admin routes for a non admin session', () => {
    const items = buildNavItems({ caps: getDefaultCaps(), role: 'viewer' });
    const hidden = ['/users', '/groups', '/approvals', '/php-ini'];
    for (const route of hidden) {
      expect(items.find((item) => item.to === route)?.show).toBe(false);
    }
  });

  it('shows the admin routes for an admin session', () => {
    const items = buildNavItems({ caps: getDefaultCaps(), role: 'admin' });
    const shown = ['/users', '/groups', '/approvals', '/php-ini'];
    for (const route of shown) {
      expect(items.find((item) => item.to === route)?.show).toBe(true);
    }
  });

  it('hides the database route when mysql is unavailable', () => {
    const caps = { ...getDefaultCaps(), mysql: false };
    expect(buildNavItems({ caps }).find((item) => item.to === '/db')?.show).toBe(false);
    expect(buildNavItems({ caps: { ...caps, mysql: true } }).find((item) => item.to === '/db')?.show).toBe(
      true,
    );
  });

  it('points every label at an existing english message', () => {
    for (const item of buildNavItems({ caps: getDefaultCaps(), role: 'admin' })) {
      expect(englishKeys.has(item.labelKey), item.labelKey).toBe(true);
    }
  });
});

describe('isNavItemActive', () => {
  it('matches the exact route and its children', () => {
    const items = buildNavItems({ caps: getDefaultCaps() });
    const files = items.find((item) => item.to === '/files')!;
    expect(isNavItemActive(files, '/files')).toBe(true);
    expect(isNavItemActive(files, '/files/some/deep/path')).toBe(true);
    expect(isNavItemActive(files, '/filestream')).toBe(false);
  });

  it('honours a custom matcher', () => {
    const items = buildNavItems({ caps: { ...getDefaultCaps(), mysql: true } });
    const db = items.find((item) => item.to === '/db')!;
    expect(isNavItemActive(db, '/db/1/browse')).toBe(true);
    expect(isNavItemActive(db, '/dashboard')).toBe(false);
  });
});

describe('filterCommands', () => {
  const commands = [
    { label: 'Dashboard', keywords: '/dashboard' },
    { label: 'Disk Analysis', keywords: '/disk-analysis' },
    { label: 'Toggle theme' },
  ];

  it('returns everything for an empty query', () => {
    expect(filterCommands(commands, '   ')).toHaveLength(3);
  });

  it('matches the label case insensitively', () => {
    expect(filterCommands(commands, 'disk')).toHaveLength(1);
    expect(filterCommands(commands, 'DISK')).toHaveLength(1);
  });

  it('matches the keywords', () => {
    expect(filterCommands(commands, '/disk-analysis')).toHaveLength(1);
  });

  it('requires every term to match', () => {
    expect(filterCommands(commands, 'disk analysis')).toHaveLength(1);
    expect(filterCommands(commands, 'disk missing')).toHaveLength(0);
  });
});
