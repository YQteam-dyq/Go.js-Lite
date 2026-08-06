import { NavLink, useLocation } from 'react-router-dom'
import {
  LayoutDashboard,
  FolderOpen,
  Database,
  Code2,
  Settings,
} from 'lucide-react'
import { useCapabilities } from '@/hooks/useCapabilities'
import { useI18n } from '@/hooks/useI18n'

export function BottomTab() {
  const caps = useCapabilities()
  const location = useLocation()
  const { t } = useI18n()

  const items = [
    { to: '/dashboard', label: t('nav.dashboard'), icon: LayoutDashboard, show: true },
    { to: '/files', label: t('nav.files'), icon: FolderOpen, show: true },
    { to: '/db', label: t('nav.database'), icon: Database, show: caps.mysql },
    { to: '/phpinfo', label: t('nav.phpInfo'), icon: Code2, show: true },
    { to: '/settings', label: t('nav.settings'), icon: Settings, show: true },
  ].filter((item) => item.show)

  const isActive = (to: string) =>
    location.pathname === to || location.pathname.startsWith(to + '/')

  return (
    <nav className="md:hidden fixed bottom-0 left-0 right-0 z-20 safe-bottom safe-x bg-bg-elevated/90 backdrop-blur-medium border-t border-border/60">
      <ul className="flex items-stretch justify-around">
        {items.map((item) => {
          const Icon = item.icon
          const active = isActive(item.to)
          return (
            <li key={item.to} className="flex-1">
              <NavLink
                to={item.to}
                className={`
                  flex flex-col items-center justify-center gap-1
                  h-14 min-h-[44px]
                  text-xs font-medium
                  transition-all duration-200 ease-out
                  ${active ? 'text-accent' : 'text-fg-muted'}
                  active:scale-95
                `}
              >
                <span className={`transition-transform duration-200 ease-out ${active ? 'scale-110' : ''}`}>
                  <Icon size={20} strokeWidth={active ? 2.2 : 1.8} />
                </span>
                <span className={`transition-all duration-200 ${active ? 'font-semibold' : ''}`}>
                  {item.label}
                </span>
              </NavLink>
            </li>
          )
        })}
      </ul>
    </nav>
  )
}
