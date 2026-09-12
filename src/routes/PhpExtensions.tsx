import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Puzzle, Search, Star } from 'lucide-react'
import { Card, CardBody, CardHeader } from '@/components/ui/Card'
import { Badge } from '@/components/ui/Badge'
import { Input } from '@/components/ui/Input'
import { EmptyState } from '@/components/ui/EmptyState'
import { SkeletonTable } from '@/components/ui/Skeleton'
import { toast } from '@/components/ui/Toast'
import { phpExtensionsApi, type PhpExtension } from '@/api/phpExtensions'
import { useI18n } from '@/hooks/useI18n'
import { resolveErrorText } from '@/lib/errorMessages'

export default function PhpExtensions() {
  const { t } = useI18n()
  const queryClient = useQueryClient()
  const [q, setQ] = useState('')
  const [favoritesOnly, setFavoritesOnly] = useState(false)

  const { data, isLoading } = useQuery({
    queryKey: ['php-extensions'],
    queryFn: () => phpExtensionsApi.list(),
  })

  const favMutation = useMutation({
    mutationFn: ({ name, favorite }: { name: string; favorite: boolean }) =>
      phpExtensionsApi.favorite(name, favorite),
    onSuccess: (res) => {
      toast({
        type: 'success',
        title: res.favorite ? t('phpExtensions.addedFavorite') : t('phpExtensions.removedFavorite'),
      })
      queryClient.invalidateQueries({ queryKey: ['php-extensions'] })
    },
    onError: (err: Error) => {
      toast({ type: 'error', title: t('common.failure'), description: resolveErrorText(err) })
    },
  })

  const rows: PhpExtension[] = useMemo(() => {
    const all = data?.extensions || []
    const needle = q.trim().toLowerCase()
    return all.filter((e) => {
      if (favoritesOnly && !e.favorite) return false
      if (needle && !e.name.toLowerCase().includes(needle)) return false
      return true
    })
  }, [data, q, favoritesOnly])

  return (
    <div className="p-4 md:p-6 space-y-5 max-w-4xl mx-auto page-enter">
      <div className="stagger-1">
        <h1 className="text-xl font-semibold text-fg flex items-center gap-2">
          <Puzzle size={20} className="text-accent" />
          {t('phpExtensions.title')}
        </h1>
        <p className="text-sm text-fg-muted mt-0.5">
          {t('phpExtensions.subtitle', { total: data?.count ?? 0, zend: data?.zend_count ?? 0 })}
        </p>
      </div>

      <Card className="stagger-2">
        <CardHeader className="flex flex-wrap items-center gap-3">
          <div className="flex-1 min-w-[200px]">
            <Input
              value={q}
              icon={<Search size={16} />}
              onChange={(e) => setQ(e.target.value)}
              placeholder={t('phpExtensions.searchPlaceholder')}
            />
          </div>
          <label className="flex items-center gap-2 text-sm text-fg-muted">
            <input
              type="checkbox"
              checked={favoritesOnly}
              onChange={(e) => setFavoritesOnly(e.target.checked)}
            />
            {t('phpExtensions.favoritesOnly')}
          </label>
          <div className="text-xs text-fg-subtle">
            {t('phpExtensions.favorites')}: {(data?.favorites || []).length}
          </div>
        </CardHeader>
        <CardBody>
          {isLoading ? (
            <SkeletonTable rows={6} columns={4} />
          ) : rows.length === 0 ? (
            <EmptyState title={t('phpExtensions.noResults')} description={t('phpExtensions.noResultsDesc')} />
          ) : (
            <div className="overflow-x-auto -mx-2">
              <table className="w-full text-sm">
                <thead>
                  <tr className="text-left text-fg-subtle border-b border-border">
                    <th className="px-3 py-2 font-medium w-10" />
                    <th className="px-3 py-2 font-medium">{t('phpExtensions.name')}</th>
                    <th className="px-3 py-2 font-medium">{t('phpExtensions.version')}</th>
                    <th className="px-3 py-2 font-medium">{t('phpExtensions.kind')}</th>
                  </tr>
                </thead>
                <tbody>
                  {rows.map((e) => (
                    <tr key={e.name} className="border-b border-border/40 hover:bg-bg-sunken/40">
                      <td className="px-3 py-2">
                        <button
                          type="button"
                          aria-label={t('phpExtensions.favorite')}
                          className={`p-1 rounded transition-colors ${e.favorite ? 'text-warning' : 'text-fg-subtle hover:text-fg'}`}
                          onClick={() => favMutation.mutate({ name: e.name, favorite: !e.favorite })}
                        >
                          <Star size={15} fill={e.favorite ? 'currentColor' : 'none'} />
                        </button>
                      </td>
                      <td className="px-3 py-2 font-mono text-fg">{e.name}</td>
                      <td className="px-3 py-2 text-fg-muted font-mono">{e.version || '—'}</td>
                      <td className="px-3 py-2">
                        {e.zend ? (
                          <Badge variant="accent">{t('phpExtensions.zend')}</Badge>
                        ) : (
                          <Badge variant="muted">{t('phpExtensions.standard')}</Badge>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </CardBody>
      </Card>
    </div>
  )
}
