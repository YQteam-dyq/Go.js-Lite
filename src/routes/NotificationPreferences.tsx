import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { BellRing, Save, RotateCcw } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Card, CardBody, CardHeader } from '@/components/ui/Card'
import { Badge } from '@/components/ui/Badge'
import { Spinner } from '@/components/ui/Spinner'
import { toast } from '@/components/ui/Toast'
import {
  notificationPrefsApi,
  type NotificationPreferences as Prefs,
  type NotificationChannelPrefs,
} from '@/api/notificationPrefs'
import { useI18n } from '@/hooks/useI18n'
import { resolveErrorText } from '@/lib/errorMessages'

const CHANNELS: Array<{ key: keyof Prefs; icon: string }> = [
  { key: 'email', icon: '✉️' },
  { key: 'inapp', icon: '🔔' },
]

export default function NotificationPreferencesPage() {
  const { t } = useI18n()
  const queryClient = useQueryClient()

  const { data, isLoading } = useQuery({
    queryKey: ['notification-preferences'],
    queryFn: () => notificationPrefsApi.get(),
  })

  const [draft, setDraft] = useState<Prefs | null>(null)

  useEffect(() => {
    if (data?.notifications) setDraft(data.notifications)
  }, [data])

  const saveMutation = useMutation({
    mutationFn: () => notificationPrefsApi.update(draft || {}),
    onSuccess: (res) => {
      toast({ type: 'success', title: t('notificationPrefs.saved') })
      setDraft(res.notifications)
      queryClient.invalidateQueries({ queryKey: ['notification-preferences'] })
      queryClient.invalidateQueries({ queryKey: ['profile'] })
    },
    onError: (err: Error) => {
      toast({ type: 'error', title: t('common.saveFailed'), description: resolveErrorText(err) })
    },
  })

  const levels = data?.levels || ['off', 'critical', 'warning', 'info']
  const categories = data?.categories || []

  const setChannel = (channel: keyof Prefs, patch: Partial<NotificationChannelPrefs>) => {
    if (!draft) return
    setDraft({ ...draft, [channel]: { ...draft[channel], ...patch } })
  }

  const toggleCategory = (channel: keyof Prefs, category: string) => {
    if (!draft) return
    const current = draft[channel].categories || []
    const next = current.includes(category)
      ? current.filter((c) => c !== category)
      : [...current, category]
    setChannel(channel, { categories: next })
  }

  const resetToDefaults = () => {
    if (data?.defaults) setDraft(data.defaults)
  }

  return (
    <div className="p-4 md:p-6 space-y-5 max-w-3xl mx-auto page-enter">
      <div className="stagger-1 flex items-center justify-between">
        <div>
          <h1 className="text-xl font-semibold text-fg flex items-center gap-2">
            <BellRing size={20} className="text-accent" />
            {t('notificationPrefs.title')}
          </h1>
          <p className="text-sm text-fg-muted mt-0.5">{t('notificationPrefs.subtitle')}</p>
        </div>
        <div className="flex items-center gap-2">
          <Button variant="ghost" onClick={resetToDefaults} disabled={!data}>
            <RotateCcw size={16} />
            {t('notificationPrefs.resetDefaults')}
          </Button>
          <Button
            variant="primary"
            onClick={() => saveMutation.mutate()}
            loading={saveMutation.isPending}
            disabled={!draft}
          >
            <Save size={16} />
            {t('common.save')}
          </Button>
        </div>
      </div>

      <div className="stagger-2 text-sm text-fg-muted bg-bg-sunken rounded-lg px-3 py-2">
        {t('notificationPrefs.roleHint', { role: data?.role || '' })}
      </div>

      {isLoading || !draft ? (
        <div className="flex justify-center py-10">
          <Spinner size="lg" />
        </div>
      ) : (
        CHANNELS.map(({ key, icon }) => (
          <Card key={key} className="stagger-2 card-hover">
            <CardHeader>
              <div className="text-sm font-semibold text-fg">
                <span className="mr-2">{icon}</span>
                {t(`notificationPrefs.channel_${key}`)}
              </div>
              <div className="text-xs text-fg-subtle">
                {draft[key].severity_min === 'off'
                  ? t('notificationPrefs.disabledHint')
                  : t('notificationPrefs.enabledHint')}
              </div>
            </CardHeader>
            <CardBody className="space-y-4">
              <div>
                <label className="block text-sm font-medium text-fg mb-1">
                  {t('notificationPrefs.severityMin')}
                </label>
                <select
                  className="w-full h-10 rounded-lg border border-border bg-bg-elevated px-3 text-sm"
                  value={draft[key].severity_min}
                  onChange={(e) => setChannel(key, { severity_min: e.target.value })}
                >
                  {levels.map((lvl) => (
                    <option key={lvl} value={lvl}>{t(`notificationPrefs.level_${lvl}`)}</option>
                  ))}
                </select>
                <p className="text-xs text-fg-subtle mt-1">{t('notificationPrefs.severityHint')}</p>
              </div>

              <div>
                <label className="block text-sm font-medium text-fg mb-2">
                  {t('notificationPrefs.categories')}
                </label>
                <div className="flex flex-wrap gap-2">
                  {categories.map((cat) => {
                    const active = (draft[key].categories || []).includes(cat)
                    return (
                      <button
                        key={cat}
                        type="button"
                        onClick={() => toggleCategory(key, cat)}
                        className={`px-3 py-1.5 rounded-full border text-xs transition-colors ${
                          active
                            ? 'border-accent bg-accent/10 text-accent font-medium'
                            : 'border-border text-fg-muted hover:text-fg hover:bg-bg-sunken/50'
                        }`}
                      >
                        {t(`notificationPrefs.category_${cat}`)}
                      </button>
                    )
                  })}
                </div>
                <p className="text-xs text-fg-subtle mt-2">
                  {(draft[key].categories || []).length === 0
                    ? t('notificationPrefs.allCategories')
                    : t('notificationPrefs.filteredCategories', { count: draft[key].categories.length })}
                </p>
              </div>
            </CardBody>
          </Card>
        ))
      )}

      {data?.role !== 'admin' && (
        <div className="stagger-3 flex items-center gap-2 text-xs text-fg-subtle">
          <Badge variant="muted">{data?.role}</Badge>
          {t('notificationPrefs.nonAdminNote')}
        </div>
      )}
    </div>
  )
}
