const CACHE_VERSION = 'gojs-shell-v1';
const SHELL_CACHE = CACHE_VERSION + '-shell';
const ASSET_CACHE = CACHE_VERSION + '-asset';
const SHELL_ASSETS = ['./', './index.html', './favicon.svg', './manifest.webmanifest'];
const OFFLINE_URL = './offline.html';

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches
      .open(SHELL_CACHE)
      .then((cache) => cache.addAll(SHELL_ASSETS))
      .then(() => self.skipWaiting())
      .catch(() => self.skipWaiting()),
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches
      .keys()
      .then((keys) =>
        Promise.all(keys.filter((key) => !key.startsWith(CACHE_VERSION)).map((key) => caches.delete(key))),
      )
      .then(() => self.clients.claim()),
  );
});

function isApiRequest(url) {
  return url.pathname.indexOf('/api/') !== -1;
}

function isNavigationRequest(request) {
  return request.mode === 'navigate' || (request.headers.get('accept') || '').indexOf('text/html') !== -1;
}

async function handleNavigation(request) {
  try {
    const response = await fetch(request);
    if (response && response.status === 200) {
      const cache = await caches.open(SHELL_CACHE);
      await cache.put('./index.html', response.clone());
    }
    return response;
  } catch (error) {
    const cached = await caches.match('./index.html');
    if (cached) return cached;
    const offline = await caches.match(OFFLINE_URL);
    if (offline) return offline;
    return new Response('Offline', { status: 503, headers: { 'Content-Type': 'text/plain' } });
  }
}

async function handleAsset(request) {
  const cached = await caches.match(request);
  if (cached) return cached;
  try {
    const response = await fetch(request);
    if (response && response.status === 200 && response.type === 'basic') {
      const cache = await caches.open(ASSET_CACHE);
      cache.put(request, response.clone());
    }
    return response;
  } catch (error) {
    return new Response('Offline', { status: 503, headers: { 'Content-Type': 'text/plain' } });
  }
}

async function handleApi(request) {
  try {
    return await fetch(request);
  } catch (error) {
    return new Response(
      JSON.stringify({ ok: false, error: { code: 'offline', message: 'The network is unavailable.' } }),
      { status: 503, headers: { 'Content-Type': 'application/json' } },
    );
  }
}

self.addEventListener('fetch', (event) => {
  const request = event.request;
  if (request.method !== 'GET') return;
  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return;

  if (isApiRequest(url)) {
    event.respondWith(handleApi(request));
    return;
  }
  if (isNavigationRequest(request)) {
    event.respondWith(handleNavigation(request));
    return;
  }
  event.respondWith(handleAsset(request));
});

self.addEventListener('push', (event) => {
  let payload = {};
  try {
    payload = event.data ? event.data.json() : {};
  } catch (error) {
    payload = { title: event.data ? event.data.text() : '' };
  }
  const title = payload.title || 'Go.js';
  const options = {
    body: payload.body || payload.message || '',
    tag: payload.tag || 'gojs-notification',
    data: { url: payload.url || './' },
    icon: './favicon.svg',
    badge: './favicon.svg',
  };
  event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const target = (event.notification.data && event.notification.data.url) || './';
  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientList) => {
      for (const client of clientList) {
        if ('focus' in client) {
          client.navigate(target);
          return client.focus();
        }
      }
      if (self.clients.openWindow) return self.clients.openWindow(target);
      return undefined;
    }),
  );
});
