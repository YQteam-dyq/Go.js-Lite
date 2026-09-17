import { NavLink, useLocation } from 'react-router-dom'
import { ChevronLeft, ChevronRight } from 'lucide-react'
import { Logo } from '@/components/branding/Logo'
import { useCapabilities } from '@/hooks/useCapabilities'
import { useIsMobile } from '@/hooks/useMediaQuery'
import { useUiStore } from '@/stores/uiStore'
import { useAuthStore } from '@/stores/authStore'
import { buildNavItems, isNavItemActive } from '@/lib/navigation'
import { useEffect, useMemo } from 'react'
import { useI18n } from '@/hooks/useI18n'

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

  useEffect(() => {
    if (isMobile) setSidebar(false)
  }, [isMobile, setSidebar])

  const items = useMemo(() => buildNavItems({ caps, role: userRole }), [caps, userRole])

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
                const Icon = item.icon
                const isActive = isNavItemActive(item, location.pathname)
                const label = t(item.labelKey)
                return (
                  <li key={item.to}>
                    <NavLink
                      to={item.to}
                      onClick={() => isMobile && setSidebar(false)}
                      title={isCollapsed ? label : undefined}
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
                      <span className={isActive ? 'text-accent' : ''}>
                        <Icon size={18} />
                      </span>
                      {!isCollapsed && <span>{label}</span>}
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
