import { NavLink, useLocation } from 'react-router-dom'
import {
  LayoutDashboard,
  FolderOpen,
  Database,
  Code2,
  Settings,
  Cpu,
} from 'lucide-react'
import { Logo } from '@/components/branding/Logo'
import { useCapabilities } from '@/hooks/useCapabilities'
import { useIsMobile } from '@/hooks/useMediaQuery'
import { useUiStore } from '@/stores/uiStore'
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
  const location = useLocation()
  const { t } = useI18n()

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
    { to: '/settings', label: t('nav.settings'), icon: <Settings size={18} />, show: true },
  ]

  if (isMobile && !sidebarOpen) return null

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
          w-60 shrink-0
          bg-bg-elevated border-r border-border
          flex flex-col
          transition-transform duration-300 ease-out
          ${isMobile ? (sidebarOpen ? 'translate-x-0' : '-translate-x-full') : ''}
        `}
      >
        <div className="h-14 flex items-center px-4 border-b border-border shrink-0">
          <Logo size="sm" />
        </div>

        <nav className="flex-1 py-3 px-2 overflow-y-auto">
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
                      className={`
                        flex items-center gap-3 px-3 h-11 rounded-lg text-sm
                        transition-colors duration-150
                        min-h-[44px]
                        ${
                          isActive
                            ? 'bg-accent/10 text-accent font-medium'
                            : 'text-fg-muted hover:text-fg hover:bg-fg/5'
                        }
                      `}
                    >
                      <span className={isActive ? 'text-accent' : ''}>{item.icon}</span>
                      <span>{item.label}</span>
                    </NavLink>
                  </li>
                )
              })}
          </ul>
        </nav>

        <div className="p-3 border-t border-border shrink-0">
          <div className="px-3 py-2 rounded-lg bg-bg-sunken">
            <div className="text-xs text-fg-muted">PHP {caps.phpVersion || '—'}</div>
            <div className="text-[10px] text-fg-subtle mt-0.5">{caps.sapi}</div>
          </div>
        </div>
      </aside>
    </>
  )
}
