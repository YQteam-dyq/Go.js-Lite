import { beforeEach, describe, expect, it } from 'vitest';
import { getDefaultCaps, useAuthStore } from '@/stores/authStore';
import { VERSION } from '@shared/version';
import type { BootstrapData, Capabilities } from '@shared/types';

const capabilities: Capabilities = {
  disk: true,
  mysql: true,
  terminal: true,
  processes: true,
  cron: true,
  zip: true,
  targz: true,
  gd: true,
  openBasedir: '/srv',
  disabledFunctions: ['exec'],
  phpVersion: '8.4.1',
  sapi: 'fpm-fcgi',
  maxUpload: 32,
  maxPost: 64,
  memoryLimit: 256,
};

const bootstrap: BootstrapData = {
  authenticated: true,
  installed: true,
  csrfToken: 'csrf-abc',
  capabilities,
  backendVersion: '0.8.0',
  frontendVersion: '0.8.1',
  user: { id: 7, username: 'admin', role: 'admin', path_allowlist: ['/srv'] },
};

const initialState = {
  bootstrapped: false,
  bootstrapFailed: false,
  authenticated: false,
  installed: true,
  loading: true,
  csrfToken: '',
  capabilities: null,
  user: null,
  backendVersion: '',
};

beforeEach(() => {
  useAuthStore.setState({ ...initialState });
});

describe('useAuthStore', () => {
  it('starts unauthenticated and loading', () => {
    const state = useAuthStore.getState();
    expect(state.bootstrapped).toBe(false);
    expect(state.bootstrapFailed).toBe(false);
    expect(state.authenticated).toBe(false);
    expect(state.installed).toBe(true);
    expect(state.loading).toBe(true);
    expect(state.csrfToken).toBe('');
    expect(state.capabilities).toBeNull();
    expect(state.user).toBeNull();
    expect(state.backendVersion).toBe('');
  });

  it('falls back to the bundled version for the frontend version', () => {
    expect(useAuthStore.getState().frontendVersion).toBe(VERSION);
  });

  it('applies a bootstrap payload', () => {
    useAuthStore.getState().setBootstrap(bootstrap);

    const state = useAuthStore.getState();
    expect(state.bootstrapped).toBe(true);
    expect(state.bootstrapFailed).toBe(false);
    expect(state.loading).toBe(false);
    expect(state.authenticated).toBe(true);
    expect(state.installed).toBe(true);
    expect(state.csrfToken).toBe('csrf-abc');
    expect(state.capabilities).toEqual(capabilities);
    expect(state.user).toEqual({
      id: 7,
      username: 'admin',
      role: 'admin',
      path_allowlist: ['/srv'],
    });
    expect(state.backendVersion).toBe('0.8.0');
    expect(state.frontendVersion).toBe('0.8.1');
  });

  it('clears the user when the bootstrap payload has none', () => {
    useAuthStore.getState().setBootstrap({ ...bootstrap, user: undefined });
    expect(useAuthStore.getState().user).toBeNull();
  });

  it('keeps the bundled version when the backend reports no frontend version', () => {
    useAuthStore.getState().setBootstrap({ ...bootstrap, frontendVersion: '' });
    expect(useAuthStore.getState().frontendVersion).toBe(VERSION);
  });

  it('records an unauthenticated bootstrap', () => {
    useAuthStore.getState().setBootstrap({ ...bootstrap, authenticated: false });
    const state = useAuthStore.getState();
    expect(state.authenticated).toBe(false);
    expect(state.bootstrapped).toBe(true);
    expect(state.capabilities).toEqual(capabilities);
  });

  it('marks the bootstrap as failed without authenticating', () => {
    useAuthStore.getState().setBootstrapFailed();

    const state = useAuthStore.getState();
    expect(state.bootstrapped).toBe(true);
    expect(state.bootstrapFailed).toBe(true);
    expect(state.loading).toBe(false);
    expect(state.authenticated).toBe(false);
  });

  it('clears the failure flag on a later successful bootstrap', () => {
    useAuthStore.getState().setBootstrapFailed();
    useAuthStore.getState().setBootstrap(bootstrap);
    expect(useAuthStore.getState().bootstrapFailed).toBe(false);
  });

  it('toggles loading', () => {
    useAuthStore.getState().setLoading(false);
    expect(useAuthStore.getState().loading).toBe(false);
    useAuthStore.getState().setLoading(true);
    expect(useAuthStore.getState().loading).toBe(true);
  });

  it('resets the session but keeps the bootstrap state', () => {
    useAuthStore.getState().setBootstrap(bootstrap);
    useAuthStore.getState().reset();

    const state = useAuthStore.getState();
    expect(state.authenticated).toBe(false);
    expect(state.user).toBeNull();
    expect(state.csrfToken).toBe('');
    expect(state.bootstrapped).toBe(true);
    expect(state.capabilities).toEqual(capabilities);
    expect(state.backendVersion).toBe('0.8.0');
  });
});

describe('getDefaultCaps', () => {
  it('reports only disk access by default', () => {
    const caps = getDefaultCaps();
    expect(caps.disk).toBe(true);
    expect(caps.mysql).toBe(false);
    expect(caps.terminal).toBe(false);
    expect(caps.processes).toBe(false);
    expect(caps.cron).toBe(false);
    expect(caps.zip).toBe(false);
    expect(caps.targz).toBe(false);
    expect(caps.gd).toBe(false);
    expect(caps.openBasedir).toBe(false);
    expect(caps.disabledFunctions).toEqual([]);
    expect(caps.phpVersion).toBe('');
    expect(caps.sapi).toBe('');
    expect(caps.maxUpload).toBe(0);
    expect(caps.maxPost).toBe(0);
    expect(caps.memoryLimit).toBe(0);
  });
});
