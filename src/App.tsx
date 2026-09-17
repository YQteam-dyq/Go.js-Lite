import { Routes, Route, Navigate, useLocation } from 'react-router-dom'
import { Suspense, lazy, useEffect } from 'react'
import { useAuthBootstrap } from '@/hooks/useAuth'
import { useI18n } from '@/hooks/useI18n'
import { Spinner } from '@/components/ui/Spinner'
import AppLayout from '@/components/layout/AppLayout'
import ErrorBoundary from '@/components/ErrorBoundary'
import NotFound from '@/routes/NotFound'
import { useCapabilities } from '@/hooks/useCapabilities'
import type { TranslationKey } from '@/hooks/useI18n'

const Login = lazy(() => import('@/routes/Login'))
const Install = lazy(() => import('@/routes/Install'))
const InviteAccept = lazy(() => import('@/routes/InviteAccept'))
const Dashboard = lazy(() => import('@/routes/Dashboard'))
const FileList = lazy(() => import('@/routes/files/FileList'))
const FileEditor = lazy(() => import('@/routes/files/FileEditor'))
const DbConnections = lazy(() => import('@/routes/db/DbConnections'))
const DbBrowser = lazy(() => import('@/routes/db/DbBrowser'))
const SqlConsole = lazy(() => import('@/routes/db/SqlConsole'))
const TableDataEditor = lazy(() => import('@/routes/db/TableDataEditor'))
const TableStructureManager = lazy(() => import('@/routes/db/TableStructureManager'))
const QueryBuilder = lazy(() => import('@/routes/db/QueryBuilder'))
const ExportEnhanced = lazy(() => import('@/routes/db/ExportEnhanced'))
const PhpInfo = lazy(() => import('@/routes/PhpInfo'))
const System = lazy(() => import('@/routes/System'))
const Settings = lazy(() => import('@/routes/Settings'))
const DiskAnalysis = lazy(() => import('@/routes/DiskAnalysis'))
const ErrorLog = lazy(() => import('@/routes/ErrorLog'))
const Htaccess = lazy(() => import('@/routes/Htaccess'))
const HealthCheck = lazy(() => import('@/routes/HealthCheck'))
const EnvCheck = lazy(() => import('@/routes/EnvCheck'))
const OperationLog = lazy(() => import('@/routes/OperationLog'))
const Cron = lazy(() => import('@/routes/Cron'))
const Backup = lazy(() => import('@/routes/Backup'))
const SSL = lazy(() => import('@/routes/SSL'))
const Ftp = lazy(() => import('@/routes/Ftp'))
const Notifications = lazy(() => import('@/routes/Notifications'))
const SecurityScan = lazy(() => import('@/routes/SecurityScan'))
const Upgrade = lazy(() => import('@/routes/Upgrade'))
const ApiTokens = lazy(() => import('@/routes/ApiTokens'))
const Deploy = lazy(() => import('@/routes/Deploy'))
const Users = lazy(() => import('@/routes/Users'))
const Sessions = lazy(() => import('@/routes/Sessions'))
const UserActivity = lazy(() => import('@/routes/UserActivity'))
const Profile = lazy(() => import('@/routes/Profile'))
const Groups = lazy(() => import('@/routes/Groups'))
const Tokens = lazy(() => import('@/routes/Tokens'))
const Invitations = lazy(() => import('@/routes/Invitations'))
const Devices = lazy(() => import('@/routes/Devices'))
const NotificationPreferences = lazy(() => import('@/routes/NotificationPreferences'))
const Approvals = lazy(() => import('@/routes/Approvals'))
const Composer = lazy(() => import('@/routes/Composer'))
const PhpOpcache = lazy(() => import('@/routes/PhpOpcache'))
const PhpExtensions = lazy(() => import('@/routes/PhpExtensions'))
const PhpErrors = lazy(() => import('@/routes/PhpErrors'))
const PhpFpm = lazy(() => import('@/routes/PhpFpm'))
const PhpBench = lazy(() => import('@/routes/PhpBench'))
const PhpIni = lazy(() => import('@/routes/PhpIni'))
const PhpProcesses = lazy(() => import('@/routes/PhpProcesses'))
const PhpUpgrade = lazy(() => import('@/routes/PhpUpgrade'))
const WebShell = lazy(() => import('@/routes/WebShell'))
const WebsiteMonitor = lazy(() => import('@/routes/WebsiteMonitor'))
const CustomErrorPages = lazy(() => import('@/routes/CustomErrorPages'))

