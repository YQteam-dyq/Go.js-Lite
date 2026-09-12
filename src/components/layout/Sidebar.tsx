import { NavLink, useLocation } from 'react-router-dom'
import {
  LayoutDashboard,
  FolderOpen,
  Database,
  Code2,
  Settings,
  Cpu,
  Bug,
  FileText,
  HardDrive,
  HardDriveDownload,
  ClipboardCheck,
  History,
  ChevronLeft,
  ChevronRight,
  CalendarClock,
  Shield,
  Users as UsersIcon,
  UserCog,
  User as UserIcon,
  Activity,
  Bell,
  ShieldAlert,
  PackageCheck,
  KeyRound,
  Rocket,
  FolderTree,
  MailPlus,
  Laptop,
  BellRing,
  ShieldCheck,
  Gauge,
  Puzzle,
  Server,
  Timer,
  FileCog,
  ArrowUpCircle,
} from 'lucide-react'
import { Logo } from '@/components/branding/Logo'
import { useCapabilities } from '@/hooks/useCapabilities'
import { useIsMobile } from '@/hooks/useMediaQuery'
import { useUiStore } from '@/stores/uiStore'
import { useAuthStore } from '@/stores/authStore'
import { useEffect } from 'react'
import { useI18n } from '@/hooks/useI18n'

interface NavItem {
  to: string
  label: string
  icon: React.ReactNode
  show: boolean
  match?: (path: string) => boolean
}

