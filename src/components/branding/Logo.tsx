interface LogoProps {
  size?: 'sm' | 'md' | 'lg'
  showText?: boolean
  className?: string
}

const sizeMap = {
  sm: { icon: 24, text: 'text-sm', gap: 'gap-2' },
  md: { icon: 32, text: 'text-lg', gap: 'gap-2.5' },
  lg: { icon: 48, text: 'text-2xl', gap: 'gap-3' },
}

export function Logo({ size = 'md', showText = true, className = '' }: LogoProps) {
  const config = sizeMap[size]

  return (
    <div className={`inline-flex items-center ${config.gap} ${className}`}>
      <svg
        width={config.icon}
        height={config.icon}
        viewBox="0 0 48 48"
        fill="none"
        xmlns="http://www.w3.org/2000/svg"
        className="shrink-0"
      >
        <rect x="4" y="4" width="40" height="40" rx="10" className="fill-accent" />
        <path
          d="M24 14C18.477 14 14 18.477 14 24C14 29.523 18.477 34 24 34C26.626 34 29.028 32.984 30.82 31.258L28.156 28.52C26.983 29.535 25.532 30.125 24 30.125C20.686 30.125 18 27.339 18 24C18 20.661 20.686 17.875 24 17.875C27.314 17.875 30 20.661 30 24V26H34V24C34 18.477 29.523 14 24 14Z"
          className="fill-accent-fg"
        />
      </svg>
      {showText && (
        <div className={`font-semibold ${config.text} text-fg tracking-tight leading-none`}>
          Go<span className="text-accent">.</span><span className="font-mono font-normal">js</span>
        </div>
      )}
    </div>
  )
}