function RouteFallback() {
  return (
    <div className="flex items-center justify-center py-24">
      <Spinner size="lg" />
    </div>
  )
}

function RequireAuth({ children }: { children: React.ReactNode }) {
  const { authenticated, loading } = useAuthBootstrap()
  if (loading) {
    return (
      <div className="min-h-screen flex items-center justify-center">
        <Spinner size="lg" />
      </div>
    )
  }
  if (!authenticated) {
    return <Navigate to="/login" replace />
  }
  return <>{children}</>
}

function DbRoutes() {
  const caps = useCapabilities()
  const { t } = useI18n()
  if (!caps.mysql) {
    return (
      <div className="p-8 text-center text-fg-muted">
        {t('db.mysqlNotSupported')}
      </div>
    )
  }
  return (
    <Routes>
      <Route index element={<DbConnections />} />
      <Route path=":connId/browse" element={<DbBrowser />} />
      <Route path=":connId/sql" element={<SqlConsole />} />
      <Route path=":connId/table/data" element={<TableDataEditor />} />
      <Route path=":connId/table/structure" element={<TableStructureManager />} />
      <Route path=":connId/query/builder" element={<QueryBuilder />} />
      <Route path=":connId/export/enhanced" element={<ExportEnhanced />} />
      <Route path="*" element={<DbConnections />} />
    </Routes>
  )
}

const routeTitleMap: Record<string, TranslationKey> = {
  '/login': 'login.documentTitle',
  '/install': 'install.documentTitle',
  '/dashboard': 'dashboard.documentTitle',
  '/files': 'files.documentTitle',
  '/edit': 'files.documentTitle',
  '/db': 'db.documentTitle',
  '/phpinfo': 'phpinfo.documentTitle',
  '/system': 'system.documentTitle',
  '/settings': 'settings.documentTitle',
  '/disk-analysis': 'diskAnalysis.documentTitle',
  '/error-log': 'errorLog.documentTitle',
  '/operation-log': 'operationLog.documentTitle',
  '/htaccess': 'htaccess.documentTitle',
  '/health-check': 'healthCheck.documentTitle',
  '/env-check': 'envCheck.documentTitle',
  '/cron': 'cron.documentTitle',
  '/backup': 'backup.documentTitle',
  '/ssl': 'ssl.documentTitle',
  '/ftp': 'ftp.documentTitle',
  '/notifications': 'notifications.documentTitle',
  '/security-scan': 'securityScan.documentTitle',
  '/upgrade': 'upgrade.documentTitle',
  '/api-tokens': 'apiTokens.documentTitle',
  '/deploy': 'deploy.documentTitle',
  '/users': 'users.documentTitle',
  '/sessions': 'sessions.documentTitle',
  '/user-activity': 'userActivity.documentTitle',
  '/profile': 'profile.documentTitle',
  '/groups': 'groups.documentTitle',
  '/tokens': 'tokens.documentTitle',
  '/invitations': 'invitations.documentTitle',
  '/devices': 'devices.documentTitle',
  '/notification-preferences': 'notificationPrefs.documentTitle',
  '/approvals': 'approvals.documentTitle',
  '/composer': 'composer.documentTitle',
  '/php-opcache': 'phpOpcache.documentTitle',
  '/php-extensions': 'phpExtensions.documentTitle',
  '/php-errors': 'phpErrors.documentTitle',
  '/php-fpm': 'phpFpm.documentTitle',
  '/php-bench': 'phpBench.documentTitle',
  '/php-ini': 'phpIni.documentTitle',
  '/php-processes': 'phpProcesses.documentTitle',
  '/php-upgrade': 'phpUpgrade.documentTitle',
  '/webshell': 'webshell.documentTitle',
  '/website-monitor': 'websiteMonitor.documentTitle',
  '/custom-error-pages': 'customErrorPages.documentTitle',
  '/invite': 'inviteAccept.documentTitle',
  '/404': 'notFound.documentTitle',
}

function getTitleKey(pathname: string): TranslationKey {
  if (pathname === '/login') return 'login.documentTitle'
  if (pathname === '/install') return 'install.documentTitle'
  for (const prefix of Object.keys(routeTitleMap)) {
    if (pathname.startsWith(prefix)) {
      return routeTitleMap[prefix]
    }
  }
  return 'dashboard.documentTitle'
}

