import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/Card'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/Tabs'
import { Badge } from '@/components/ui/Badge'
import { Eye, RotateCcw, Code, EyeOff, Save } from 'lucide-react'
import { useI18n } from '@/hooks/useI18n'

interface ErrorTemplate {
  error_code: string
  title: string
  content: string
  updated_at?: number
}

interface ErrorPageConfig {
  templates: Record<string, ErrorTemplate>
}

export default function CustomErrorPages() {
  const { t } = useI18n()
  const queryClient = useQueryClient()
  const [activeTab, setActiveTab] = useState<'403' | '404' | '500'>('403')
  const [editingTemplate, setEditingTemplate] = useState<{
    error_code: string
    title: string
    content: string
  } | null>(null)
  const [showPreview, setShowPreview] = useState(false)
  const [previewContent, setPreviewContent] = useState('')

  const { data: templates, isLoading } = useQuery<ErrorPageConfig>({
    queryKey: ['custom-error-pages'],
    queryFn: async () => {
      const response = await fetch('/api/custom-error-pages')
      if (!response.ok) throw new Error('Failed to load templates')
      return response.json()
    }
  })

  const updateTemplateMutation = useMutation({
    mutationFn: async (data: { error_code: string; title: string; content: string }) => {
      // 如果没有保存的模板，先初始化默认配置
      const currentTemplates = templates?.templates || {}
      
      // 如果该错误码还没有模板，先创建一个空的配置
      if (!currentTemplates[data.error_code]) {
        const initResponse = await fetch('/api/custom-error-pages', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            error_code: data.error_code,
            title: data.title,
            content: data.content
          })
        })
        if (!initResponse.ok) throw new Error('Failed to initialize template')
        return initResponse.json()
      }
      
      // 否则更新现有模板
      const response = await fetch('/api/custom-error-pages', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
      })
      if (!response.ok) throw new Error('Failed to update template')
      return response.json()
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['custom-error-pages'] })
      setEditingTemplate(null)
    }
  })

  const resetTemplateMutation = useMutation({
    mutationFn: async (error_code: string) => {
      const response = await fetch(`/api/custom-error-pages/reset-template`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ error_code })
    })
      if (!response.ok) throw new Error('Failed to reset template')
      return response.json()
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['custom-error-pages'] })
    }
  })

  const generateTemplate = (error_code: string) => {
    const defaultTemplates = {
      '403': {
        title: '403 Forbidden',
        content: `<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>403 Forbidden</title>
    <style>
        body { font-family: Arial, sans-serif; text-align: center; padding: 50px; background: #f5f5f5; }
        .error-container { background: white; padding: 40px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); max-width: 500px; margin: 0 auto; }
        .error-code { font-size: 72px; font-weight: bold; color: #e74c3c; margin-bottom: 20px; }
        .error-message { font-size: 24px; color: #333; margin-bottom: 20px; }
        .error-details { font-size: 16px; color: #666; margin-bottom: 30px; }
        .back-button { background: #3498db; color: white; padding: 12px 24px; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; }
        .back-button:hover { background: #2980b9; }
    </style>
</head>
<body>
    <div class="error-container">
        <div class="error-code">403</div>
        <div class="error-message">禁止访问</div>
        <div class="error-details">您没有权限访问此页面。</div>
        <button class="back-button" onclick="history.back()">返回上一页</button>
    </div>
</body>
</html>`
      },
      '404': {
        title: '404 Not Found',
        content: `<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>404 Not Found</title>
    <style>
        body { font-family: Arial, sans-serif; text-align: center; padding: 50px; background: #f5f5f5; }
        .error-container { background: white; padding: 40px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); max-width: 500px; margin: 0 auto; }
        .error-code { font-size: 72px; font-weight: bold; color: #e74c3c; margin-bottom: 20px; }
        .error-message { font-size: 24px; color: #333; margin-bottom: 20px; }
        .error-details { font-size: 16px; color: #666; margin-bottom: 30px; }
        .back-button { background: #3498db; color: white; padding: 12px 24px; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; }
        .back-button:hover { background: #2980b9; }
    </style>
</head>
<body>
    <div class="error-container">
        <div class="error-code">404</div>
        <div class="error-message">页面未找到</div>
        <div class="error-details">您访问的页面不存在或已被删除。</div>
        <button class="back-button" onclick="history.back()">返回上一页</button>
    </div>
</body>
</html>`
      },
      '500': {
        title: '500 Internal Server Error',
        content: `<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>500 Internal Server Error</title>
    <style>
        body { font-family: Arial, sans-serif; text-align: center; padding: 50px; background: #f5f5f5; }
        .error-container { background: white; padding: 40px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); max-width: 500px; margin: 0 auto; }
        .error-code { font-size: 72px; font-weight: bold; color: #e74c3c; margin-bottom: 20px; }
        .error-message { font-size: 24px; color: #333; margin-bottom: 20px; }
        .error-details { font-size: 16px; color: #666; margin-bottom: 30px; }
        .back-button { background: #3498db; color: white; padding: 12px 24px; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; }
        .back-button:hover { background: #2980b9; }
    </style>
</head>
<body>
    <div class="error-container">
        <div class="error-code">500</div>
        <div class="error-message">服务器内部错误</div>
        <div class="error-details">服务器遇到了一个意外错误，无法完成您的请求。</div>
        <button class="back-button" onclick="history.back()">返回上一页</button>
    </div>
</body>
</html>`
      }
    }
    return defaultTemplates[error_code as keyof typeof defaultTemplates]
  }

  const handleEditTemplate = (error_code: string) => {
    const template = templates?.templates[error_code]
    if (template) {
      setEditingTemplate({
        error_code,
        title: template.title,
        content: template.content
      })
    }
  }

  const handleSaveTemplate = () => {
    if (editingTemplate) {
      updateTemplateMutation.mutate({
        error_code: editingTemplate.error_code,
        title: editingTemplate.title,
        content: editingTemplate.content
      })
    }
  }

  const handleResetTemplate = (error_code: string) => {
    if (!activeTab || !confirm(t('customErrorPages.confirmReset'))) return
    resetTemplateMutation.mutate(error_code)
  }

  const handlePreview = (content: string) => {
    setPreviewContent(content)
    setShowPreview(true)
  }

  const handlePreviewClose = () => {
    setShowPreview(false)
  }

  if (isLoading) {
    return <div className="p-6">{t('common.loading')}</div>
  }

  return (
    <div className="p-6 space-y-6">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold">{t('customErrorPages.title')}</h1>
        <Badge variant="muted">
          {t('customErrorPages.beta')}
        </Badge>
      </div>

      <Tabs value={activeTab} onValueChange={(value) => setActiveTab(value as '403' | '404' | '500')} className="w-full">
        <TabsList className="grid w-full grid-cols-3">
          <TabsTrigger value="403" className="flex items-center gap-2">
            <Code className="w-4 h-4" />
            {t('customErrorPages.error403')}
          </TabsTrigger>
          <TabsTrigger value="404" className="flex items-center gap-2">
            <Code className="w-4 h-4" />
            {t('customErrorPages.error404')}
          </TabsTrigger>
          <TabsTrigger value="500" className="flex items-center gap-2">
            <Code className="w-4 h-4" />
            {t('customErrorPages.error500')}
          </TabsTrigger>
        </TabsList>

        <TabsContent value="403" className="space-y-4">
          <Card>
            <CardHeader>
              <div className="flex items-center justify-between">
                <CardTitle>{t('customErrorPages.editTemplate')} - 403</CardTitle>
                <div className="flex gap-2">
                  <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => handleEditTemplate('403')}
                  >
                    <Eye className="w-4 h-4 mr-2" />
                    {t('customErrorPages.edit')}
                  </Button>
                  <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => handleResetTemplate('403')}
                    disabled={resetTemplateMutation.isPending}
                  >
                    <RotateCcw className="w-4 h-4 mr-2" />
                    {t('customErrorPages.reset')}
                  </Button>
                </div>
              </div>
            </CardHeader>
            <CardContent>
              {templates?.templates['403'] ? (
                <div className="space-y-4">
                  <div className="flex items-center gap-2">
                    <span className="font-medium">403</span>
                    {(templates.templates as any)['403']?.updated_at && (
                      <Badge variant="accent" className="text-xs">
                        已自定义
                      </Badge>
                    )}
                  </div>
                  <div className="border rounded-lg overflow-hidden">
                    <div
                      className="w-full h-96 bg-white"
                      dangerouslySetInnerHTML={{ __html: (templates.templates as any)['403']?.content || '' }}
                    />
                    <div className="p-3 bg-muted text-sm text-muted-foreground">
                      最后更新: {new Date((templates.templates as any)['403']?.updated_at || 0).toLocaleString()}
                    </div>
                  </div>
                </div>
              ) : (
                <div className="border rounded-lg overflow-hidden">
                  <div
                    className="w-full h-96 bg-white"
                    dangerouslySetInnerHTML={{ __html: generateTemplate('403').content }}
                  />
                  <div className="p-3 bg-muted text-sm text-muted-foreground">
                    使用默认模板
                  </div>
                </div>
              )}
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="404" className="space-y-4">
          <Card>
            <CardHeader>
              <div className="flex items-center justify-between">
                <CardTitle>{t('customErrorPages.editTemplate')} - 404</CardTitle>
                <div className="flex gap-2">
                  <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => handleEditTemplate('404')}
                  >
                    <Eye className="w-4 h-4 mr-2" />
                    {t('customErrorPages.edit')}
                  </Button>
                  <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => handleResetTemplate('404')}
                    disabled={resetTemplateMutation.isPending}
                  >
                    <RotateCcw className="w-4 h-4 mr-2" />
                    {t('customErrorPages.reset')}
                  </Button>
                </div>
              </div>
            </CardHeader>
            <CardContent>
              {templates?.templates['404'] ? (
                <div className="space-y-4">
                  <div className="flex items-center gap-2">
                    <span className="font-medium">404</span>
                    {(templates.templates as any)['404']?.updated_at && (
                      <Badge variant="accent" className="text-xs">
                        已自定义
                      </Badge>
                    )}
                  </div>
                  <div className="border rounded-lg overflow-hidden">
                    <div
                      className="w-full h-96 bg-white"
                      dangerouslySetInnerHTML={{ __html: (templates.templates as any)['404']?.content || '' }}
                    />
                    <div className="p-3 bg-muted text-sm text-muted-foreground">
                      最后更新: {new Date((templates.templates as any)['404']?.updated_at || 0).toLocaleString()}
                    </div>
                  </div>
                </div>
              ) : (
                <div className="border rounded-lg overflow-hidden">
                  <div
                    className="w-full h-96 bg-white"
                    dangerouslySetInnerHTML={{ __html: generateTemplate('404').content }}
                  />
                  <div className="p-3 bg-muted text-sm text-muted-foreground">
                    使用默认模板
                  </div>
                </div>
              )}
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="500" className="space-y-4">
          <Card>
            <CardHeader>
              <div className="flex items-center justify-between">
                <CardTitle>{t('customErrorPages.editTemplate')} - 500</CardTitle>
                <div className="flex gap-2">
                  <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => handleEditTemplate('500')}
                  >
                    <Eye className="w-4 h-4 mr-2" />
                    {t('customErrorPages.edit')}
                  </Button>
                  <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => handleResetTemplate('500')}
                    disabled={resetTemplateMutation.isPending}
                  >
                    <RotateCcw className="w-4 h-4 mr-2" />
                    {t('customErrorPages.reset')}
                  </Button>
                </div>
              </div>
            </CardHeader>
            <CardContent>
              {templates?.templates['500'] ? (
                <div className="space-y-4">
                  <div className="flex items-center gap-2">
                    <span className="font-medium">500</span>
                    {(templates.templates as any)['500']?.updated_at && (
                      <Badge variant="accent" className="text-xs">
                        已自定义
                      </Badge>
                    )}
                  </div>
                  <div className="border rounded-lg overflow-hidden">
                    <div
                      className="w-full h-96 bg-white"
                      dangerouslySetInnerHTML={{ __html: (templates.templates as any)['500']?.content || '' }}
                    />
                    <div className="p-3 bg-muted text-sm text-muted-foreground">
                      最后更新: {new Date((templates.templates as any)['500']?.updated_at || 0).toLocaleString()}
                    </div>
                  </div>
                </div>
              ) : (
                <div className="border rounded-lg overflow-hidden">
                  <div
                    className="w-full h-96 bg-white"
                    dangerouslySetInnerHTML={{ __html: generateTemplate('500').content }}
                  />
                  <div className="p-3 bg-muted text-sm text-muted-foreground">
                    使用默认模板
                  </div>
                </div>
              )}
            </CardContent>
          </Card>
        </TabsContent>
      </Tabs>

      {editingTemplate && (
        <Card>
          <CardHeader>
            <div className="flex items-center justify-between">
              <CardTitle>{t('customErrorPages.editTemplate')} - {editingTemplate.error_code}</CardTitle>
              <div className="flex gap-2">
                <Button onClick={handleSaveTemplate} disabled={updateTemplateMutation.isPending}>
                  <Save className="w-4 h-4 mr-2" />
                  {t('common.save')}
                </Button>
                <Button variant="ghost" onClick={() => setEditingTemplate(null)}>
                  {t('common.cancel')}
                </Button>
              </div>
            </div>
          </CardHeader>
          <CardContent className="space-y-4">
            <div>
              <label className="text-sm font-medium">{t('customErrorPages.title')}</label>
              <Input
                value={editingTemplate.title}
                onChange={(e) => setEditingTemplate({
                  ...editingTemplate,
                  title: e.target.value
                })}
                placeholder={t('customErrorPages.titlePlaceholder')}
              />
            </div>
            <div>
              <label className="text-sm font-medium">{t('customErrorPages.content')}</label>
              <textarea
                value={editingTemplate.content}
                onChange={(e) => setEditingTemplate({
                  ...editingTemplate,
                  content: e.target.value
                })}
                className="w-full h-96 p-3 border rounded-lg font-mono text-sm resize-none"
                placeholder={t('customErrorPages.contentPlaceholder')}
              />
              <p className="text-xs text-muted-foreground mt-1">
                {t('customErrorPages.contentHelp')}
              </p>
            </div>
            <div className="flex gap-2">
              <Button
                variant="ghost"
                onClick={() => handlePreview(editingTemplate.content)}
              >
                <Eye className="w-4 h-4 mr-2" />
                {t('customErrorPages.preview')}
              </Button>
            </div>
          </CardContent>
        </Card>
      )}

      {showPreview && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
          <div className="bg-white rounded-lg w-full max-w-4xl h-full max-h-[90vh] flex flex-col">
            <div className="p-4 border-b flex items-center justify-between">
              <h3 className="font-medium">{t('customErrorPages.preview')} - {activeTab}</h3>
              <div className="flex gap-2">
                <Button variant="ghost" onClick={handlePreviewClose}>
                  <EyeOff className="w-4 h-4 mr-2" />
                  {t('customErrorPages.closePreview')}
                </Button>
              </div>
            </div>
            <div className="flex-1 p-4">
              <iframe
                srcDoc={previewContent}
                className="w-full h-full border rounded-lg"
                title="Preview"
              />
            </div>
          </div>
        </div>
      )}
    </div>
  )
}