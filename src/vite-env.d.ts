
interface ImportMetaEnv {
  readonly VITE_APP_VERSION: string
  readonly BASE_URL: string
  readonly PROD: boolean
  readonly DEV: boolean
  readonly VITE_VAPID_PUBLIC_KEY?: string
}

interface ImportMeta {
  readonly env: ImportMetaEnv
}
