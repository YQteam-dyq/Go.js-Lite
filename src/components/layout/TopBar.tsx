import { useState } from 'react'
import { Menu, Sun, Moon, Monitor, LogOut, User, ChevronsLeft, ChevronsRight } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { useTheme } from '@/hooks/useTheme'
import { useAuth } from '@/hooks/useAuth'
import { useIsMobile, useIsDesktop } from '@/hooks/useMediaQuery'
import { useUiStore } from '@/stores/uiStore'
import { useI18n } from '@/hooks/useI18n'

export function TopBar({ title }: { title?: string }) {
  const { theme, setTheme } = useTheme()
  const { logout, user } = useAuth()
  const isMobile = useIsMobile()
  const isDesktop = useIsDesktop()
  const sidebarOpen = useUiStore((s) => s.sidebarOpen)
  const toggleSidebar = useUiStore((s) => s.toggleSidebar)
  const { t } = useI18n()
  const [menuOpen, setMenuOpen] = useState(false)

  const cycleTheme = () => {
    if (theme === 'light') setTheme('dark')
    else if (theme === 'dark') setTheme('system')
    else setTheme('light')
  }

  const themeIcon =
    theme === 'light' ? <Sun size={18} /> : theme === 'dark' ? <Moon size={18} /> : <Monitor size={18} />

  return (
    <header className="h-14 shrink-0 flex items-center gap-2 px-3 md:px-5 border-b border-border bg-bg-elevated/80 backdrop-blur z-10 safe-top">
      {isMobile ? (
        <Button variant="ghost" size="icon-sm" onClick={toggleSidebar} aria-label={t('common.menu')}>
          <Menu size={20} />
        </Button>
      ) : isDesktop ? (
        <Button variant="ghost" size="icon-sm" onClick={toggleSidebar} aria-label={t('common.toggleSidebar')}>
          {sidebarOpen ? <ChevronsLeft size={18} /> : <ChevronsRight size={18} />}
        </Button>
      ) : null}

      {title && (
        <h1 className="text-base font-semibold text-fg truncate flex-1">{title}</h1>
      )}
      {!title && <div className="flex-1" />}

      <div className="flex items-center gap-1">
        <Button
          variant="ghost"
          size="icon-sm"
          onClick={cycleTheme}
          aria-label={t('common.toggleTheme')}
          title={theme === 'light' ? t('settings.light') : theme === 'dark' ? t('settings.dark') : t('settings.system')}
        >
          {themeIcon}
        </Button>

        {isMobile ? (
          <Button variant="ghost" size="icon-sm" onClick={() => setMenuOpen((v) => !v)} aria-label={t('common.user')}>
            <User size={18} />
          </Button>
        ) : (
          <div className="relative">
            <Button
              variant="ghost"
              size="sm"
              onClick={() => setMenuOpen((v) => !v)}
              className="gap-2"
            >
              <User size={16} />
              <span className="text-sm max-w-[100px] truncate">
                {user?.username || t('common.admin')}
              </span>
            </Button>
            {menuOpen && (
              <>
                <div className="fixed inset-0 z-20" onClick={() => setMenuOpen(false)} />
                <div className="absolute right-0 top-full mt-1 w-40 card shadow-lg z-30 animate-scale-in py-1">
                  <div className="px-3 py-2 text-xs text-fg-muted border-b border-border">
                    {user?.username || t('common.admin')}
                  </div>
                  <button
                    onClick={() => {
                      setMenuOpen(false)
                      logout()
                    }}
                    className="w-full px-3 py-2 text-left text-sm hover:bg-fg/5 text-danger flex items-center gap-2"
                  >
                    <LogOut size={16} />
                    {t('common.logout')}
                  </button>
                </div>
              </>
            )}
          </div>
        )}
      </div>

      {isMobile && menuOpen && (
        <div className="absolute top-full left-0 right-0 bg-bg-elevated border-b border-border shadow-lg animate-slide-right z-20">
          <div className="p-4 space-y-3">
            <div className="text-sm font-medium text-fg">{user?.username || t('common.admin')}</div>
            <button
              onClick={() => {
                setMenuOpen(false)
                logout()
              }}
              className="w-full h-11 flex items-center gap-3 px-3 rounded-lg text-danger hover:bg-danger/10 transition-colors min-h-[44px]"
            >
              <LogOut size={18} />
              <span>{t('common.logout')}</span>
            </button>
          </div>
        </div>
      )}
    </header>
  )
}
