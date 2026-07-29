import { useState, Suspense, lazy, useEffect, useMemo } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Save, RotateCcw, FileText, AlertTriangle, Wand2, Check } from 'lucide-react'
import { Card, CardBody, CardHeader } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Badge } from '@/components/ui/Badge'
import { Spinner } from '@/components/ui/Spinner'
import { Confirm } from '@/components/ui/Modal'
import { htaccessApi } from '@/api/htaccess'
import { toast } from '@/components/ui/Toast'
import { useI18n } from '@/hooks/useI18n'
import type { HtaccessRuleType } from '@shared/types'

const CodeEditor = lazy(() =>
  import('@/components/editor/CodeEditor').then((m) => ({ default: m.CodeEditor })),
)

const RULE_IDS: HtaccessRuleType[] = [
  'force_https',
  'block_sensitive',
  'prevent_hotlink',
  'redirect_301',
  'gzip_compress',
  'browser_cache',
  'block_dir_browsing',
]

type RuleI18nKey = {
  name: `htaccess.rule${string}`
  desc: `htaccess.rule${string}Desc`
}

const RULE_I18N_KEYS: Record<HtaccessRuleType, RuleI18nKey> = {
  force_https: { name: 'htaccess.ruleForceHttps', desc: 'htaccess.ruleForceHttpsDesc' },
  block_sensitive: { name: 'htaccess.ruleBlockSensitive', desc: 'htaccess.ruleBlockSensitiveDesc' },
  prevent_hotlink: { name: 'htaccess.rulePreventHotlink', desc: 'htaccess.rulePreventHotlinkDesc' },
  redirect_301: { name: 'htaccess.ruleRedirect301', desc: 'htaccess.ruleRedirect301Desc' },
  gzip_compress: { name: 'htaccess.ruleGzipCompress', desc: 'htaccess.ruleGzipCompressDesc' },
  browser_cache: { name: 'htaccess.ruleBrowserCache', desc: 'htaccess.ruleBrowserCacheDesc' },
  block_dir_browsing: { name: 'htaccess.ruleBlockDirBrowsing', desc: 'htaccess.ruleBlockDirBrowsingDesc' },
}

