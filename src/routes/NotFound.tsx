import { Link, useNavigate } from 'react-router-dom'
import { Home, ArrowLeft } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { useI18n } from '@/hooks/useI18n'

export default function NotFound() {
  const { t } = useI18n()
  const navigate = useNavigate()

  return (
    <div className="min-h-screen flex items-center justify-center p-4 bg-bg">
      <div className="w-full max-w-md">
        <Card className="p-8 md:p-10 text-center card-hover">
          <div className="mb-6">
            <div className="text-[80px] md:text-[100px] font-bold leading-none bg-gradient-to-br from-accent to-info bg-clip-text text-transparent">
              404
            </div>
          </div>

          <h1 className="text-xl font-semibold text-fg mb-2">
            {t('notFound.title')}
          </h1>
          <p className="text-sm text-fg-muted mb-8">
            {t('notFound.description')}
          </p>

          <div className="flex flex-col sm:flex-row gap-3 justify-center">
            <Link to="/dashboard" className="btn-primary btn-md">
              <Home size={16} />
              {t('notFound.goHome')}
            </Link>
            <Button variant="secondary" onClick={() => navigate(-1)}>
              <ArrowLeft size={16} />
              {t('notFound.goBack')}
            </Button>
          </div>

          <div className="mt-8 pt-6 border-t border-border">
            <p className="text-xs text-fg-subtle">
              {t('notFound.helpText')}
            </p>
          </div>
        </Card>
      </div>
    </div>
  )
}
