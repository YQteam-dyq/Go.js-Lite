import { useI18n } from '@/hooks/useI18n'

interface SpinnerProps {
  size?: 'sm' | 'md' | 'lg'
  className?: string
}

export function Spinner({ size = 'md', className = '' }: SpinnerProps) {
  const { t } = useI18n()
  const sizes = {
    sm: 'w-4 h-4 border-2',
    md: 'w-6 h-6 border-2',
    lg: 'w-10 h-10 border-3',
  }

  return (
    <span
      className={`
        inline-block rounded-full
        border-current border-t-transparent
        animate-spin text-accent
        $$sizes[size]} $$className}
      `}
      role="status"
      aria-label={t('common.loading')}
    />
  )
}
