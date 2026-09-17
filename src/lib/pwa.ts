export type PushFallbackReason =
  | 'unsupported'
  | 'insecure_context'
  | 'no_service_worker'
  | 'no_push_manager'
  | 'permission_denied'
  | 'subscribe_failed'
  | 'no_application_server_key'

export interface PushSupport {
  supported: boolean
  reason?: PushFallbackReason
  permission: NotificationPermission | 'unsupported'
}

export interface PushSubscriptionResult {
  ok: boolean
  reason?: PushFallbackReason
  subscription?: PushSubscription
}

export interface ServiceWorkerRegistrationResult {
  registration: ServiceWorkerRegistration | null
  reason?: PushFallbackReason
}

function getNavigator(): Navigator | null {
  if (typeof navigator === 'undefined') return null
  return navigator
}

function getWindow(): Window | null {
  if (typeof window === 'undefined') return null
  return window
}

export function isSecureContextAvailable(): boolean {
  const win = getWindow()
  if (!win) return false
  if (win.isSecureContext) return true
  const location = win.location
  return !!location && (location.protocol === 'https:' || location.hostname === 'localhost')
}

export function getServiceWorkerContainer(): ServiceWorkerContainer | null {
  const nav = getNavigator() as (Navigator & { serviceWorker?: ServiceWorkerContainer }) | null
  if (!nav || !nav.serviceWorker) return null
  return nav.serviceWorker
}

export function getNotificationApi(): typeof Notification | null {
  const win = getWindow() as (Window & typeof globalThis) | null
  if (!win || typeof win.Notification === 'undefined') return null
  return win.Notification
}

export function serviceWorkerUrl(baseUrl?: string): string {
  const base = baseUrl ?? (typeof import.meta !== 'undefined' ? import.meta.env?.BASE_URL : undefined) ?? '/'
  const normalized = base.endsWith('/') ? base : `${base}/`
  return `${normalized}sw.js`
}

export function getPushSupport(): PushSupport {
  const notificationApi = getNotificationApi()
  if (!notificationApi) {
    return { supported: false, reason: 'unsupported', permission: 'unsupported' }
  }
  if (!isSecureContextAvailable()) {
    return { supported: false, reason: 'insecure_context', permission: notificationApi.permission }
  }
  const container = getServiceWorkerContainer()
  if (!container) {
    return { supported: false, reason: 'no_service_worker', permission: notificationApi.permission }
  }
  const win = getWindow() as (Window & typeof globalThis & { PushManager?: unknown }) | null
  if (!win || !('PushManager' in win) || typeof win.PushManager === 'undefined') {
    return { supported: false, reason: 'no_push_manager', permission: notificationApi.permission }
  }
  return { supported: true, permission: notificationApi.permission }
}

export async function registerServiceWorker(url?: string): Promise<ServiceWorkerRegistrationResult> {
  const container = getServiceWorkerContainer()
  if (!container) return { registration: null, reason: 'no_service_worker' }
  if (!isSecureContextAvailable()) return { registration: null, reason: 'insecure_context' }
  try {
    const registration = await container.register(url ?? serviceWorkerUrl())
    return { registration }
  } catch {
    return { registration: null, reason: 'unsupported' }
  }
}

export async function requestPushPermission(): Promise<NotificationPermission | 'unsupported'> {
  const notificationApi = getNotificationApi()
  if (!notificationApi) return 'unsupported'
  if (notificationApi.permission !== 'default') return notificationApi.permission
  try {
    return await notificationApi.requestPermission()
  } catch {
    return 'unsupported'
  }
}

export function urlBase64ToUint8Array(base64: string): Uint8Array {
  const padding = '='.repeat((4 - (base64.length % 4)) % 4)
  const normalized = (base64 + padding).replace(/-/g, '+').replace(/_/g, '/')
  const raw = typeof atob === 'function' ? atob(normalized) : ''
  const output = new Uint8Array(raw.length)
  for (let i = 0; i < raw.length; i += 1) {
    output[i] = raw.charCodeAt(i)
  }
  return output
}

export async function subscribeToPush(
  applicationServerKey?: string,
): Promise<PushSubscriptionResult> {
  const support = getPushSupport()
  if (!support.supported) return { ok: false, reason: support.reason }

  const key =
    applicationServerKey ??
    (typeof import.meta !== 'undefined' ? import.meta.env?.VITE_VAPID_PUBLIC_KEY : undefined)
  if (!key) return { ok: false, reason: 'no_application_server_key' }

  const permission = support.permission === 'default' ? await requestPushPermission() : support.permission
  if (permission !== 'granted') return { ok: false, reason: 'permission_denied' }

  try {
    const registration = await getServiceWorkerContainer()!.register(serviceWorkerUrl())
    const existing = await registration.pushManager.getSubscription()
    if (existing) return { ok: true, subscription: existing }
    const subscription = await registration.pushManager.subscribe({
      userVisibleOnly: true,
      applicationServerKey: urlBase64ToUint8Array(key) as BufferSource,
    })
    return { ok: true, subscription }
  } catch {
    return { ok: false, reason: 'subscribe_failed' }
  }
}
