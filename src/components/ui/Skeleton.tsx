import type { HTMLAttributes } from 'react'

interface SkeletonProps extends HTMLAttributes<HTMLDivElement> {
  variant?: 'text' | 'circular' | 'rectangular'
  width?: string | number
  height?: string | number
}

export function Skeleton({
  variant = 'text',
  width,
  height,
  className = '',
  style,
  ...props
}: SkeletonProps) {
  const baseClass = 'skeleton'

  const variantClasses = {
    text: 'h-4 rounded',
    circular: 'rounded-full',
    rectangular: 'rounded-lg',
  }

  const sizeStyle: React.CSSProperties = {
    ...style,
    ...(width !== undefined ? { width: typeof width === 'number' ? `${width}px` : width } : {}),
    ...(height !== undefined ? { height: typeof height === 'number' ? `${height}px` : height } : {}),
  }

  return (
    <div
      className={`${baseClass} ${variantClasses[variant]} ${className}`}
      style={sizeStyle}
      aria-hidden="true"
      {...props}
    />
  )
}

export function SkeletonText({
  lines = 3,
  className = '',
}: {
  lines?: number
  className?: string
}) {
  return (
    <div className={`space-y-2 ${className}`}>
      {Array.from({ length: lines }).map((_, i) => (
        <Skeleton
          key={i}
          variant="text"
          className={i === lines - 1 && lines > 1 ? 'w-3/4' : ''}
        />
      ))}
    </div>
  )
}

export function SkeletonCard({
  className = '',
}: {
  className?: string
}) {
  return (
    <div className={`card p-5 space-y-4 ${className}`}>
      <div className="flex items-center gap-3">
        <Skeleton variant="circular" width={40} height={40} />
        <div className="flex-1 space-y-2">
          <Skeleton variant="text" className="w-1/2" />
          <Skeleton variant="text" className="w-1/3 h-3" />
        </div>
      </div>
      <div className="space-y-2">
        <Skeleton variant="text" />
        <Skeleton variant="text" className="w-5/6" />
        <Skeleton variant="text" className="w-2/3" />
      </div>
    </div>
  )
}

export function SkeletonList({
  count = 5,
  className = '',
}: {
  count?: number
  className?: string
}) {
  return (
    <div className={`divide-y divide-border ${className}`}>
      {Array.from({ length: count }).map((_, i) => (
        <div key={i} className="flex items-center gap-3 px-4 py-3">
          <Skeleton variant="circular" width={36} height={36} />
          <div className="flex-1 space-y-2">
            <Skeleton variant="text" className="w-2/3" />
            <Skeleton variant="text" className="w-1/3 h-3" />
          </div>
          <Skeleton variant="text" className="w-16 h-3" />
        </div>
      ))}
    </div>
  )
}

export function SkeletonDashboard({
  className = '',
}: {
  className?: string
}) {
  return (
    <div className={`space-y-5 ${className}`}>
      <div className="space-y-2">
        <Skeleton variant="text" className="w-32 h-7" />
        <Skeleton variant="text" className="w-48 h-4" />
      </div>

      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        <SkeletonCard />
        <SkeletonCard />
        <SkeletonCard />
      </div>

      <div className="card">
        <div className="flex items-center gap-3 px-5 py-4 border-b border-border">
          <Skeleton variant="circular" width={40} height={40} />
          <div className="flex-1 space-y-2">
            <Skeleton variant="text" className="w-2/5" />
            <Skeleton variant="text" className="w-1/3 h-3" />
          </div>
          <Skeleton variant="text" className="w-16" />
        </div>
        <SkeletonList count={5} />
      </div>
    </div>
  )
}

export function SkeletonTable({
  rows = 6,
  columns = 4,
  className = '',
}: {
  rows?: number
  columns?: number
  className?: string
}) {
  return (
    <div className={`card overflow-hidden ${className}`}>
      <div className="hidden md:flex items-center gap-2 px-4 py-2 border-b border-border bg-bg-sunken/50">
        {Array.from({ length: columns }).map((_, i) => (
          <Skeleton
            key={i}
            variant="text"
            className={`flex-1 ${i === 0 ? '' : 'text-right'} h-3`}
            style={{ maxWidth: i === 0 ? 'none' : '120px' }}
          />
        ))}
      </div>
      <SkeletonList count={rows} />
    </div>
  )
}
