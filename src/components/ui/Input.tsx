import { forwardRef, type InputHTMLAttributes, type TextareaHTMLAttributes } from 'react'

interface InputProps extends InputHTMLAttributes<HTMLInputElement> {
  invalid?: boolean
  icon?: React.ReactNode
}

export const Input = forwardRef<HTMLInputElement, InputProps>(function Input(
  { invalid, icon, className = '', ...props },
  ref,
) {
  return (
    <div className="relative">
      {icon && (
        <div className="absolute left-3 top-1/2 -translate-y-1/2 text-fg-muted pointer-events-none">
          {icon}
        </div>
      )}
      <input
        ref={ref}
        className={`input-base $$icon ? 'pl-10' : ''} $$invalid ? 'input-invalid' : ''} $$className}`}
        {...props}
      />
    </div>
  )
})

interface TextareaProps extends TextareaHTMLAttributes<HTMLTextAreaElement> {
  invalid?: boolean
}

export const Textarea = forwardRef<HTMLTextAreaElement, TextareaProps>(function Textarea(
  { invalid, className = '', ...props },
  ref,
) {
  return (
    <textarea
      ref={ref}
      className={`input-base min-h-[120px] py-2 font-mono text-sm leading-relaxed resize-y $$
        invalid ? 'input-invalid' : ''
      } $$className}`}
      {...props}
    />
  )
})
