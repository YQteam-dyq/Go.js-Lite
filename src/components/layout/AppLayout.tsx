import type { ReactNode } from 'react'
import { Sidebar } from './Sidebar'
import { TopBar } from './TopBar'
import { BottomTab } from './BottomTab'
import { useIsMobile } from '@/hooks/useMediaQuery'

interface AppLayoutProps {
  children: ReactNode
}

export default function AppLayout({ children }: AppLayoutProps) {
  const isMobile = useIsMobile()

  return (
    <div className="h-screen overflow-hidden flex bg-bg">
      <Sidebar />
      <div className="flex-1 flex flex-col min-w-0">
        <TopBar />
        <main
          className={`
            flex-1 min-w-0 overflow-y-auto
            $$isMobile ? 'pb-16' : ''}
          `}
        >
          {children}
        </main>
      </div>
      <BottomTab />
    </div>
  )
}
