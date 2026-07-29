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
  targz: boolean
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

export interface SearchResult {
  files: FileEntry[]
  total: number
}

export interface ErrorLogEntry {
  message: string
  type: 'fatal' | 'warning' | 'notice' | 'deprecated' | 'info'
}

export interface ErrorLogData {
  found: boolean
  path: string | null
  entries: ErrorLogEntry[]
  size?: number
}

export interface InstallCheckItem {
  name: string
  pass: boolean
  value: string
  required: string
}

export interface InstallCheckData {
  pass: boolean
  checks: InstallCheckItem[]
  disabledFunctions: string[]
}

export interface UploadChunkRequest {
  target: string
  fileName: string
  uploadId: string
  chunkIndex: number
  totalChunks: number
  chunk: string
}

export interface UploadChunkResponse {
  success: boolean
  merged: boolean
  progress: string
  received: number
  totalChunks: number
}

export interface UploadProgress {
  loaded: number
  total: number
  percentage: number
}

export interface UploadedFile {
  name: string
  size: number
}

export interface UploadResult {
  success: boolean
  files: UploadedFile[]
  errors?: Array<{ name: string; error: string }>
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

export interface SqlExportRequest {
  connId: string
  tables?: string[]
  mode: 'structure_only' | 'structure_data'
}

export interface SqlImportResult {
  success: boolean
  executed: number
  failed: number
  errors: string[]
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

export interface HealthCheckItem {
  name: string
  currentValue: string
  recommendedValue: string
  status: 'pass' | 'warning' | 'danger'
  description: string
}

export interface CompatibilityItem {
  name: string
  pass: boolean
  requirements: string[]
  missing: string[]
}

export interface HealthCheckData {
  security: HealthCheckItem[]
  performance: HealthCheckItem[]
  compatibility: CompatibilityItem[]
  summary: { pass: number; warning: number; danger: number; total: number }
}

export interface SystemData {
  diskTotal: number
  diskFree: number
  diskUsed: number
  loadAverage?: number[]
  uptime?: number
  serverAddr?: string
  serverName?: string
  webServer?: string | null
  memTotal?: number | null
  memAvailable?: number | null
  memUsed?: number | null
  memPercent?: number | null
}

export interface ProcessInfo {
  pid: number
  name: string
  cmdline: string
  cpu: number | null
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

export interface HtaccessData {
  content: string
  path: string
  writable: boolean
  exists: boolean
}

export interface HtaccessRule {
  id: string
  name: string
  description: string
}

export type HtaccessRuleType =
  | 'force_https'
  | 'block_sensitive'
  | 'prevent_hotlink'
  | 'redirect_301'
  | 'gzip_compress'
  | 'browser_cache'
  | 'block_dir_browsing'

export interface HtaccessGenerateRequest {
  rules: HtaccessRuleType[]
  from?: string
  to?: string
}

export interface HtaccessGenerateResult {
  content: string
  rules: string[]
}

export interface DiskDirectory {
  name: string
  path: string
  size: number
  fileCount: number
  percent: number
}

export interface DiskAnalysisData {
  directories: DiskDirectory[]
  totalSize: number
  diskTotal: number
  diskFree: number
}

export interface LargeFile {
  name: string
  path: string
  size: number
  modified: string
}

export interface LargeFilesData {
  files: LargeFile[]
  total: number
}

export type ThemeMode = 'light' | 'dark' | 'system'
export type Language = 'zh' | 'en'

export type Breakpoint = 'mobile' | 'tablet' | 'desktop'
