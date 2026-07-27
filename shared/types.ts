export interface ApiResponse<T> {
  ok: boolean
  data?: T
  error?: {
    code: string
    message: string
  }
}

export interface Capabilities {
  disk: boolean
  mysql: boolean
  terminal: boolean
  processes: boolean
  cron: boolean
  zip: boolean
  gd: boolean
  openBasedir: string | false
  disabledFunctions: string[]
  phpVersion: string
  sapi: string
  maxUpload: number
  maxPost: number
  memoryLimit: number
}

export interface BootstrapData {
  authenticated: boolean
  installed: boolean
  csrfToken: string
  capabilities: Capabilities
  backendVersion: string
  frontendVersion: string
  user?: {
    username: string
  }
  settings?: UserSettings
  accessToken?: string
}

export interface UserSettings {
  theme: 'light' | 'dark' | 'system'
  language: 'zh' | 'en'
  sessionTimeout: number
  accessToken?: string
}

export interface FileEntry {
  name: string
  path: string
  type: 'file' | 'dir' | 'link'
  size: number
  mtime: number
  perms: string
  readable: boolean
  writable: boolean
}

export interface FileContent {
  type: 'text' | 'image' | 'binary'
  content: string
  size: number
  mime?: string
  encoding?: string
  lines?: number
  truncated?: boolean
}

export interface FileOperationRequest {
  action: 'create_file' | 'create_dir' | 'rename' | 'delete' | 'copy' | 'move' | 'chmod'
  path: string
  target?: string
  perms?: string
  recursive?: boolean
  content?: string
}

export interface UploadChunkRequest {
  path: string
  filename: string
  chunk: number
  totalChunks: number
  size: number
}

export interface DbConnection {
  id: string
  name: string
  host: string
  port: number
  username: string
  database: string
}

export interface DbConnectionInput {
  name: string
  host: string
  port: number
  username: string
  password: string
  database: string
}

export interface DbTable {
  name: string
  engine: string
  rows: number
  size: number
  collation: string
  comment: string
}

export interface DbColumn {
  name: string
  type: string
  nullable: boolean
  key: string
  default: string | null
  extra: string
}

export interface SqlResult {
  success: boolean
  statement: string
  rows?: Record<string, unknown>[]
  affectedRows?: number
  error?: string
}

export interface SqlExecResponse {
  results: SqlResult[]
  executionTime: number
}

export interface DashboardData {
  phpVersion: string
  sapi: string
  webServer: string
  hostname: string
  timezone: string
  now: number
  diskTotal: number
  diskFree: number
  diskUsed: number
  rootPath: string
  fileCount: number
  totalSize: number
  maxUpload: number
  maxPost: number
  memoryLimit: number
  recentFiles: FileEntry[]
}

export interface PhpInfoData {
  version: string
  sapi: string
  iniFile: string
  loadedExtensions: string[]
  coreIni: Record<string, string>
  env: Record<string, string>
  server: Record<string, string>
}

export interface SystemData {
  diskTotal: number
  diskFree: number
  diskUsed: number
  loadAverage?: number[]
  uptime?: number
  serverAddr?: string
  serverName?: string
}

export interface ProcessInfo {
  pid: number
  name: string
  cmdline: string
  cpu: number
  mem: number
}

export interface CronJob {
  minute: string
  hour: string
  day: string
  month: string
  weekday: string
  command: string
  raw: string
}

export interface AuthCredentials {
  username: string
  password: string
  totp?: string
}

export interface InstallRequest {
  password: string
  rootPath?: string
}

export type ThemeMode = 'light' | 'dark' | 'system'
export type Language = 'zh' | 'en'

export type Breakpoint = 'mobile' | 'tablet' | 'desktop'
