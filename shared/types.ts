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
  ftp?: boolean
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
  
  reason: string
  
  related_feature: string
  
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
  
  cert_status?: 'ok' | 'warning' | 'critical' | 'expired'
  
  status: 'pending' | 'ok' | 'failed' | 'checking'
  
  error?: string
  
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
  recovery_code?: string
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

export interface TotpStatus { enabled:boolean; hasSecret:boolean; recoveryCodesCount:number }
export interface TotpEnrollResponse { secret:string; otpauth_url:string; qr_svg_data_url:string; recovery_codes:string[] }
export type BackupDestinationType = 's3'|'ftp'|'sftp';
export interface BackupDestinationBase { id:string; name:string; type:BackupDestinationType; path_prefix?:string; created_at:number }
export interface BackupDestinationS3 extends BackupDestinationBase { type:'s3'; access_key_enc:string; secret_key_enc:string; endpoint:string; region?:string; bucket:string; sse?:boolean }
export interface BackupDestinationFtp extends BackupDestinationBase { type:'ftp'; host:string; port:number; username:string; password_enc:string; use_tls?:boolean }
export interface BackupDestinationSftp extends BackupDestinationBase { type:'sftp'; host:string; port:number; username:string; password_enc?:string; private_key_enc?:string }
export type BackupDestination = BackupDestinationS3 | BackupDestinationFtp | BackupDestinationSftp;
export interface RetentionRule { keep_last?:number; keep_daily?:number; keep_weekly?:number; keep_monthly?:number }
export interface BackupSchedule { id:string; name:string; enabled:boolean; source:{ include_files?:boolean; include_db?:boolean; include_config?:boolean; exclude_dirs?:string[] }; destination_ids:string[]; cron_expr:string; retention:RetentionRule; next_run_at:number; last_run_at?:number; created_at:number }
export type BackupRunStatus = 'success'|'running'|'failed';
export interface BackupRunRecord { id:string; schedule_id:string; started_at:number; ended_at?:number; status:BackupRunStatus; bytes_total:number; destination_results:{dest_id:string; ok:boolean; remote_path?:string; error?:string}[]; pruned_count:number }
export interface OperationLogAlertRule { id:string; name:string; enabled:boolean; when:{ action_in?:string[]; action_not_in?:string[]; ip_not_in_whitelist?:boolean; outside_hours_range?:string; consecutive_fail_login_gt_N?:number }; then:{ channel_ids:string[]; severity:'info'|'warning'|'critical' } }
export type NotificationChannelType = 'email'|'smtp'|'webhook';
export interface NotificationChannelBase { id:string; name:string; enabled:boolean; type:NotificationChannelType; created_at:number }
export interface NotificationChannelMail extends NotificationChannelBase { type:'email'; from_addr?:string; }
export interface NotificationChannelSmtp extends NotificationChannelBase { type:'smtp'; host:string; port:number; username?:string; password_enc?:string; from_addr:string; use_tls?:boolean }
export interface NotificationChannelWebhook extends NotificationChannelBase { type:'webhook'; url:string; method?:'POST'|'PUT'; headers_enc?:string }
export type NotificationChannel = NotificationChannelMail | NotificationChannelSmtp | NotificationChannelWebhook;
export type NotificationCategory = 'login_anomaly'|'backup'|'ssl'|'security'|'system';
export type NotificationSeverity = 'info'|'success'|'warning'|'critical';
export interface Notification { id:string; category:NotificationCategory; severity:NotificationSeverity; title_key:string; body_key?:string; body_params?:Record<string,string|number>; payload?:unknown; read_at?:number; created_at:number }
export type FtpProvider = 'proftpd_authfile' | 'pureftpd_passwd';

export interface FtpDegradationReason {
  code: string;
  key: string;
  severity: 'info' | 'warning' | 'danger';
}


export interface FtpLegacyDegradationReason {
  key: string;
  message_key: string;
}

export interface FtpCapabilities {
  available: boolean;
  reason_key?: string;
  degraded?: boolean;
  reasons?: FtpDegradationReason[];
  degradation_reasons?: FtpLegacyDegradationReason[];
  posix_available?: boolean;
  supported_providers: FtpProvider[];
  active_provider?: FtpProvider | null;
  path?: string;
  can_write?: boolean;
  default_uid?: number;
  default_gid?: number;
}

export interface FtpAccount {
  id: string;
  username: string;
  home_dir: string;
  uid?: number;
  gid?: number;
  quota_size_mb?: number | null;
  quota_files?: number | null;
  upload_bw_kbps?: number | null;
  download_bw_kbps?: number | null;
  allow_client_ips?: string;
  deny_client_ips?: string;
  enabled: boolean;
  expires_at_ts?: number | null;
  created_at: number;
  last_changed_at: number;
  last_login_at?: number | null;
}

export interface FtpAccountCreateInput {
  username: string;
  password: string;
  home_dir: string;
  uid?: number;
  gid?: number;
  quota_size_mb?: number | null;
  quota_files?: number | null;
  upload_bw_kbps?: number | null;
  download_bw_kbps?: number | null;
  allow_client_ips?: string;
  deny_client_ips?: string;
  enabled?: boolean;
  expires_at_ts?: number | null;
}

export interface FtpAccountUpdateInput extends Partial<FtpAccountCreateInput> {
  password_renew?: string;
}
export interface AcmeDomainState { domain:string; account_email?:string; staging:boolean; cert_status:'pending'|'valid'|'expiring'|'expired'|'failed'; last_issue_at?:number; next_renew_at?:number; error?:string }

export type AcmeCertStatus = 'pending' | 'valid' | 'invalid' | 'expiring_soon' | 'expired' | 'renew_failed'

