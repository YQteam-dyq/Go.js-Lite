import { create } from 'zustand'
import { persist } from 'zustand/middleware'
import type { ThemeMode, Language } from '@shared/types'

function getInitialLanguage(): Language {
  if (typeof navigator === 'undefined') return 'zh'
  const lang = navigator.language
  if (lang.startsWith('zh')) return 'zh'
  return 'en'
}

interface UiState {
  theme: ThemeMode
  language: Language
  sidebarOpen: boolean
  multiSelection: Set<string>
  toasts: ToastItem[]

  setTheme: (theme: ThemeMode) => void
  setLanguage: (lang: Language) => void
  toggleSidebar: () => void
  setSidebar: (open: boolean) => void

  toggleSelection: (path: string) => void
  clearSelection: () => void
  setSelection: (paths: string[]) => void

  addToast: (toast: Omit<ToastItem, 'id'>) => string
  removeToast: (id: string) => void
}

export interface ToastItem {
  id: string
  type: 'success' | 'error' | 'info' | 'warning'
  title: string
  description?: string
  duration?: number
}

let toastId = 0

export const useUiStore = create<UiState>()(
  persist(
    (set, get) => ({
      theme: 'system',
      language: getInitialLanguage(),
      sidebarOpen: true,
      multiSelection: new Set(),
      toasts: [],

      setTheme: (theme) => set({ theme }),
      setLanguage: (language) => set({ language }),
      toggleSidebar: () => set({ sidebarOpen: !get().sidebarOpen }),
      setSidebar: (open) => set({ sidebarOpen: open }),

      toggleSelection: (path) => {
        const next = new Set(get().multiSelection)
        if (next.has(path)) next.delete(path)
        else next.add(path)
        set({ multiSelection: next })
      },
      clearSelection: () => set({ multiSelection: new Set() }),
      setSelection: (paths) => set({ multiSelection: new Set(paths) }),

      addToast: (toast) => {
        const id = `toast-${++toastId}`
        const duration = toast.duration ?? 3000
        set({
          toasts: [...get().toasts, { ...toast, id }],
        })
        if (duration > 0) {
          setTimeout(() => {
            set({ toasts: get().toasts.filter((t) => t.id !== id) })
          }, duration)
        }
        return id
      },
      removeToast: (id) => {
        set({ toasts: get().toasts.filter((t) => t.id !== id) })
      },
    }),
    {
      name: 'gojs-ui',
      partialize: (state) => ({
        theme: state.theme,
        language: state.language,
      }),
    },
  ),
)
