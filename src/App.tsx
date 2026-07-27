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