export default function Htaccess() {
  const { t } = useI18n()
  const queryClient = useQueryClient()

  const [content, setContent] = useState('')
  const [selectedRules, setSelectedRules] = useState<Set<HtaccessRuleType>>(new Set())
  const [redirectFrom, setRedirectFrom] = useState('')
  const [redirectTo, setRedirectTo] = useState('')
  const [showReset, setShowReset] = useState(false)
  const [resetting, setResetting] = useState(false)

  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['htaccess'],
    queryFn: () => htaccessApi.get(),
  })

  useEffect(() => {
    if (data) {
      setContent(data.content)
    }
  }, [data])

  const saveMutation = useMutation({
    mutationFn: (text: string) => htaccessApi.save(text),
    onSuccess: () => {
      toast({ type: 'success', title: t('htaccess.saveSuccess') })
      queryClient.invalidateQueries({ queryKey: ['htaccess'] })
    },
    onError: (err: Error) => {
      toast({ type: 'error', title: t('htaccess.saveFailed'), description: err.message })
    },
  })

  const generateMutation = useMutation({
    mutationFn: (params: {
      rules: HtaccessRuleType[]
      from?: string
      to?: string
    }) => htaccessApi.generate(params),
    onSuccess: (res) => {
      setContent(res.content)
      toast({ type: 'success', title: t('htaccess.generateSuccess') })
    },
    onError: (err: Error) => {
      toast({ type: 'error', title: t('htaccess.generateFailed'), description: err.message })
    },
  })

  const handleSave = () => {
    saveMutation.mutate(content)
  }

  const handleToggleRule = (id: HtaccessRuleType) => {
    setSelectedRules((prev) => {
      const next = new Set(prev)
      if (next.has(id)) {
        next.delete(id)
      } else {
        next.add(id)
      }
      return next
    })
  }

  const handleGenerate = () => {
    if (selectedRules.size === 0) {
      toast({ type: 'warning', title: t('htaccess.noRulesSelected') })
      return
    }
    const rules = Array.from(selectedRules)
    const params: { rules: HtaccessRuleType[]; from?: string; to?: string } = { rules }
    if (selectedRules.has('redirect_301')) {
      params.from = redirectFrom
      params.to = redirectTo
    }
    generateMutation.mutate(params)
  }

  const handleReset = async () => {
    setResetting(true)
    try {
      const res = await htaccessApi.reset()
      setContent(res.content)
      toast({ type: 'success', title: t('htaccess.resetSuccess') })
      setShowReset(false)
      queryClient.invalidateQueries({ queryKey: ['htaccess'] })
    } catch (err) {
      toast({
        type: 'error',
        title: t('htaccess.resetFailed'),
        description: err instanceof Error ? err.message : t('common.unknownError'),
      })
    } finally {
      setResetting(false)
    }
  }

  useEffect(() => {
    const handleKey = (e: KeyboardEvent) => {
      if ((e.ctrlKey || e.metaKey) && e.key === 's') {
        e.preventDefault()
        if (!saveMutation.isPending) {
          handleSave()
        }
      }
    }
    window.addEventListener('keydown', handleKey)
    return () => window.removeEventListener('keydown', handleKey)
    
  }, [content, saveMutation.isPending])

  const ruleItems = useMemo(
    () =>
      RULE_IDS.map((id) => ({
        id,
        name: t(RULE_I18N_KEYS[id].name),
        description: t(RULE_I18N_KEYS[id].desc),
      })),
    [t],
  )

  const writable = data?.writable ?? false
  const exists = data?.exists ?? false
  const dirty = data ? content !== data.content : false

  return (
    <div className="p-4 md:p-6 space-y-4">
      <div className="flex items-start justify-between gap-4 flex-wrap">
        <div className="min-w-0">
          <h1 className="text-xl font-semibold text-fg flex items-center gap-2">
            <FileText size={22} className="text-accent" />
            {t('htaccess.title')}
          </h1>
          <p className="text-sm text-fg-muted mt-0.5">{t('htaccess.subtitle')}</p>
        </div>
        <div className="flex items-center gap-2">
          <Button
            variant="secondary"
            size="sm"
            onClick={() => setShowReset(true)}
            disabled={isLoading}
          >
            <RotateCcw size={16} />
            <span className="hidden sm:inline">{t('htaccess.reset')}</span>
          </Button>
          <Button
            variant="primary"
            size="sm"
            onClick={handleSave}
            loading={saveMutation.isPending}
            disabled={isLoading || !writable}
          >
            <Save size={16} />
            <span className="hidden sm:inline">{t('htaccess.save')}</span>
          </Button>
        </div>
      </div>

      {data && !writable && (
        <div className="flex items-start gap-3 p-3 rounded-lg bg-warning/10 border border-warning/20 text-warning">
          <AlertTriangle size={18} className="shrink-0 mt-0.5" />
          <p className="text-sm">{t('htaccess.notWritableWarning')}</p>
        </div>
      )}

      {error ? (
        <Card className="p-6 text-center text-danger">
          <AlertTriangle size={24} className="mx-auto mb-2" />
          <p className="text-sm">
            {error instanceof Error ? error.message : t('htaccess.loadFailed')}
          </p>
          <Button variant="secondary" size="sm" className="mt-3" onClick={() => refetch()}>
            {t('common.retry')}
          </Button>
        </Card>
      ) : (
        <div className="grid grid-cols-1 lg:grid-cols-[340px_1fr] gap-4">
          <Card className="flex flex-col">
            <CardHeader className="flex items-center gap-2">
              <Wand2 size={16} className="text-accent" />
              <div>
                <div className="text-sm font-medium text-fg">{t('htaccess.rulesGenerator')}</div>
                <div className="text-xs text-fg-subtle">{t('htaccess.rulesGeneratorSubtitle')}</div>
              </div>
            </CardHeader>
            <CardBody className="flex-1 flex flex-col gap-3">
              <ul className="space-y-1.5">
                {ruleItems.map((rule) => {
                  const checked = selectedRules.has(rule.id)
                  return (
                    <li key={rule.id}>
                      <label
                        className={`
                          flex items-start gap-3 p-2.5 rounded-lg cursor-pointer
                          transition-colors duration-150
                          ${checked ? 'bg-accent/10' : 'hover:bg-fg/5'}
                        `}
                      >
                        <span
                          className={`
                            shrink-0 mt-0.5 w-5 h-5 rounded border flex items-center justify-center
                            transition-colors
                            ${
                              checked
                                ? 'bg-accent border-accent text-white'
                                : 'border-border bg-bg-sunken text-transparent'
                            }
                          `}
                        >
                          <Check size={14} strokeWidth={3} />
                        </span>
                        <input
                          type="checkbox"
                          className="sr-only"
                          checked={checked}
                          onChange={() => handleToggleRule(rule.id)}
                        />
                        <div className="min-w-0">
                          <div className="text-sm font-medium text-fg">{rule.name}</div>
                          <div className="text-xs text-fg-muted mt-0.5 leading-relaxed">
                            {rule.description}
                          </div>
                        </div>
                      </label>
                    </li>
                  )
                })}
              </ul>

              {selectedRules.has('redirect_301') && (
                <div className="space-y-2 p-3 rounded-lg bg-bg-sunken">
                  <div className="text-xs text-fg-muted">{t('htaccess.redirectHint')}</div>
                  <Input
                    value={redirectFrom}
                    onChange={(e) => setRedirectFrom(e.target.value)}
                    placeholder={t('htaccess.redirectFromPlaceholder')}
                  />
                  <Input
                    value={redirectTo}
                    onChange={(e) => setRedirectTo(e.target.value)}
                    placeholder={t('htaccess.redirectToPlaceholder')}
                  />
                </div>
              )}

              <div className="pt-1 flex items-center justify-between gap-2">
                <span className="text-xs text-fg-subtle">
                  {t('htaccess.selectedCount', { count: selectedRules.size })}
                </span>
                <Button
                  variant="primary"
                  size="sm"
                  onClick={handleGenerate}
                  loading={generateMutation.isPending}
                  disabled={selectedRules.size === 0}
                >
                  <Wand2 size={14} />
                  {t('htaccess.generate')}
                </Button>
              </div>
            </CardBody>
          </Card>

          <Card className="flex flex-col">
            <CardHeader className="flex items-center justify-between gap-2">
              <div className="flex items-center gap-2">
                <FileText size={16} className="text-fg-muted" />
                <div>
                  <div className="text-sm font-medium text-fg">{t('htaccess.editor')}</div>
                  <div className="text-xs text-fg-subtle">{t('htaccess.editorSubtitle')}</div>
                </div>
              </div>
              <div className="flex items-center gap-1.5">
                <Badge variant="muted" className="font-mono">
                  .htaccess
                </Badge>
                {dirty && (
                  <Badge variant="muted" className="text-warning">
                    *
                  </Badge>
                )}
              </div>
            </CardHeader>
            <CardBody className="p-0 flex-1 min-h-0">
              {isLoading ? (
                <div className="h-[400px] lg:h-[520px] flex items-center justify-center">
                  <Spinner size="lg" />
                </div>
              ) : (
                <div className="h-[400px] lg:h-[520px]">
                  <Suspense
                    fallback={
                      <div className="h-full flex items-center justify-center">
                        <Spinner />
                      </div>
                    }
                  >
                    <CodeEditor
                      value={content}
                      onChange={setContent}
                      filename=".htaccess"
                      readOnly={!writable}
                    />
                  </Suspense>
                </div>
              )}
            </CardBody>
          </Card>
        </div>
      )}

      <div className="flex items-center justify-between gap-4 text-xs text-fg-subtle flex-wrap">
        <div className="flex items-center gap-2">
          <span>{t('htaccess.filePath')}:</span>
          <code className="bg-bg-sunken px-1.5 py-0.5 rounded font-mono">.htaccess</code>
          {!exists && (
            <span className="text-warning flex items-center gap-1">
              <AlertTriangle size={12} />
              {t('htaccess.notExists')}
            </span>
          )}
        </div>
        <div className="flex items-center gap-2">
          <span
            className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium ${
              writable
                ? 'bg-success/10 text-success'
                : 'bg-fg-muted/10 text-fg-muted'
            }`}
          >
            <span
              className={`w-1.5 h-1.5 rounded-full ${writable ? 'bg-success' : 'bg-fg-muted'}`}
            />
            {writable ? t('htaccess.writable') : t('htaccess.readOnly')}
          </span>
        </div>
      </div>

      <Confirm
        open={showReset}
        title={t('htaccess.resetConfirmTitle')}
        message={t('htaccess.resetConfirmMessage')}
        confirmText={t('htaccess.reset')}
        variant="danger"
        loading={resetting}
        onConfirm={handleReset}
        onCancel={() => setShowReset(false)}
      />
    </div>
  )
}
