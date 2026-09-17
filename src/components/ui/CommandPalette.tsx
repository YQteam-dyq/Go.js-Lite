import { useCallback, useEffect, useId, useMemo, useRef, useState, type ReactNode } from 'react'
import { CornerDownLeft, Search } from 'lucide-react'
import { filterCommands } from '@/lib/navigation'
import { useFocusTrap } from '@/hooks/useFocusTrap'
import { useI18n } from '@/hooks/useI18n'

export interface CommandItem {
  id: string
  label: string
  group: string
  keywords?: string
  icon?: ReactNode
  run: () => void
}

export interface CommandPaletteProps {
  open: boolean
  onClose: () => void
  commands: CommandItem[]
}

export function CommandPalette({ open, onClose, commands }: CommandPaletteProps) {
  const { t } = useI18n()
  const dialogRef = useRef<HTMLDivElement>(null)
  const listRef = useRef<HTMLDivElement>(null)
  const [query, setQuery] = useState('')
  const [activeIndex, setActiveIndex] = useState(0)
  const listboxId = useId()

  useFocusTrap(dialogRef, { active: open, onEscape: onClose })

  useEffect(() => {
    if (!open) return
    setQuery('')
    setActiveIndex(0)
  }, [open])

  const results = useMemo(() => filterCommands(commands, query), [commands, query])

  useEffect(() => {
    setActiveIndex(0)
  }, [query])

  useEffect(() => {
    if (!open) return
    const node = listRef.current?.querySelector<HTMLElement>('[data-active="true"]')
    node?.scrollIntoView?.({ block: 'nearest' })
  }, [open, activeIndex])

  const runAt = useCallback(
    (index: number) => {
      const command = results[index]
      if (!command) return
      onClose()
      command.run()
    },
    [results, onClose],
  )

  const grouped = useMemo(() => {
    const map = new Map<string, { item: CommandItem; index: number }[]>()
    results.forEach((item, index) => {
      const bucket = map.get(item.group) ?? []
      bucket.push({ item, index })
      map.set(item.group, bucket)
    })
    return Array.from(map.entries())
  }, [results])

  if (!open) return null

  const active = results[activeIndex]
  const activeId = active ? `${listboxId}-${active.id}` : undefined

  const handleKeyDown = (event: React.KeyboardEvent<HTMLDivElement>) => {
    if (event.key === 'ArrowDown') {
      event.preventDefault()
      setActiveIndex((index) => Math.min(index + 1, Math.max(0, results.length - 1)))
      return
    }
    if (event.key === 'ArrowUp') {
      event.preventDefault()
      setActiveIndex((index) => Math.max(index - 1, 0))
      return
    }
    if (event.key === 'Enter') {
      event.preventDefault()
      runAt(activeIndex)
    }
  }

  return (
    <div className="fixed inset-0 z-[60] flex items-start justify-center p-4 pt-[12vh]">
      <div
        className="absolute inset-0 bg-black/50 backdrop-blur-sm animate-fade-in"
        onClick={onClose}
      />
      <div
        ref={dialogRef}
        tabIndex={-1}
        role="dialog"
        aria-modal="true"
        aria-label={t('commandPalette.title')}
        onKeyDown={handleKeyDown}
        className="relative w-full max-w-lg bg-bg-elevated rounded-2xl shadow-2xl border border-border/50 animate-scale-in outline-none overflow-hidden"
      >
        <div className="flex items-center gap-3 px-4 py-3 border-b border-border">
          <Search size={18} className="text-fg-subtle shrink-0" />
          <input
            type="text"
            value={query}
            onChange={(event) => setQuery(event.target.value)}
            placeholder={t('commandPalette.placeholder')}
            aria-label={t('commandPalette.placeholder')}
            role="combobox"
            aria-expanded="true"
            aria-controls={listboxId}
            aria-activedescendant={activeId}
            autoComplete="off"
            className="flex-1 bg-transparent text-sm text-fg placeholder:text-fg-subtle outline-none"
          />
        </div>

        <div
          ref={listRef}
          id={listboxId}
          role="listbox"
          aria-label={t('commandPalette.title')}
          className="max-h-[22rem] overflow-y-auto py-2"
        >
          {results.length === 0 && (
            <p className="px-4 py-8 text-center text-sm text-fg-muted">
              {t('commandPalette.empty')}
            </p>
          )}
          {grouped.map(([group, entries]) => (
            <div key={group} role="group" aria-label={group}>
              <div className="px-4 pt-2 pb-1 text-[10px] font-semibold uppercase tracking-wider text-fg-subtle">
                {group}
              </div>
              {entries.map(({ item, index }) => (
                <div
                  key={item.id}
                  id={`${listboxId}-${item.id}`}
                  role="option"
                  aria-selected={index === activeIndex}
                  data-active={index === activeIndex}
                  onMouseEnter={() => setActiveIndex(index)}
                  onClick={() => runAt(index)}
                  className={`
                    flex items-center gap-3 px-4 py-2.5 text-sm cursor-pointer
                    ${index === activeIndex ? 'bg-accent/10 text-accent' : 'text-fg hover:bg-fg/5'}
                  `}
                >
                  {item.icon && <span className="shrink-0">{item.icon}</span>}
                  <span className="flex-1 truncate">{item.label}</span>
                  {index === activeIndex && <CornerDownLeft size={14} className="shrink-0 opacity-60" />}
                </div>
              ))}
            </div>
          ))}
        </div>

        <div className="px-4 py-2 border-t border-border text-[11px] text-fg-subtle">
          {t('commandPalette.hint')}
        </div>
      </div>
    </div>
  )
}