export interface AcmeCertificateRecord {
  id: string
  domain: string
  status: AcmeCertStatus
  not_before_ts: number
  not_after_ts: number
  last_ordered_at?: number
  auto_renew_days_before: number
  cert_pem_enc?: string
  fullchain_pem_enc?: string
  privkey_pem_enc?: string
  issuer_url?: string
  chain_thumbprint?: string
  san_domains?: string[]
  
  last_renew_error?: string | null
  
  last_renew_attempt_ts?: number | null
  
  last_renew_ok_ts?: number | null
  
  renew_attempts?: number
  
  auto_paused?: boolean
}

export interface AcmeCertificatesResponse {
  records: Array<Omit<AcmeCertificateRecord, 'privkey_pem_enc'> & {
    status_derived: AcmeCertStatus
  }>
}

export interface AcmeCapabilities {
  available: boolean
  acme_extensions_ok: boolean
  docroot_known: boolean
  challenges_dir_writable: boolean
  reason_key?: string
}

export interface AcmeIssueCertPayload {
  domain: string
  email: string
  accept_tos: boolean
  ca?: 'letsencrypt' | 'letsencrypt-staging'
}
export type SeverityBadgeVariant = 'accent'|'success'|'warning'|'danger'|'muted';
export interface SecurityVulnItem { package:string; installed_version:string; fixed_version?:string; severity:'info'|'low'|'moderate'|'high'|'critical'; title:string; url?:string; severityBadgeVariant:SeverityBadgeVariant }
export interface SecurityScanFrontendResult { available:boolean; reason_key?:string; scanned_at?:number; vulns:SecurityVulnItem[] }
export interface SecurityScanBackendResult { available:boolean; reason_key?:string; scanned_at?:number; heuristicOnly:boolean; notice_key?:string; vulns:SecurityVulnItem[] }

export interface RemoteArchiveItem {
  key: string
  size: number
  modified: string
}

export interface BackupRemoteBrowseRequest {
  dest_id: string
  prefix?: string
}

export interface BackupRemoteBrowseResult {
  items: RemoteArchiveItem[]
  prefix: string
}

export interface BackupRemoteDownloadResult {
  filename: string
  size: number
  downloaded: boolean
}

export interface RecoveryCodeExport {
  filename: string;
  content: string;
  mime: string;
}

export interface TotpRecoveryViewResult {
  view_only?: boolean;
  needs_regenerate?: boolean;
  legacy?: boolean;
  download?: boolean;
  filename?: string;
  message_key?: string;
  regenerated?: boolean;
  recovery_codes?: string[];
  codes_format?: 'enc' | 'hash_legacy';
  recovery_codes_count?: number;
  used_count?: number;
}

export type ApiTokenScope = 'backup:run' | 'status:read' | 'files:read';

export interface ApiToken {
  id: string;
  name: string;
  scopes: ApiTokenScope[];
  created_at: number;
  expires_at?: number | null;
  last_used_at?: number | null;
}

export interface ApiTokenCreateInput {
  name: string;
  scopes: ApiTokenScope[];
  expires_at?: number | null;
}

export interface ApiTokenCreateResult {
  token: ApiToken;
  plain_token: string;
}

export interface TrashItem {
  id: string;
  orig_path: string;
  type: 'file' | 'dir';
  size: number;
  deleted_at: number;
}

export interface TrashListResult {
  items: TrashItem[];
  total_size: number;
  enabled: boolean;
}

export interface MonitorSample {
  ts: number;
  disk_used_pct: number;
  disk_used: number;
  disk_total: number;
  file_count: number;
  inode_cap: number;
  inode_used_pct: number;
  inode_truncated?: boolean;
  bandwidth_in_day: number;
  bandwidth_out_day: number;
  bandwidth_delta: number;
}

export interface MonitorBandwidthDay {
  day: string;
  bytes: number;
}

export interface MonitorReport {
  sample: MonitorSample | null;
  history: MonitorSample[];
  thresholds: { disk_threshold_pct: number; inode_threshold_pct: number };
  config: { sample_interval_min: number; inode_cap: number };
}

export interface WebcronHistoryStats {
  processed_schedules: number;
  processed_runs: number;
  drained_outbox: number;
  tick_at: number;
  acme_renewed?: number;
  acme_skipped?: number;
  acme_failed?: number;
  acme_paused?: number;
}

export interface WebcronHistoryEntry {
  id: string;
  tick_at: number;
  status: 'ok' | 'fail';
  stats: WebcronHistoryStats;
}

export interface WebcronHistoryResult {
  token_set: boolean;
  webcron_url: string;
  last_triggered_at: number | null;
  next_backup_run_at: number | null;
  cap: number;
  history: WebcronHistoryEntry[];
}

export interface UpgradeCheckResult {
  checked_at: number;
  current_version: string;
  latest_version?: string;
  update_available: boolean;
  release_name?: string;
  published_at?: string;
  asset_url?: string;
  asset_size?: number;
  error_key?: string;
  error_message?: string;
}

export interface UpgradeProgress {
  step: 'download' | 'backup' | 'extract' | 'migrate' | 'done' | 'error' | null;
  message_key: string;
  message?: string;
  percent?: number;
  error?: string;
  updated_at?: number;
}

export interface DeployAppInfo {
  id: string;
  name: string;
  name_key: string;
  description_key: string;
  version: string;
  download_url: string;
  db_required: boolean;
  sha256?: string;
  root_dir_in_zip?: string;
}

export interface DeployRunResult {
  ok: boolean;
  app_id: string;
  target_dir: string;
  files_extracted?: number;
  db_configured?: boolean;
  db_created?: boolean;
  wp_config_hint?: string;
  next_step_key: string;
  message_key?: string;
}