export default function App() {
  const { loading, bootstrapFailed, authenticated } = useAuthBootstrap()
  const location = useLocation()
  const { t } = useI18n()

  useEffect(() => {
    const titleKey = getTitleKey(location.pathname)
    document.title = t(titleKey)
  }, [t, location.pathname])

  useEffect(() => {
    if (!authenticated) return
    const timer = window.setTimeout(() => {
      void import('@/routes/Dashboard').catch(() => {})
    }, 1000)
    return () => window.clearTimeout(timer)
  }, [authenticated])

  if (loading) {
    return (
      <div className="min-h-screen flex items-center justify-center">
        <div className="text-center">
          <Spinner size="lg" />
          <p className="mt-4 text-muted">Loading...</p>
        </div>
      </div>
    )
  }

  if (bootstrapFailed) {
    return <NotFound />
  }

  return (
    <ErrorBoundary fallback={<RouteFallback />}>
      <Suspense fallback={<RouteFallback />}>
        <Routes>
          <Route path="/login" element={<Login />} />
        <Route path="/install" element={<Install />} />
        <Route path="/invite/:token" element={<InviteAccept />} />
        <Route
          path="/*"
          element={
            <RequireAuth>
              <AppLayout>
                <Suspense fallback={<RouteFallback />}>
                  <Routes>
                    <Route index element={<Navigate to="/dashboard" replace />} />
                    <Route path="dashboard" element={<Dashboard />} />
                    <Route path="files/*" element={<FileList />} />
                    <Route path="edit/*" element={<FileEditor />} />
                    <Route path="db/*" element={<DbRoutes />} />
                    <Route path="phpinfo" element={<PhpInfo />} />
                    <Route path="system" element={<System />} />
                    <Route path="disk-analysis" element={<DiskAnalysis />} />
                    <Route path="error-log" element={<ErrorLog />} />
                    <Route path="operation-log" element={<OperationLog />} />
                    <Route path="htaccess" element={<Htaccess />} />
                    <Route path="health-check" element={<HealthCheck />} />
                    <Route path="env-check" element={<EnvCheck />} />
                    <Route path="cron" element={<Cron />} />
                    <Route path="backup" element={<Backup />} />
                    <Route path="ssl" element={<SSL />} />
                    <Route path="ftp" element={<Ftp />} />
                    <Route path="notifications" element={<Notifications />} />
                    <Route path="security-scan" element={<SecurityScan />} />
                    <Route path="upgrade" element={<Upgrade />} />
                    <Route path="api-tokens" element={<ApiTokens />} />
                    <Route path="deploy" element={<Deploy />} />
                    <Route path="users" element={<Users />} />
                    <Route path="sessions" element={<Sessions />} />
                    <Route path="user-activity" element={<UserActivity />} />
                    <Route path="profile" element={<Profile />} />
                    <Route path="groups" element={<Groups />} />
                    <Route path="tokens" element={<Tokens />} />
                    <Route path="invitations" element={<Invitations />} />
                    <Route path="devices" element={<Devices />} />
                    <Route path="notification-preferences" element={<NotificationPreferences />} />
                    <Route path="approvals" element={<Approvals />} />
                    <Route path="composer" element={<Composer />} />
                    <Route path="php-opcache" element={<PhpOpcache />} />
                    <Route path="php-extensions" element={<PhpExtensions />} />
                    <Route path="php-errors" element={<PhpErrors />} />
                    <Route path="php-fpm" element={<PhpFpm />} />
                    <Route path="php-bench" element={<PhpBench />} />
                    <Route path="php-ini" element={<PhpIni />} />
                    <Route path="php-processes" element={<PhpProcesses />} />
                    <Route path="php-upgrade" element={<PhpUpgrade />} />
                    <Route path="webshell" element={<WebShell />} />
                    <Route path="website-monitor" element={<WebsiteMonitor />} />
                    <Route path="custom-error-pages" element={<CustomErrorPages />} />
                    <Route path="settings" element={<Settings />} />
                    <Route path="*" element={<NotFound />} />
                  </Routes>
                </Suspense>
              </AppLayout>
            </RequireAuth>
          }
        />
      </Routes>
      </Suspense>
    </ErrorBoundary>
  )
}
