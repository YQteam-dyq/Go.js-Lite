import type { HTMLAttributes, ReactNode } from 'react'

interface BadgeProps extends HTMLAttributes<HTMLSpanElement> {
  variant?: 'accent' | 'success' | 'danger' | 'warning' | 'muted'
  children: ReactNode
}

export function Badge({ variant = 'muted', className = '', children, ...props }: BadgeProps) {
  const variants = {
    accent: 'badge-accent',
    success: 'badge-success',
    danger: 'badge-danger',
    warning: 'badge-warning',
    muted: 'badge-muted',
  }

  return (
    <span className={`${variants[variant]} ${className}`} {...props}>
      {children}
    </span>
  )
}
