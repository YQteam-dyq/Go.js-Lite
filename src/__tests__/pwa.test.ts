import { afterEach, describe, expect, it, vi } from 'vitest';
import {
  getPushSupport,
  getServiceWorkerContainer,
  isSecureContextAvailable,
  registerServiceWorker,
  requestPushPermission,
  serviceWorkerUrl,
  subscribeToPush,
  urlBase64ToUint8Array,
} from '@/lib/pwa';

interface BrowserOptions {
  notification?: boolean;
  permission?: string;
  serviceWorker?: unknown;
  pushManager?: boolean;
}

function installBrowser(options: BrowserOptions = {}): void {
  const win = window as unknown as Record<string, unknown>;
  if (options.notification === false) {
    delete win.Notification;
  } else {
    win.Notification = {
      permission: options.permission ?? 'granted',
      requestPermission: vi.fn(async () => options.permission ?? 'granted'),
    };
  }
  Object.defineProperty(navigator, 'serviceWorker', {
    value: options.serviceWorker,
    configurable: true,
    writable: true,
  });
  if (options.pushManager) win.PushManager = function PushManager() {};
  else delete win.PushManager;
}

afterEach(() => {
  const win = window as unknown as Record<string, unknown>;
  delete win.Notification;
  delete win.PushManager;
  Object.defineProperty(navigator, 'serviceWorker', {
    value: undefined,
    configurable: true,
    writable: true,
  });
});

describe('capability detection', () => {
  it('reports unsupported when notifications are missing', () => {
    installBrowser({ notification: false });
    const support = getPushSupport();
    expect(support.supported).toBe(false);
    expect(support.reason).toBe('unsupported');
    expect(support.permission).toBe('unsupported');
  });

  it('reports unsupported when the service worker is missing', () => {
    installBrowser({ permission: 'default' });
    const support = getPushSupport();
    expect(support.supported).toBe(false);
    expect(support.reason).toBe('no_service_worker');
    expect(support.permission).toBe('default');
    expect(getServiceWorkerContainer()).toBeNull();
  });

  it('reports unsupported when the push manager is missing', () => {
    installBrowser({ serviceWorker: {} });
    expect(getPushSupport().reason).toBe('no_push_manager');
  });

  it('reports supported when every api is present', () => {
    installBrowser({ serviceWorker: {}, pushManager: true, permission: 'granted' });
    const support = getPushSupport();
    expect(support.supported).toBe(true);
    expect(support.permission).toBe('granted');
  });

  it('treats localhost as a secure context', () => {
    expect(isSecureContextAvailable()).toBe(true);
  });

  it('builds the service worker url from the base path', () => {
    expect(serviceWorkerUrl('/gojs/')).toBe('/gojs/sw.js');
    expect(serviceWorkerUrl('/gojs')).toBe('/gojs/sw.js');
  });
});

describe('registration', () => {
  it('skips registration without a service worker container', async () => {
    installBrowser({});
    const result = await registerServiceWorker();
    expect(result.registration).toBeNull();
    expect(result.reason).toBe('no_service_worker');
  });

  it('returns the registration on success', async () => {
    const registration = { scope: '/gojs/' };
    installBrowser({ serviceWorker: { register: vi.fn(async () => registration) } });
    const result = await registerServiceWorker('/gojs/sw.js');
    expect(result.registration).toBe(registration);
  });

  it('reports a failure instead of throwing', async () => {
    installBrowser({
      serviceWorker: {
        register: vi.fn(async () => {
          throw new Error('nope');
        }),
      },
    });
    const result = await registerServiceWorker();
    expect(result.registration).toBeNull();
    expect(result.reason).toBe('unsupported');
  });
});

describe('permission', () => {
  it('returns unsupported without the notification api', async () => {
    installBrowser({ notification: false });
    expect(await requestPushPermission()).toBe('unsupported');
  });

  it('reuses an already decided permission', async () => {
    installBrowser({ permission: 'denied' });
    expect(await requestPushPermission()).toBe('denied');
  });

  it('asks when the permission is still open', async () => {
    installBrowser({ permission: 'default' });
    expect(await requestPushPermission()).toBe('default');
  });
});

describe('subscribeToPush', () => {
  it('reports the missing server key', async () => {
    installBrowser({ serviceWorker: {}, pushManager: true });
    const result = await subscribeToPush();
    expect(result.ok).toBe(false);
    expect(result.reason).toBe('no_application_server_key');
  });

  it('reports the unsupported browser before anything else', async () => {
    installBrowser({ notification: false });
    const result = await subscribeToPush('AAAAAAAAAAB');
    expect(result.reason).toBe('unsupported');
  });

  it('stops when the permission is denied', async () => {
    installBrowser({ serviceWorker: {}, pushManager: true, permission: 'denied' });
    const result = await subscribeToPush('AAAAAAAAAAB');
    expect(result.reason).toBe('permission_denied');
  });

  it('creates a subscription', async () => {
    const subscription = { endpoint: 'https://push.test/1' };
    const subscribe = vi.fn(async () => subscription);
    const register = vi.fn(async () => ({
      pushManager: { getSubscription: vi.fn(async () => null), subscribe },
    }));
    installBrowser({ serviceWorker: { register }, pushManager: true });

    const result = await subscribeToPush('AAAAAAAAAAB');
    expect(result.ok).toBe(true);
    expect(result.subscription).toBe(subscription);
    expect(subscribe).toHaveBeenCalledTimes(1);
  });

  it('reuses an existing subscription', async () => {
    const subscription = { endpoint: 'https://push.test/existing' };
    const subscribe = vi.fn();
    const register = vi.fn(async () => ({
      pushManager: { getSubscription: vi.fn(async () => subscription), subscribe },
    }));
    installBrowser({ serviceWorker: { register }, pushManager: true });

    const result = await subscribeToPush('AAAAAAAAAAB');
    expect(result.ok).toBe(true);
    expect(result.subscription).toBe(subscription);
    expect(subscribe).not.toHaveBeenCalled();
  });

  it('reports a failed subscription instead of throwing', async () => {
    const register = vi.fn(async () => {
      throw new Error('boom');
    });
    installBrowser({ serviceWorker: { register }, pushManager: true });
    const result = await subscribeToPush('AAAAAAAAAAB');
    expect(result.ok).toBe(false);
    expect(result.reason).toBe('subscribe_failed');
  });
});

describe('urlBase64ToUint8Array', () => {
  it('decodes base64 and base64url input', () => {
    expect(Array.from(urlBase64ToUint8Array('AAAA'))).toEqual([0, 0, 0]);
    expect(urlBase64ToUint8Array('AAAAAAAAAAB').length).toBe(8);
    expect(Array.from(urlBase64ToUint8Array('-_-_'))).toEqual(
      Array.from(urlBase64ToUint8Array('+/+/')),
    );
  });
});