export function Sidebar() {
  const caps = useCapabilities()
  const isMobile = useIsMobile()
  const sidebarOpen = useUiStore((s) => s.sidebarOpen)
  const setSidebar = useUiStore((s) => s.setSidebar)
  const collapsed = useUiStore((s) => s.sidebarCollapsed)
  const toggleCollapsed = useUiStore((s) => s.toggleSidebarCollapsed)
  const location = useLocation()
  const { t } = useI18n()
  const userRole = useAuthStore((s) => s.user?.role)
  const isAdmin = userRole === 'admin'

  useEffect(() => {
    if (isMobile) setSidebar(false)
  }, [isMobile, setSidebar])

  const items: NavItem[] = [
    { to: '/dashboard', label: t('nav.dashboard'), icon: <LayoutDashboard size={18} />, show: true },
    { to: '/files', label: t('nav.files'), icon: <FolderOpen size={18} />, show: true },
    {
      to: '/db',
      label: t('nav.database'),
      icon: <Database size={18} />,
      show: caps.mysql,
      match: (p) => p.startsWith('/db'),
    },
    { to: '/phpinfo', label: t('nav.phpInfo'), icon: <Code2 size={18} />, show: true },
    {
      to: '/system',
      label: t('nav.system'),
      icon: <Cpu size={18} />,
      show: caps.disk || caps.processes || caps.cron,
    },
    {
      to: '/disk-analysis',
      label: t('nav.diskAnalysis'),
      icon: <HardDrive size={18} />,
      show: caps.disk,
    },
    { to: '/ftp', label: t('nav.ftp'), icon: <UsersIcon />, show: caps.ftp ?? true },
    { to: '/error-log', label: t('nav.errorLog'), icon: <Bug size={18} />, show: true },
    { to: '/operation-log', label: t('nav.operationLog'), icon: <History size={18} />, show: true },
    { to: '/notifications', label: t('nav.notifications'), icon: <Bell size={18} />, show: true },
    { to: '/env-check', label: t('nav.envCheck'), icon: <ClipboardCheck size={18} />, show: true },
    { to: '/security-scan', label: t('nav.securityScan'), icon: <ShieldAlert size={18} />, show: true },
    { to: '/ssl', label: t('nav.ssl'), icon: <Shield size={18} />, show: true },
    { to: '/cron', label: t('nav.cron'), icon: <CalendarClock size={18} />, show: true },
    { to: '/backup', label: t('nav.backup'), icon: <HardDriveDownload size={18} />, show: true },
    { to: '/htaccess', label: t('nav.htaccess'), icon: <FileText size={18} />, show: true },
    { to: '/upgrade', label: t('nav.upgrade'), icon: <PackageCheck size={18} />, show: true },
    { to: '/api-tokens', label: t('nav.apiTokens'), icon: <KeyRound size={18} />, show: true },
    { to: '/deploy', label: t('nav.deploy'), icon: <Rocket size={18} />, show: true },
    { to: '/users', label: t('nav.users'), icon: <UserCog size={18} />, show: isAdmin },
    { to: '/sessions', label: t('nav.sessions'), icon: <Shield size={18} />, show: isAdmin },
    { to: '/user-activity', label: t('nav.userActivity'), icon: <Activity size={18} />, show: isAdmin },
    { to: '/profile', label: t('nav.profile'), icon: <UserIcon size={18} />, show: true },
    { to: '/groups', label: t('nav.groups'), icon: <FolderTree size={18} />, show: isAdmin },
    { to: '/tokens', label: t('nav.tokens'), icon: <KeyRound size={18} />, show: isAdmin || userRole === 'operator' },
    { to: '/invitations', label: t('nav.invitations'), icon: <MailPlus size={18} />, show: isAdmin },
    { to: '/devices', label: t('nav.devices'), icon: <Laptop size={18} />, show: true },
    { to: '/notification-preferences', label: t('nav.notificationPrefs'), icon: <BellRing size={18} />, show: true },
    { to: '/approvals', label: t('nav.approvals'), icon: <ShieldCheck size={18} />, show: isAdmin },
    { to: '/composer', label: t('nav.composer'), icon: <PackageCheck size={18} />, show: isAdmin },
    { to: '/php-opcache', label: t('nav.phpOpcache'), icon: <Gauge size={18} />, show: isAdmin },
    { to: '/php-extensions', label: t('nav.phpExtensions'), icon: <Puzzle size={18} />, show: isAdmin },
    { to: '/php-errors', label: t('nav.phpErrors'), icon: <Bug size={18} />, show: isAdmin },
    { to: '/php-fpm', label: t('nav.phpFpm'), icon: <Server size={18} />, show: isAdmin },
    { to: '/php-bench', label: t('nav.phpBench'), icon: <Timer size={18} />, show: isAdmin },
    { to: '/php-ini', label: t('nav.phpIni'), icon: <FileCog size={18} />, show: isAdmin },
    { to: '/php-processes', label: t('nav.phpProcesses'), icon: <Cpu size={18} />, show: isAdmin },
    { to: '/php-upgrade', label: t('nav.phpUpgrade'), icon: <ArrowUpCircle size={18} />, show: isAdmin },
    { to: '/settings', label: t('nav.settings'), icon: <Settings size={18} />, show: true },
  ]

  if (isMobile && !sidebarOpen) return null

  const isCollapsed = !isMobile && collapsed

  return (
    <>
      {isMobile && sidebarOpen && (
        <div
          className="fixed inset-0 bg-black/40 backdrop-blur-sm z-30 animate-fade-in"
          onClick={() => setSidebar(false)}
        />
      )}
      <aside
        className={`
          fixed md:relative z-40 h-full
          ${isCollapsed ? 'w-16' : 'w-60'}
          shrink-0
          bg-bg-elevated border-r border-border
          flex flex-col
          transition-all duration-300 ease-out
          ${isMobile ? (sidebarOpen ? 'translate-x-0' : '-translate-x-full') : ''}
        `}
      >
        <div className={`h-14 flex items-center border-b border-border shrink-0 ${isCollapsed ? 'justify-center px-2' : 'px-4'}`}>
          <Logo size="sm" showText={!isCollapsed} />
        </div>

        <nav className="flex-1 py-3 px-2 overflow-y-auto overflow-x-hidden">
          <ul className="space-y-0.5">
            {items
              .filter((item) => item.show)
              .map((item) => {
                const isActive = item.match
                  ? item.match(location.pathname)
                  : location.pathname === item.to || location.pathname.startsWith(item.to + '/')
                return (
                  <li key={item.to}>
                    <NavLink
                      to={item.to}
                      onClick={() => isMobile && setSidebar(false)}
                      title={isCollapsed ? item.label : undefined}
                      className={`
                        flex items-center gap-3 h-11 rounded-lg text-sm
                        transition-colors duration-150
                        min-h-[44px]
                        ${isCollapsed ? 'justify-center px-0' : 'px-3'}
                        ${
                          isActive
                            ? 'bg-accent/10 text-accent font-medium'
                            : 'text-fg-muted hover:text-fg hover:bg-fg/5'
                        }
                      `}
                    >
                      <span className={isActive ? 'text-accent' : ''}>{item.icon}</span>
                      {!isCollapsed && <span>{item.label}</span>}
                    </NavLink>
                  </li>
                )
              })}
          </ul>
        </nav>

        <div className="p-3 border-t border-border shrink-0">
          {isCollapsed ? (
            <div className="flex justify-center">
              <button
                type="button"
                onClick={toggleCollapsed}
                title={t('nav.expand')}
                aria-label={t('nav.expand')}
                className="p-2 rounded-lg text-fg-muted hover:text-fg hover:bg-fg/5 transition-colors"
              >
                <ChevronRight size={18} />
              </button>
            </div>
          ) : (
            <div className="flex items-center justify-between gap-2">
              <div className="px-3 py-2 rounded-lg bg-bg-sunken min-w-0 flex-1">
                <div className="text-xs text-fg-muted truncate">PHP {caps.phpVersion || '—'}</div>
                <div className="text-[10px] text-fg-subtle mt-0.5 truncate">{caps.sapi}</div>
              </div>
              <button
                type="button"
                onClick={toggleCollapsed}
                title={t('nav.collapse')}
                aria-label={t('nav.collapse')}
                className="shrink-0 p-2 rounded-lg text-fg-muted hover:text-fg hover:bg-fg/5 transition-colors"
              >
                <ChevronLeft size={18} />
              </button>
            </div>
          )}
        </div>
      </aside>
    </>
  )
}
