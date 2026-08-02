import { Routes, Route, Navigate, useLocation } from 'react-router-dom'
import { useEffect } from 'react'
import { useAuthBootstrap } from '@/hooks/useAuth'
import { useI18n } from '@/hooks/useI18n'
import { Spinner } from '@/components/ui/Spinner'
import Login from '@/routes/Login'
import Install from '@/routes/Install'
import AppLayout from '@/components/layout/AppLayout'
import Dashboard from '@/routes/Dashboard'
import FileList from '@/routes/files/FileList'
import FileEditor from '@/routes/files/FileEditor'
import DbConnections from '@/routes/db/DbConnections'
import DbBrowser from '@/routes/db/DbBrowser'
import SqlConsole from '@/routes/db/SqlConsole'
import PhpInfo from '@/routes/PhpInfo'
import System from '@/routes/System'
import Settings from '@/routes/Settings'
import NotFound from '@/routes/NotFound'
import { useCapabilities } from '@/hooks/useCapabilities'
import type { TranslationKey } from '@/hooks/useI18n'
import DiskAnalysis from '@/routes/DiskAnalysis'
import ErrorLog from '@/routes/ErrorLog'
import Htaccess from '@/routes/Htaccess'
import HealthCheck from '@/routes/HealthCheck'
import EnvCheck from '@/routes/EnvCheck'
import OperationLog from '@/routes/OperationLog'
import Cron from '@/routes/Cron'
import Backup from '@/routes/Backup'
import SSL from '@/routes/SSL'
import Ftp from '@/routes/Ftp'
import Notifications from '@/routes/Notifications'
import SecurityScan from '@/routes/SecurityScan'
import Upgrade from '@/routes/Upgrade'
import ApiTokens from '@/routes/ApiTokens'
import Deploy from '@/routes/Deploy'

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
  const { loading, bootstrapFailed } = useAuthBootstrap()
  const location = useLocation()
  const { t } = useI18n()

  useEffect(() => {
    const titleKey = getTitleKey(location.pathname)
    document.title = t(titleKey)
  }, [t, location.pathname])

  if (loading) {
    return (
      <div className="min-h-screen flex items-center justify-center">
        <Spinner size="lg" />
      </div>
    )
  }

  if (bootstrapFailed) {
    return <NotFound />
  }

  return (
    <Routes>
      <Route path="/login" element={<Login />} />
      <Route path="/install" element={<Install />} />
      <Route
        path="/*"
        element={
          <RequireAuth>
            <AppLayout>
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
                <Route path="settings" element={<Settings />} />
                <Route path="*" element={<NotFound />} />
              </Routes>
            </AppLayout>
          </RequireAuth>
        }
      />
    </Routes>
  )
}
