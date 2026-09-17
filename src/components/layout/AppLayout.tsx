import { useEffect, useMemo, useState, type ReactNode } from 'react'
import { useNavigate } from 'react-router-dom'
import { Activity, Moon, PanelLeft, Sun } from 'lucide-react'
import { Sidebar } from './Sidebar'
import { TopBar } from './TopBar'
import { BottomTab } from './BottomTab'
import { OfflineBanner } from './OfflineBanner'
import { CommandPalette, type CommandItem } from '@/components/ui/CommandPalette'
import { useIsMobile } from '@/hooks/useMediaQuery'
import { useCapabilities } from '@/hooks/useCapabilities'
import { useI18n } from '@/hooks/useI18n'
import { useAuthStore } from '@/stores/authStore'
import { useUiStore } from '@/stores/uiStore'
import { buildNavItems } from '@/lib/navigation'

interface AppLayoutProps {
  children: ReactNode
}

export default function AppLayout({ children }: AppLayoutProps) {
  const isMobile = useIsMobile()
  const navigate = useNavigate()
  const caps = useCapabilities()
  const { t } = useI18n()
  const theme = useUiStore((s) => s.theme)
  const setTheme = useUiStore((s) => s.setTheme)
  const role = useAuthStore((s) => s.user?.role)
  const toggleSidebar = useUiStore((s) => s.toggleSidebar)
  const [paletteOpen, setPaletteOpen] = useState(false)

  useEffect(() => {
    const handleKeyDown = (event: KeyboardEvent) => {
      if (event.key.toLowerCase() !== 'k') return
      if (!event.metaKey && !event.ctrlKey) return
      event.preventDefault()
      setPaletteOpen((open) => !open)
    }
    window.addEventListener('keydown', handleKeyDown)
    return () => window.removeEventListener('keydown', handleKeyDown)
  }, [])

  const commands = useMemo<CommandItem[]>(() => {
    const navigation = buildNavItems({ caps, role })
      .filter((item) => item.show)
      .map((item) => {
        const Icon = item.icon
        return {
          id: `nav-${item.to}`,
          label: t(item.labelKey),
          group: t('commandPalette.groupNavigation'),
          keywords: item.to,
          icon: <Icon size={16} />,
          run: () => navigate(item.to),
        }
      })

    const actions: CommandItem[] = [
      {
        id: 'action-theme',
        label: t('commandPalette.toggleTheme'),
        group: t('commandPalette.groupActions'),
        icon: theme === 'dark' ? <Sun size={16} /> : <Moon size={16} />,
        run: () => setTheme(theme === 'dark' ? 'light' : 'dark'),
      },
      {
        id: 'action-sidebar',
        label: t('commandPalette.toggleSidebar'),
        group: t('commandPalette.groupActions'),
        icon: <PanelLeft size={16} />,
        run: () => toggleSidebar(),
      },
      {
        id: 'action-status',
        label: t('commandPalette.openStatusPage'),
        group: t('commandPalette.groupActions'),
        icon: <Activity size={16} />,
        run: () => navigate('/status'),
      },
    ]

    return [...navigation, ...actions]
  }, [caps, role, t, navigate, setTheme, toggleSidebar, theme])

  return (
    <div className="h-screen overflow-hidden flex bg-bg">
      <Sidebar />
      <div className="flex-1 flex flex-col min-w-0">
        <TopBar />
        <OfflineBanner />
        <main
          className={`
            flex-1 min-w-0 overflow-y-auto
            ${isMobile ? 'pb-16' : ''}
          `}
        >
          {children}
        </main>
      </div>
      <BottomTab />
      <CommandPalette
        open={paletteOpen}
        onClose={() => setPaletteOpen(false)}
        commands={commands}
      />
    </div>
  )
}
