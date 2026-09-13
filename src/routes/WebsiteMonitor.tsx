import { useState, useEffect } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/Card'
import { Badge } from '@/components/ui/Badge'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/Tabs'
import { Plus, Trash2, Play, AlertTriangle, CheckCircle, XCircle, Settings, BarChart3 } from 'lucide-react'
import { useI18n } from '@/hooks/useI18n'

interface WebsiteConfig {
  websites: Array<{
    id: string
    name: string
    url: string
    enabled: boolean
    timeout: number
    notifications: boolean
  }>
  check_interval: number
}

interface MonitorHistory {
  website_id: string
  url: string
  timestamp: number
  status: string
  response_time: number
  status_code: number
  error: string | null
  content_size: number
}

interface Notification {
  id: string
  website_id: string
  website_name: string
  url: string
  status: string
  status_code: number
  response_time: number
  error: string | null
  timestamp: number
  sent: boolean
  acknowledged?: boolean
  acknowledged_at?: number
}

export default function WebsiteMonitor() {
  const { t } = useI18n()
  const [config, setConfig] = useState<WebsiteConfig>({ websites: [], check_interval: 60 })
  const [editingWebsite, setEditingWebsite] = useState<{
    id: string
    name: string
    url: string
    enabled: boolean
    timeout: number
    notifications: boolean
  } | null>(null)
  const [activeTab, setActiveTab] = useState<'websites' | 'history' | 'notifications'>('websites')
  
  const queryClient = useQueryClient()

  const { data: monitorConfig } = useQuery({
    queryKey: ['website-monitor', 'config'],
    queryFn: async () => {
      const response = await fetch('/api/website-monitor/config')
      if (!response.ok) throw new Error('Failed to fetch config')
      return response.json()
    }
  })

  const { data: history, isLoading: historyLoading } = useQuery({
    queryKey: ['website-monitor', 'history'],
    queryFn: async () => {
      const response = await fetch('/api/website-monitor/history')
      if (!response.ok) throw new Error('Failed to fetch history')
      return response.json()
    }
  })

  const { data: notifications, isLoading: notificationsLoading } = useQuery({
    queryKey: ['website-monitor', 'notifications'],
    queryFn: async () => {
      const response = await fetch('/api/website-monitor/notifications')
      if (!response.ok) throw new Error('Failed to fetch notifications')
      return response.json()
    }
  })

  const updateConfigMutation = useMutation({
    mutationFn: async (newConfig: WebsiteConfig) => {
      const response = await fetch('/api/website-monitor/config', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(newConfig)
      })
      if (!response.ok) throw new Error('Failed to update config')
      return response.json()
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['website-monitor', 'config'] })
    }
  })

  const runCheckMutation = useMutation({
    mutationFn: async () => {
      const response = await fetch('/api/website-monitor/run-check', { method: 'POST' })
      if (!response.ok) throw new Error('Failed to run check')
      return response.json()
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['website-monitor', 'history'] })
      queryClient.invalidateQueries({ queryKey: ['website-monitor', 'notifications'] })
    }
  })

  const clearNotificationsMutation = useMutation({
    mutationFn: async () => {
      const response = await fetch('/api/website-monitor/clear-notifications', { method: 'POST' })
      if (!response.ok) throw new Error('Failed to clear notifications')
      return response.json()
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['website-monitor', 'notifications'] })
    }
  })

  const acknowledgeNotificationMutation = useMutation({
    mutationFn: async (notificationId: string) => {
      const response = await fetch(`/api/website-monitor/notifications/${notificationId}`, {
        method: 'PATCH'
      })
      if (!response.ok) throw new Error('Failed to acknowledge notification')
      return response.json()
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['website-monitor', 'notifications'] })
    }
  })

  useEffect(() => {
    if (monitorConfig) {
      setConfig(monitorConfig)
    }
  }, [monitorConfig])

  const handleSaveWebsite = () => {
    if (!editingWebsite) return

    const newConfig = { ...config }
    if (editingWebsite.id) {
      const index = newConfig.websites.findIndex(w => w.id === editingWebsite.id)
      if (index !== -1) {
        newConfig.websites[index] = editingWebsite
      }
    } else {
      newConfig.websites.push({
        ...editingWebsite,
        id: ''
      })
    }

    updateConfigMutation.mutate(newConfig)
    setEditingWebsite(null)
  }

  const handleDeleteWebsite = (id: string) => {
    if (!confirm(t('websiteMonitor.confirmDelete'))) return

    const newConfig = { ...config }
    newConfig.websites = newConfig.websites.filter(w => w.id !== id)
    updateConfigMutation.mutate(newConfig)
  }

  const handleRunCheck = () => {
    runCheckMutation.mutate()
  }

  const handleClearNotifications = () => {
    if (confirm(t('websiteMonitor.confirmClearNotifications'))) {
      clearNotificationsMutation.mutate()
    }
  }

  const handleAcknowledgeNotification = (id: string) => {
    acknowledgeNotificationMutation.mutate(id)
  }

  const getStatusIcon = (status: string) => {
    switch (status) {
      case 'up':
        return <CheckCircle className="w-4 h-4 text-green-500" />
      case 'down':
        return <XCircle className="w-4 h-4 text-red-500" />
      case 'error':
        return <AlertTriangle className="w-4 h-4 text-yellow-500" />
      default:
        return <AlertTriangle className="w-4 h-4 text-gray-500" />
    }
  }

  const getStatusBadge = (status: string) => {
    switch (status) {
      case 'up':
        return <Badge variant="success">正常</Badge>
      case 'down':
        return <Badge variant="danger">异常</Badge>
      case 'error':
        return <Badge variant="warning">错误</Badge>
      default:
        return <Badge variant="muted">未知</Badge>
    }
  }

  const formatTime = (timestamp: number) => {
    return new Date(timestamp * 1000).toLocaleString()
  }

  const getWebsiteStatus = (websiteId: string) => {
    if (!history) return null
    
    const websiteHistory = history
      .filter((h: MonitorHistory) => h.website_id === websiteId)
      .slice(-5)
    
    if (websiteHistory.length === 0) return null
    
    const latest = websiteHistory[websiteHistory.length - 1]
    return {
      ...latest,
      trend: websiteHistory.length > 1 ? 
        (latest.status === 'up' ? 'up' : 'down') : 'unknown'
    }
  }

  return (
    <div className="p-6 space-y-6">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold">{t('websiteMonitor.title')}</h1>
        <div className="flex gap-2">
          <Button
            onClick={handleRunCheck}
            disabled={runCheckMutation.isPending}
          >
            <Play className="w-4 h-4 mr-2" />
            {t('websiteMonitor.runCheck')}
          </Button>
          <Button
            variant="ghost"
            onClick={handleClearNotifications}
            disabled={clearNotificationsMutation.isPending}
          >
            <Trash2 className="w-4 h-4 mr-2" />
            {t('websiteMonitor.clearNotifications')}
          </Button>
        </div>
      </div>

      <Tabs value={activeTab} onValueChange={(value) => setActiveTab(value as 'websites' | 'history' | 'notifications')} className="w-full">
        <TabsList className="grid w-full grid-cols-3">
          <TabsTrigger value="websites" className="flex items-center gap-2">
            <Settings className="w-4 h-4" />
            {t('websiteMonitor.websites')}
          </TabsTrigger>
          <TabsTrigger value="history" className="flex items-center gap-2">
            <BarChart3 className="w-4 h-4" />
            {t('websiteMonitor.history')}
          </TabsTrigger>
          <TabsTrigger value="notifications" className="flex items-center gap-2">
            <AlertTriangle className="w-4 h-4" />
            {t('websiteMonitor.notifications')}
          </TabsTrigger>
        </TabsList>

        <TabsContent value="websites" className="space-y-4">
          <Card>
            <CardHeader>
              <CardTitle>{t('websiteMonitor.websiteList')}</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="space-y-4">
                {config.websites.map((website) => {
                  const status = getWebsiteStatus(website.url)
                  return (
                    <div
                      key={website.id}
                      className="p-4 border rounded-lg"
                    >
                      <div className="flex items-center justify-between">
                        <div className="flex items-center gap-3">
                          {getStatusIcon(status?.status || 'unknown')}
                          <div>
                            <h3 className="font-medium">{website.name}</h3>
                            <p className="text-sm text-muted-foreground">{website.url}</p>
                          </div>
                        </div>
                        <div className="flex items-center gap-2">
                          {status && (
                            <div className="text-sm text-muted-foreground">
                              响应时间: {status.response_time}ms
                            </div>
                          )}
                          <Badge variant={website.enabled ? 'success' : 'muted'}>
                            {website.enabled ? '启用' : '禁用'}
                          </Badge>
                        </div>
                      </div>
                      <div className="flex gap-2 mt-3">
                        <Button
                          variant="ghost"
                          size="sm"
                          onClick={() => setEditingWebsite(website)}
                        >
                          编辑
                        </Button>
                        <Button
                          variant="ghost"
                          size="sm"
                          onClick={() => handleDeleteWebsite(website.id)}
                        >
                          <Trash2 className="w-4 h-4" />
                        </Button>
                      </div>
                    </div>
                  )
                })}
                {config.websites.length === 0 && (
                  <div className="text-center py-8 text-muted-foreground">
                    {t('websiteMonitor.noWebsites')}
                  </div>
                )}
              </div>
              <Button
                className="mt-4"
                onClick={() => setEditingWebsite({
                  id: Date.now().toString(),
                  name: '',
                  url: '',
                  enabled: true,
                  timeout: 10,
                  notifications: true
                })}
              >
                <Plus className="w-4 h-4 mr-2" />
                {t('websiteMonitor.addWebsite')}
              </Button>
            </CardContent>
          </Card>

          {editingWebsite && (
            <Card>
              <CardHeader>
                <CardTitle>{editingWebsite?.id ? t('websiteMonitor.editWebsite') : t('websiteMonitor.addWebsite')}</CardTitle>
              </CardHeader>
              <CardContent>
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <div>
                    <label className="text-sm font-medium">{t('websiteMonitor.websiteName')}</label>
                    <Input
                      value={editingWebsite.name}
                      onChange={(e) => setEditingWebsite({...editingWebsite, name: e.target.value})}
                      placeholder={t('websiteMonitor.websiteNamePlaceholder')}
                    />
                  </div>
                  <div>
                    <label className="text-sm font-medium">{t('websiteMonitor.websiteUrl')}</label>
                    <Input
                      value={editingWebsite.url}
                      onChange={(e) => setEditingWebsite({...editingWebsite, url: e.target.value})}
                      placeholder="https://example.com"
                    />
                  </div>
                  <div>
                    <label className="text-sm font-medium">{t('websiteMonitor.timeout')}</label>
                    <Input
                      type="number"
                      value={editingWebsite.timeout}
                      onChange={(e) => setEditingWebsite({...editingWebsite, timeout: parseInt(e.target.value) || 10})}
                      min="1"
                      max="60"
                    />
                  </div>
                  <div className="flex items-center gap-4">
                    <label className="flex items-center gap-2">
                      <input
                        type="checkbox"
                        checked={editingWebsite.enabled}
                        onChange={(e) => setEditingWebsite({...editingWebsite, enabled: e.target.checked})}
                      />
                      {t('websiteMonitor.enabled')}
                    </label>
                    <label className="flex items-center gap-2">
                      <input
                        type="checkbox"
                        checked={editingWebsite.notifications}
                        onChange={(e) => setEditingWebsite({...editingWebsite, notifications: e.target.checked})}
                      />
                      {t('websiteMonitor.notifications')}
                    </label>
                  </div>
                </div>
                <div className="flex gap-2 mt-4">
                  <Button onClick={handleSaveWebsite}>
                    {t('common.save')}
                  </Button>
                  <Button variant="ghost" onClick={() => setEditingWebsite(null)}>
                    {t('common.cancel')}
                  </Button>
                </div>
              </CardContent>
            </Card>
          )}
        </TabsContent>

        <TabsContent value="history" className="space-y-4">
          <Card>
            <CardHeader>
              <CardTitle>{t('websiteMonitor.monitorHistory')}</CardTitle>
            </CardHeader>
            <CardContent>
              {historyLoading ? (
                <div className="text-center py-8 text-muted-foreground">
                  {t('common.loading')}
                </div>
              ) : (
                <div className="space-y-2 max-h-96 overflow-y-auto">
                  {Array.isArray(history) && history.length > 0 ? (
                    history.slice().reverse().map((item: MonitorHistory, index: number) => (
                      <div key={index} className="p-3 border rounded-lg">
                        <div className="flex items-center justify-between mb-2">
                          <div className="flex items-center gap-2">
                            {getStatusIcon(item.status)}
                            <span className="font-medium">{item.url}</span>
                            {getStatusBadge(item.status)}
                          </div>
                          <span className="text-xs text-muted-foreground">
                            {formatTime(item.timestamp)}
                          </span>
                        </div>
                        <div className="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                          <div>
                            <span className="text-muted-foreground">状态码:</span>
                            <span className="ml-2 font-mono">{item.status_code}</span>
                          </div>
                          <div>
                            <span className="text-muted-foreground">响应时间:</span>
                            <span className="ml-2">{item.response_time}ms</span>
                          </div>
                          <div>
                            <span className="text-muted-foreground">大小:</span>
                            <span className="ml-2">{item.content_size} bytes</span>
                          </div>
                          {item.error && (
                            <div className="text-red-600">
                              <span className="text-muted-foreground">错误:</span>
                              <span className="ml-2">{item.error}</span>
                            </div>
                          )}
                        </div>
                      </div>
                    ))
                  ) : (
                    <div className="text-center py-8 text-muted-foreground">
                      {t('websiteMonitor.noHistory')}
                    </div>
                  )}
                </div>
              )}
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="notifications" className="space-y-4">
          <Card>
            <CardHeader>
              <CardTitle>{t('websiteMonitor.notifications')}</CardTitle>
            </CardHeader>
            <CardContent>
              {notificationsLoading ? (
                <div className="text-center py-8 text-muted-foreground">
                  {t('common.loading')}
                </div>
              ) : (
                <div className="space-y-2 max-h-96 overflow-y-auto">
                  {Array.isArray(notifications) && notifications.length > 0 ? (
                    notifications.slice().reverse().map((notification: Notification) => (
                      <div
                        key={notification.id}
                        className={`p-3 border rounded-lg ${
                          notification.acknowledged ? 'opacity-50' : ''
                        }`}
                      >
                        <div className="flex items-center justify-between mb-2">
                          <div className="flex items-center gap-2">
                            {getStatusIcon(notification.status)}
                            <div>
                              <span className="font-medium">{notification.website_name}</span>
                              <span className="text-sm text-muted-foreground ml-2">
                                ({notification.url})
                              </span>
                            </div>
                            {getStatusBadge(notification.status)}
                          </div>
                          <div className="flex items-center gap-2">
                            <span className="text-xs text-muted-foreground">
                              {formatTime(notification.timestamp)}
                            </span>
                            {!notification.acknowledged && (
                              <Button
                                variant="ghost"
                                size="sm"
                                onClick={() => handleAcknowledgeNotification(notification.id)}
                              >
                                确认
                              </Button>
                            )}
                          </div>
                        </div>
                        <div className="text-sm text-muted-foreground">
                          <div>状态码: {notification.status_code}</div>
                          <div>响应时间: {notification.response_time}ms</div>
                          {notification.error && (
                            <div className="text-red-600 mt-1">错误: {notification.error}</div>
                          )}
                        </div>
                      </div>
                    ))
                  ) : (
                    <div className="text-center py-8 text-muted-foreground">
                      {t('websiteMonitor.noNotifications')}
                    </div>
                  )}
                </div>
              )}
            </CardContent>
          </Card>
        </TabsContent>
      </Tabs>
    </div>
  )
}