import { useState } from 'react'
import {
  Menu,
  Sun,
  Moon,
  Monitor,
  LogOut,
  User,
  ChevronsLeft,
  ChevronsRight,
  Bell,
  Inbox,
  CheckCircle2,
} from 'lucide-react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { Button } from '@/components/ui/Button'
import { BottomSheet } from '@/components/ui/BottomSheet'
import { useTheme } from '@/hooks/useTheme'
import { useAuth } from '@/hooks/useAuth'
import { useIsMobile, useIsDesktop } from '@/hooks/useMediaQuery'
import { useUiStore } from '@/stores/uiStore'
import { useI18n } from '@/hooks/useI18n'
import { notificationsApi } from '@/api/notifications'
import { useRelativeTime, renderI18nText } from '@/components/notifications/helpers'

function NotificationPopoverContent({ onClose }: { onClose: () => void }) {
  const { t } = useI18n()
  const nav = useNavigate()
  const qc = useQueryClient()
  const relTime = useRelativeTime()
  const { data: summary } = useQuery({
    queryKey: ['notificationsSummary'],
    queryFn: () => notificationsApi.summary(),
    refetchInterval: 30000,
    staleTime: 5000,
  })

  const markReadMut = useMutation({
    mutationFn: (id: string) => notificationsApi.markRead(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['notificationsSummary'] })
      qc.invalidateQueries({ queryKey: ['notificationsList'] })
    },
  })

  const items = summary?.latest_5 ?? []
  const goInbox = () => {
    onClose()
    nav('/notifications')
  }

  return (
    <div className="flex flex-col">
      <div className="flex items-center justify-between px-3 py-2.5 border-b border-border">
        <div className="text-sm font-semibold text-fg">{t('notify.recent', { defaultValue: '最近通知' })}</div>
        <button
          onClick={goInbox}
          className="text-xs text-accent hover:underline font-medium flex items-center gap-1"
        >
          <Inbox size={12} />
          {t('notify.goInbox', { defaultValue: '前往收件箱' })}
        </button>
      </div>
      <div className="max-h-[420px] overflow-auto min-w-[320px]">
        {items.length === 0 ? (
          <div className="px-4 py-8 text-center">
            <div className="mx-auto w-12 h-12 rounded-full bg-bg-sunken text-fg-subtle flex items-center justify-center mb-3">
              <Bell size={20} />
            </div>
            <div className="text-sm text-fg font-medium">{t('notify.noRecent', { defaultValue: '暂无未读通知' })}</div>
            <div className="text-xs text-fg-subtle mt-1">{t('notify.noRecentHint', { defaultValue: '系统事件与告警会在这里展示。' })}</div>
          </div>
        ) : (
          <div className="divide-y divide-border/60">
            {items.map((it) => (
              <div key={it.id} className="px-3 py-2.5 hover:bg-bg-sunken/50 transition-colors">
                <div className="flex items-start gap-2.5">
                  <div className="w-2 h-2 rounded-full bg-accent mt-2 shrink-0" />
                  <button
                    onClick={goInbox}
                    className="flex-1 min-w-0 text-left"
                  >
                    <div className="text-sm font-medium text-fg line-clamp-2 leading-snug">
                      {renderI18nText(t, it.title_key, it.body_params as never) ?? it.title_key}
                    </div>
                    <div className="text-xs text-fg-subtle mt-0.5">{relTime(it.created_at)}</div>
                  </button>
                  <Button
                    variant="ghost"
                    size="icon-sm"
                    className="shrink-0 text-fg-muted hover:text-accent"
                    onClick={() => markReadMut.mutate(it.id)}
                    title={t('notify.markRead')}
                  >
                    <CheckCircle2 size={16} />
                  </Button>
                </div>
              </div>
            ))}
          </div>
        )}
      </div>
      <button
        onClick={goInbox}
        className="px-3 py-2.5 border-t border-border text-xs text-accent hover:bg-bg-sunken/50 transition-colors font-medium flex items-center justify-center gap-1"
      >
        {t('notify.viewAll', { defaultValue: '查看全部' })}
      </button>
    </div>
  )
}

