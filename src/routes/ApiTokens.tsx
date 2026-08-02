import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { KeyRound, Plus, Copy, Check, Trash2 } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Card, CardBody } from '@/components/ui/Card'
import { Badge } from '@/components/ui/Badge'
import { Modal, Confirm } from '@/components/ui/Modal'
import { EmptyState } from '@/components/ui/EmptyState'
import { SkeletonTable } from '@/components/ui/Skeleton'
import { toast } from '@/components/ui/Toast'
import { apiTokensApi } from '@/api/apiTokens'
import { useFormat } from '@/lib/format'
import { useI18n } from '@/hooks/useI18n'
import type { ApiToken, ApiTokenScope } from '@shared/types'

const ALL_SCOPES: ApiTokenScope[] = ['backup:run', 'status:read', 'files:read']

const SCOPE_META: Record<ApiTokenScope, { labelKey: string; desc: string }> = {
  'backup:run': { labelKey: 'apiTokens.scopeBackupRun', desc: 'POST /api/backup/run' },
  'status:read': { labelKey: 'apiTokens.scopeStatusRead', desc: 'GET /api/status' },
  'files:read': { labelKey: 'apiTokens.scopeFilesRead', desc: 'GET /api/files' },
}

export default function ApiTokens() {
  const { t } = useI18n()
  const { formatDate } = useFormat()
  const queryClient = useQueryClient()

  const [showCreate, setShowCreate] = useState(false)
  const [creating, setCreating] = useState(false)
  const [createFailed, setCreateFailed] = useState('')
  const [name, setName] = useState('')
  const [scopes, setScopes] = useState<ApiTokenScope[]>(['status:read'])
  const [createdToken, setCreatedToken] = useState<{ name: string; plainToken: string } | null>(null)
  const [copied, setCopied] = useState(false)
  const [revokeTarget, setRevokeTarget] = useState<ApiToken | null>(null)
  const [revoking, setRevoking] = useState(false)

  const { data: tokens, isLoading } = useQuery({
    queryKey: ['api-tokens'],
    queryFn: () => apiTokensApi.list(),
  })

  const toggleScope = (scope: ApiTokenScope) => {
    setScopes((prev) =>
      prev.includes(scope) ? prev.filter((s) => s !== scope) : [...prev, scope]
    )
  }

  const resetCreate = () => {
    setName('')
    setScopes(['status:read'])
    setCreateFailed('')
  }

  const closeCreate = () => {
    setShowCreate(false)
    resetCreate()
  }

  const handleCreate = async () => {
    if (!name.trim()) {
      setCreateFailed(t('apiTokens.nameRequired'))
      return
    }
    setCreating(true)
    setCreateFailed('')
    try {
      const res = await apiTokensApi.create({ name: name.trim(), scopes })
      setCreatedToken({ name: res.token.name, plainToken: res.plain_token })
      setShowCreate(false)
      resetCreate()
      queryClient.invalidateQueries({ queryKey: ['api-tokens'] })
    } catch (err) {
      setCreateFailed(err instanceof Error ? err.message : t('apiTokens.createFailed'))
    } finally {
      setCreating(false)
    }
  }

  const copyToken = async () => {
    if (!createdToken) return
    try {
      await navigator.clipboard.writeText(createdToken.plainToken)
      setCopied(true)
      setTimeout(() => setCopied(false), 2000)
    } catch {
      toast({ type: 'error', title: t('apiTokens.copyFailed') })
    }
  }

  const handleRevoke = async () => {
    if (!revokeTarget) return
    setRevoking(true)
    try {
      await apiTokensApi.revoke(revokeTarget.id)
      toast({ type: 'success', title: t('apiTokens.revoked') })
      setRevokeTarget(null)
      queryClient.invalidateQueries({ queryKey: ['api-tokens'] })
    } catch (err) {
      toast({
        type: 'error',
        title: t('apiTokens.revokeFailed'),
        description: err instanceof Error ? err.message : undefined,
      })
    } finally {
      setRevoking(false)
    }
  }

  const list = tokens ?? []

  return (
    <div className="p-4 md:p-6 space-y-5">
      <div className="flex items-start justify-between gap-4">
        <div>
          <h1 className="text-xl font-semibold text-fg flex items-center gap-2">
            <KeyRound size={22} className="text-accent" />
            {t('apiTokens.pageTitle')}
          </h1>
          <p className="text-sm text-fg-muted mt-0.5">{t('apiTokens.description')}</p>
        </div>
        <Button size="sm" onClick={() => setShowCreate(true)}>
          <Plus size={15} />
          {t('apiTokens.create')}
        </Button>
      </div>

      <Card className="card-hover">
        <CardBody className="p-0">
          {isLoading ? (
            <SkeletonTable rows={4} />
          ) : list.length === 0 ? (
            <EmptyState
              icon={
                <div className="w-16 h-16 rounded-full bg-bg-sunken flex items-center justify-center text-fg-subtle mx-auto">
                  <KeyRound size={28} />
                </div>
              }
              title={t('apiTokens.noTokens')}
            />
          ) : (
            <ul className="divide-y divide-border">
              {list.map((token) => (
                <li key={token.id} className="flex items-center gap-3 px-4 md:px-6 py-3.5">
                  <div className="w-9 h-9 rounded-md bg-accent/10 text-accent flex items-center justify-center shrink-0">
                    <KeyRound size={17} />
                  </div>

                  <div className="flex-1 min-w-0">
                    <div className="text-sm font-medium text-fg truncate">{token.name}</div>
                    <div className="flex flex-wrap items-center gap-1.5 mt-1">
                      {token.scopes.map((s) => (
                        <Badge key={s} variant="accent">
                          {SCOPE_META[s]?.labelKey ? t(SCOPE_META[s].labelKey) : s}
                        </Badge>
                      ))}
                    </div>
                  </div>

                  <div className="hidden sm:flex flex-col items-end gap-0.5 text-xs text-fg-subtle shrink-0">
                    <span>
                      {t('apiTokens.createdAt')}: {formatDate(token.created_at)}
                    </span>
                    <span>
                      {t('apiTokens.lastUsedAt')}:{' '}
                      {token.last_used_at ? formatDate(token.last_used_at) : '—'}
                    </span>
                  </div>

                  <Button
                    variant="ghost"
                    size="sm"
                    className="text-danger hover:text-danger shrink-0"
                    onClick={() => setRevokeTarget(token)}
                  >
                    <Trash2 size={14} />
                    {t('apiTokens.revoke')}
                  </Button>
                </li>
              ))}
            </ul>
          )}
        </CardBody>
      </Card>

      {/* 创建 Token */}
      <Modal
        open={showCreate}
        onClose={closeCreate}
        title={t('apiTokens.create')}
        size="sm"
        footer={
          <>
            <Button variant="secondary" onClick={closeCreate}>
              {t('common.cancel')}
            </Button>
            <Button onClick={handleCreate} loading={creating}>
              {t('apiTokens.create')}
            </Button>
          </>
        }
      >
        <div className="space-y-4">
          <div>
            <label className="block text-xs font-medium text-fg-muted mb-1.5">
              {t('apiTokens.name')}
            </label>
            <Input
              value={name}
              onChange={(e) => setName(e.target.value)}
              placeholder={t('apiTokens.name')}
              maxLength={64}
              autoFocus
            />
          </div>

          <div>
            <label className="block text-xs font-medium text-fg-muted mb-1.5">
              {t('apiTokens.scopes')}
            </label>
            <div className="space-y-2">
              {ALL_SCOPES.map((scope) => (
                <label
                  key={scope}
                  className="flex items-start gap-2.5 p-2.5 rounded-lg border border-border cursor-pointer hover:bg-fg/5 transition-colors"
                >
                  <input
                    type="checkbox"
                    checked={scopes.includes(scope)}
                    onChange={() => toggleScope(scope)}
                    className="mt-0.5 h-4 w-4 rounded border-border accent-accent"
                  />
                  <span className="min-w-0">
                    <span className="block text-sm text-fg">
                      {t(SCOPE_META[scope].labelKey)}
                    </span>
                    <span className="block text-xs text-fg-subtle mt-0.5">
                      {SCOPE_META[scope].desc}
                    </span>
                  </span>
                </label>
              ))}
            </div>
          </div>

          {createFailed && <p className="text-xs text-danger">{createFailed}</p>}
        </div>
      </Modal>

      {/* 创建成功：一次性展示 plain_token */}
      <Modal
        open={createdToken !== null}
        onClose={() => setCreatedToken(null)}
        title={t('apiTokens.tokenCreated')}
        size="sm"
        footer={
          <Button onClick={() => setCreatedToken(null)}>
            {t('apiTokens.iHaveSaved')}
          </Button>
        }
      >
        <div className="space-y-4">
          <p className="text-sm text-warning bg-warning/10 border border-warning/20 rounded-lg px-3 py-2.5">
            {t('apiTokens.plainTokenWarning')}
          </p>
          <div className="flex items-center gap-2">
            <code className="flex-1 min-w-0 bg-bg-sunken border border-border rounded-lg px-3 py-2.5 text-xs text-fg break-all select-all">
              {createdToken?.plainToken}
            </code>
            <Button variant="secondary" size="icon" onClick={copyToken} aria-label={t('apiTokens.copy')}>
              {copied ? <Check size={16} className="text-accent" /> : <Copy size={16} />}
            </Button>
          </div>
          {copied && <p className="text-xs text-accent">{t('apiTokens.copied')}</p>}
        </div>
      </Modal>

      {/* 撤销确认 */}
      <Confirm
        open={revokeTarget !== null}
        title={t('apiTokens.revoke')}
        message={t('apiTokens.revokeConfirm', { name: revokeTarget?.name ?? '' })}
        confirmText={t('apiTokens.revoke')}
        variant="danger"
        loading={revoking}
        onConfirm={handleRevoke}
        onCancel={() => setRevokeTarget(null)}
      />
    </div>
  )
}
