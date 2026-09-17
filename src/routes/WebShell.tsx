import { useState, useEffect, useRef, useCallback } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Terminal as XTermTerminal } from '@xterm/xterm'
import { FitAddon } from '@xterm/addon-fit'
import { WebLinksAddon } from '@xterm/addon-web-links'


import '@xterm/xterm/css/xterm.css'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/Card'
import { Badge } from '@/components/ui/Badge'
import { Play, RotateCcw, History } from 'lucide-react'
import { useI18n } from '@/hooks/useI18n'
import { resolveErrorText } from '@/lib/errorMessages'

interface CommandHistory {
  id: string
  command: string
  output: string
  success: boolean
  timestamp: number
}

export default function WebShell() {
  const { t } = useI18n()
  const [command, setCommand] = useState('')
  const [terminal, setTerminal] = useState<XTermTerminal | null>(null)

  const terminalRef = useRef<HTMLDivElement>(null)
  const inputRef = useRef<HTMLInputElement>(null)
  
  const queryClient = useQueryClient()

  const { data: history, isLoading: historyLoading, isError: historyError, refetch: refetchHistory } = useQuery({
    queryKey: ['webshell', 'history'],
    queryFn: async () => {
      const response = await fetch('/gojs/api/webshell/history')
      if (!response.ok) throw new Error('Failed to fetch history')
      return response.json()
    }
  })

  const executeMutation = useMutation({
    mutationFn: async (cmd: string) => {
      const response = await fetch('/gojs/api/webshell/execute', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ command: cmd })
      })
      if (!response.ok) {
        const errData = await response.json().catch(() => null)
        const errBody = errData?.error && typeof errData.error === 'object' ? errData.error : errData
        const err = new Error(errBody?.message || t('webshell.executeFailed'))
        if (errBody?.code) Object.assign(err, { code: errBody.code })
        throw err
      }
      return response.json()
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['webshell', 'history'] })
    }
  })

  const clearHistoryMutation = useMutation({
    mutationFn: async () => {
      const response = await fetch('/gojs/api/webshell/clear-history', { method: 'POST' })
      if (!response.ok) throw new Error('Failed to clear history')
      return response.json()
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['webshell', 'history'] })
    }
  })

  const autocompleteMutation = useMutation({
    mutationFn: async (input: string) => {
      const response = await fetch(`/gojs/api/webshell/autocomplete?input=${encodeURIComponent(input)}`)
      if (!response.ok) throw new Error('Failed to get autocomplete')
      return response.json()
    }
  })

  useEffect(() => {
    if (!terminalRef.current) return

    const newTerminal = new XTermTerminal({
      cursorBlink: true,
      theme: {
        background: '#1e1e1e',
        foreground: '#ffffff',
        cursor: '#ffffff',

        black: '#000000',
        red: '#cd3131',
        green: '#0dbc79',
        yellow: '#e5e510',
        blue: '#2472c8',
        magenta: '#bc3fbc',
        cyan: '#11a8cd',
        white: '#e5e5e5',
        brightBlack: '#666666',
        brightRed: '#f14c4c',
        brightGreen: '#23d18b',
        brightYellow: '#f5f543',
        brightBlue: '#3b8eea',
        brightMagenta: '#d670d6',
        brightCyan: '#29b8db',
        brightWhite: '#e5e5e5',
      }
    })

    const newFitAddon = new FitAddon()
    const newWebLinksAddon = new WebLinksAddon()
    
    newTerminal.loadAddon(newFitAddon)
    newTerminal.loadAddon(newWebLinksAddon)
    
    newTerminal.open(terminalRef.current)
    newFitAddon.fit()

    setTerminal(newTerminal)

    const handleResize = () => {
      newFitAddon?.fit()
    }

    window.addEventListener('resize', handleResize)
    
    return () => {
      newTerminal.dispose()
      window.removeEventListener('resize', handleResize)
    }
  }, [])

  useEffect(() => {
    if (terminal && history) {
      terminal.clear()
      history.forEach((item: CommandHistory) => {
        terminal.write(`\r\n$ ${item.command}\r\n`)
        terminal.write(`${item.output}\r\n`)
      })
    }
  }, [terminal, history])

  useEffect(() => {
    inputRef.current?.focus()
  }, [])

  const handleExecute = useCallback(() => {
    if (!command.trim() || !terminal || executeMutation.isPending) return

    terminal.write(`\r\n$ ${command}\r\n`)
    
    executeMutation.mutate(command, {
      onSuccess: (result) => {
        if (!result.success) {
          terminal.write(`${result.output}\r\n`)
        } else {
          terminal.write(`${result.output}\r\n`)
        }
        setCommand('')
        inputRef.current?.focus()
      },
      onError: (error) => {
        terminal.write(`\r\n${t('webshell.executeFailed')}: ${resolveErrorText(error)}\r\n`)
        setCommand('')
        inputRef.current?.focus()
      }
    })
  }, [command, terminal, executeMutation, t])

  const handleKeyPress = useCallback((e: React.KeyboardEvent) => {
    if (e.key === 'Enter' && !executeMutation.isPending) {
      handleExecute()
    }
  }, [handleExecute, executeMutation.isPending])

  const handleClearHistory = useCallback(() => {
    if (confirm(t('webshell.confirmClearHistory'))) {
      clearHistoryMutation.mutate()
    }
  }, [t, clearHistoryMutation])

  const handleAutocomplete = useCallback(() => {
    if (command.trim()) {
      autocompleteMutation.mutate(command.trim())
    }
  }, [command, autocompleteMutation])

  const formatTime = (timestamp: number) => {
    return new Date(timestamp * 1000).toLocaleString()
  }

  return (
    <div className="p-6 space-y-6">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold">{t('webshell.title')}</h1>
        <Button
          variant="ghost"
          onClick={handleClearHistory}
          disabled={clearHistoryMutation.isPending}
        >
          <RotateCcw className="w-4 h-4 mr-2" />
          {t('webshell.clearHistory')}
        </Button>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <Play className="w-5 h-5" />
              {t('webshell.terminal')}
            </CardTitle>
          </CardHeader>
          <CardContent>
            <div
              ref={terminalRef}
              className="w-full h-96 bg-black rounded-md overflow-hidden mb-4"
            />
            <div className="flex gap-2">
              <Input
                ref={inputRef}
                value={command}
                onChange={(e) => setCommand(e.target.value)}
                onKeyPress={handleKeyPress}
                placeholder={t('webshell.commandPlaceholder')}
                className="flex-1"
                disabled={executeMutation.isPending}
              />
              <Button
                onClick={handleExecute}
                disabled={executeMutation.isPending || !command.trim()}
              >
                <Play className="w-4 h-4" />
              </Button>
              <Button
                variant="ghost"
                onClick={handleAutocomplete}
                disabled={autocompleteMutation.isPending}
              >
                <History className="w-4 h-4" />
              </Button>
            </div>
            {executeMutation.isPending && (
              <div className="mt-2 text-sm text-muted-foreground">
                {t('webshell.executing')}
              </div>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <History className="w-5 h-5" />
              {t('webshell.commandHistory')}
            </CardTitle>
          </CardHeader>
          <CardContent>
            {historyLoading ? (
              <div className="text-center py-8 text-muted-foreground">
                {t('common.loading')}
              </div>
            ) : historyError ? (
              <div className="text-center py-8">
                <p className="text-sm text-muted-foreground mb-3">{t('webshell.historyLoadFailed')}</p>
                <Button variant="secondary" size="sm" onClick={() => refetchHistory()}>
                  <RotateCcw className="w-4 h-4 mr-2" />
                  {t('common.retry')}
                </Button>
              </div>
            ) : (
              <div className="space-y-2 max-h-96 overflow-y-auto">
                {Array.isArray(history) && history.length > 0 ? (
                  history.slice().reverse().map((item: CommandHistory) => (
                    <div
                      key={item.id}
                      className="p-3 rounded-lg border bg-card"
                    >
                      <div className="flex items-center justify-between mb-2">
                        <div className="flex items-center gap-2">
                          <Badge variant={item.success ? 'success' : 'danger'}>
                            {item.success ? '✓' : '✗'}
                          </Badge>
                          <code className="text-sm font-mono bg-muted px-2 py-1 rounded">
                            {item.command}
                          </code>
                        </div>
                        <span className="text-xs text-muted-foreground">
                          {formatTime(item.timestamp)}
                        </span>
                      </div>
                      {item.output && (
                        <div className="text-sm text-muted-foreground font-mono">
                          <pre className="whitespace-pre-wrap break-words">
                            {item.output}
                          </pre>
                        </div>
                      )}
                    </div>
                  ))
                ) : (
                  <div className="text-center py-8 text-muted-foreground">
                    {t('webshell.noHistory')}
                  </div>
                )}
              </div>
            )}
          </CardContent>
        </Card>
      </div>
    </div>
  )
}