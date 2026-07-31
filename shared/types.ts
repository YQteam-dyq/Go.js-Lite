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
  logRetention?: number
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

export interface OperationLogEntry {
  time: string
  timestamp: number
  ip: string
  action: string
  target: string
  result: boolean
  detail: string
}

export interface OperationLogData {
  logs: OperationLogEntry[]
  total: number
  page: number
  per_page: number
  total_pages: number
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

export interface EnvCheckItem {
  name: string
  category: 'extension' | 'function' | 'system' | 'config'
  available: boolean
  /** 向后兼容：原始原因文本（已过时，优先使用 reason_key + reason_params） */
  reason: string
  /** 向后兼容：原始关联功能文本（已过时，优先使用 feature_key） */
  related_feature: string
  /** 向后兼容：原始建议文本（已过时，优先使用 suggestion_key + suggestion_params） */
  suggestion: string
  feature_key?: string
  reason_key?: string
  reason_params?: Record<string, string | number> | null
  suggestion_key?: string
  suggestion_params?: Record<string, string | number> | null
}

export interface EnvCheckSummary {
  total: number
  passed: number
  failed: number
}

export interface EnvCheckData {
  items: EnvCheckItem[]
  summary: EnvCheckSummary
}

export interface SSLInfo {
  domain: string
  enabled: boolean
  issuer?: string
  subject?: string
  valid_from?: string
  valid_to?: string
  days_remaining?: number
  chain_complete?: boolean
  /** 证书健康状态：正常 / 即将到期 / 紧急 / 已过期 */
  cert_status?: 'ok' | 'warning' | 'critical' | 'expired'
  /** 检测执行状态：待检测 / 检测成功 / 检测失败 / 检测中 */
  status: 'pending' | 'ok' | 'failed' | 'checking'
  /** 向后兼容：原始错误码 / 错误文本（已过时，优先使用 error_key + error_params） */
  error?: string
  /** 向后兼容：原始错误详情文本（已过时，优先使用 error_key + error_params） */
  message?: string
  error_key?: string
  error_params?: Record<string, string | number> | null
}

export interface SSLListResponse {
  domains: string[]
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

export interface CronCapabilities {
  available: boolean
  exec_available: boolean
  crontab_available: boolean
  method?: 'exec' | 'file' | 'none'
  cron_file?: string
  message: string
  message_key?: string
  message_params?: Record<string, string | number> | null
  info_key?: string
  info_params?: Record<string, string | number>
}

export interface CronJob {
  expression: string
  command: string
  raw?: string
}

export interface CronListResponse {
  jobs: CronJob[]
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

export interface BackupDbMeta {
  id: string
  name: string
  database: string
  size: number
}

export interface BackupMetadata {
  created_at: string
  version: string
  files: { count: number; root: string } | null
  databases: BackupDbMeta[]
  config: boolean
  db_error?: string[] | string
}

export interface BackupRecord {
  filename: string
  size: number
  created: number
  metadata: BackupMetadata | null
}

export interface BackupListResponse {
  backups: BackupRecord[]
}

export interface BackupCreateRequest {
  include_files?: boolean
  include_db?: boolean
  include_config?: boolean
  exclude_dirs?: string[]
}

export interface BackupCreateResult {
  filename: string
  size: number
  metadata: BackupMetadata
}

export interface BackupRestoreResult {
  restored_files: number
  restored_db: number
  db_errors: string[]
}

export type ThemeMode = 'light' | 'dark' | 'system'
export type Language = 'zh' | 'en'

export type Breakpoint = 'mobile' | 'tablet' | 'desktop'
