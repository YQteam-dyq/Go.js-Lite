import { create } from 'zustand'
import { persist } from 'zustand/middleware'
import type { ThemeMode, Language } from '@shared/types'
import {
  UI_STORAGE_KEY,
  detectBrowserLanguage,
  normalizeLanguage,
  normalizeSelection,
  normalizeTheme,
  readStorageItem,
  sameSelection,
  writeStorageItem,
} from '@/lib/storage'

const SIDEBAR_COLLAPSED_KEY = 'gojs_sidebar_collapsed'

const EMPTY_SELECTION: Set<string> = new Set<string>()

function getInitialSidebarCollapsed(): boolean {
  return readStorageItem(SIDEBAR_COLLAPSED_KEY) === '1'
}

function persistSidebarCollapsed(collapsed: boolean): void {
  writeStorageItem(SIDEBAR_COLLAPSED_KEY, collapsed ? '1' : '0')
}

interface UiState {
  theme: ThemeMode
  language: Language
  sidebarOpen: boolean
  sidebarCollapsed: boolean
  multiSelection: Set<string>
  toasts: ToastItem[]

  setTheme: (theme: ThemeMode) => void
  setLanguage: (lang: Language) => void
  toggleSidebar: () => void
  setSidebar: (open: boolean) => void
  toggleSidebarCollapsed: () => void
  setSidebarCollapsed: (collapsed: boolean) => void

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
      language: detectBrowserLanguage(),
      sidebarOpen: true,
      sidebarCollapsed: getInitialSidebarCollapsed(),
      multiSelection: EMPTY_SELECTION,
      toasts: [],

      setTheme: (theme) => set({ theme: normalizeTheme(theme) }),
      setLanguage: (language) => set({ language: normalizeLanguage(language) }),
      toggleSidebar: () => set({ sidebarOpen: !get().sidebarOpen }),
      setSidebar: (open) => set({ sidebarOpen: open }),
      toggleSidebarCollapsed: () => {
        const next = !get().sidebarCollapsed
        persistSidebarCollapsed(next)
        set({ sidebarCollapsed: next })
      },
      setSidebarCollapsed: (collapsed) => {
        persistSidebarCollapsed(collapsed)
        set({ sidebarCollapsed: collapsed })
      },

      toggleSelection: (path) => {
        if (typeof path !== 'string' || path.length === 0) return
        const current = get().multiSelection
        const next = new Set(current)
        if (next.has(path)) next.delete(path)
        else next.add(path)
        set({ multiSelection: next.size === 0 ? EMPTY_SELECTION : next })
      },
      clearSelection: () => {
        if (get().multiSelection.size === 0) return
        set({ multiSelection: EMPTY_SELECTION })
      },
      setSelection: (paths) => {
        const next = normalizeSelection(paths)
        const current = get().multiSelection
        if (sameSelection(current, next)) return
        set({ multiSelection: next.size === 0 ? EMPTY_SELECTION : next })
      },

      addToast: (toast) => {
        const id = `toast-${++toastId}`
        const duration = toast.duration ?? 3000
        set({
          toasts: [...get().toasts, { ...toast, id }],
        })
        if (duration > 0) {
          setTimeout(() => {
            get().removeToast(id)
          }, duration)
        }
        return id
      },
      removeToast: (id) => {
        const current = get().toasts
        if (!current.some((t) => t.id === id)) return
        set({ toasts: current.filter((t) => t.id !== id) })
      },
    }),
    {
      name: UI_STORAGE_KEY,
      version: 1,
      partialize: (state) => ({
        theme: state.theme,
        language: state.language,
      }),
      merge: (persisted, current) => {
        const stored = (persisted ?? {}) as Partial<UiState>
        return {
          ...current,
          theme: normalizeTheme(stored.theme, current.theme),
          language: normalizeLanguage(stored.language, current.language),
        }
      },
    },
  ),
)

export const selectTheme = (state: UiState): ThemeMode => state.theme
export const selectLanguage = (state: UiState): Language => state.language
export const selectMultiSelection = (state: UiState): Set<string> => state.multiSelection
export const selectSelectedCount = (state: UiState): number => state.multiSelection.size
export const selectToasts = (state: UiState): ToastItem[] => state.toasts

export const EMPTY_MULTI_SELECTION = EMPTY_SELECTION
