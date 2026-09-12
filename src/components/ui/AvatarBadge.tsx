export const AVATAR_PALETTE: readonly string[] = [
  '#ef4444',
  '#f59e0b',
  '#10b981',
  '#3b82f6',
  '#8b5cf6',
  '#ec4899',
  '#14b8a6',
  '#f97316',
] as const

export function pickAvatarColor(seed: string): string {
  let h = 0
  for (let i = 0; i < seed.length; i++) {
    h = (h * 31 + seed.charCodeAt(i)) | 0
  }
  const idx = Math.abs(h) % AVATAR_PALETTE.length
  return AVATAR_PALETTE[idx]
}

function initialsOf(name: string): string {
  if (!name) return '?'
  const parts = name.split(/[\s._-]+/).filter(Boolean)
  if (parts.length === 0) return name.slice(0, 1).toUpperCase()
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase()
  return (parts[0][0] + parts[1][0]).toUpperCase()
}

const SIZE_CLASS = {
  sm: 'w-7 h-7 text-xs',
  md: 'w-9 h-9 text-sm',
  lg: 'w-12 h-12 text-base',
} as const

export interface AvatarBadgeProps {
  username?: string | null
  color?: string | null
  size?: keyof typeof SIZE_CLASS
  className?: string
  title?: string
}

export function AvatarBadge({
  username,
  color,
  size = 'md',
  className = '',
  title,
}: AvatarBadgeProps) {
  const fallbackColor = pickAvatarColor(username || 'user')
  const bg = color || fallbackColor
  const initials = initialsOf(username || '?')

  const cls = ['inline-flex', 'items-center', 'justify-center', 'rounded-full',
    'font-semibold', 'text-white', 'shrink-0', SIZE_CLASS[size], className]
    .filter(Boolean).join(' ')

  return (
    <span
      title={title || username || undefined}
      aria-label={username ? `avatar of ${ username }` : 'avatar'}
      className={cls}
      style={{ backgroundColor: bg }}
    >
      {initials}
    </span>
  )
}