function UnreadBadge({ count }: { count: number }) {
  if (count <= 0) return null
  const label = count >= 100 ? '99+' : String(count)
  return (
    <span className="absolute -top-0.5 -right-0.5 min-w-[16px] h-[16px] px-1 rounded-full bg-danger text-danger-fg text-[10px] font-bold flex items-center justify-center shadow-sm shadow-black/20 pointer-events-none leading-none">
      {label}
    </span>
  )
}

export function TopBar({ title }: { title?: string }) {
  const { theme, setTheme } = useTheme()
  const { logout, user } = useAuth()
  const isMobile = useIsMobile()
  const isDesktop = useIsDesktop()
  const sidebarOpen = useUiStore((s) => s.sidebarOpen)
  const toggleSidebar = useUiStore((s) => s.toggleSidebar)
  const { t } = useI18n()
  const nav = useNavigate()

  const [menuOpen, setMenuOpen] = useState(false)
  const [bellOpen, setBellOpen] = useState(false)

  const { data: summary } = useQuery({
    queryKey: ['notificationsSummary'],
    queryFn: () => notificationsApi.summary(),
    refetchInterval: 30000,
    staleTime: 5000,
  })

  const cycleTheme = () => {
    if (theme === 'light') setTheme('dark')
    else if (theme === 'dark') setTheme('system')
    else setTheme('light')
  }

  const themeIcon =
    theme === 'light' ? <Sun size={18} /> : theme === 'dark' ? <Moon size={18} /> : <Monitor size={18} />

  const unread = summary?.unread ?? 0

  const bellButton = (
    <div className="relative">
      <Button
        variant="ghost"
        size="icon-sm"
        onClick={() => {
          setBellOpen((v) => !v)
          setMenuOpen(false)
        }}
        aria-label={t('notify.inbox')}
        title={t('notify.totalUnread', { count: String(unread) })}
      >
        <Bell size={18} />
        <UnreadBadge count={unread} />
      </Button>

      {bellOpen && !isMobile && (
        <>
          <div className="fixed inset-0 z-20" onClick={() => setBellOpen(false)} />
          <div className="absolute right-0 top-full mt-1 min-w-[340px] card shadow-2xl z-30 animate-scale-in overflow-hidden rounded-xl border border-border bg-bg-elevated">
            <NotificationPopoverContent onClose={() => setBellOpen(false)} />
          </div>
        </>
      )}
    </div>
  )

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

        {bellButton}

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
                      nav('/settings')
                    }}
                    className="w-full px-3 py-2 text-left text-sm hover:bg-fg/5 text-fg flex items-center gap-2"
                  >
                    <Monitor size={16} />
                    {t('settings.title')}
                  </button>
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

      {isMobile && (
        <>
          {bellOpen && (
            <BottomSheet
              open={bellOpen}
              onClose={() => setBellOpen(false)}
              title={t('notify.recent', { defaultValue: '最近通知' })}
            >
              <NotificationPopoverContent onClose={() => setBellOpen(false)} />
            </BottomSheet>
          )}
          {menuOpen && (
            <div className="absolute top-full left-0 right-0 bg-bg-elevated border-b border-border shadow-lg animate-slide-right z-20">
              <div className="p-4 space-y-3">
                <div className="text-sm font-medium text-fg">{user?.username || t('common.admin')}</div>
                <button
                  onClick={() => {
                    setMenuOpen(false)
                    nav('/settings')
                  }}
                  className="w-full h-11 flex items-center gap-3 px-3 rounded-lg text-fg hover:bg-bg-sunken transition-colors min-h-[44px]"
                >
                  <Monitor size={18} />
                  <span>{t('settings.title')}</span>
                </button>
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
        </>
      )}
    </header>
  )
}
