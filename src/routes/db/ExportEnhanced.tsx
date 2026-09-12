import React, { useState, useEffect } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Badge } from '@/components/ui/Badge';
import { Checkbox } from '@/components/ui/Checkbox';
import { Progress } from '@/components/ui/Progress';
import { dbApi } from '@/api/db';
import { toast } from '@/components/ui/Toast';

interface TableInfo {
  name: string;
  rows: number;
  size: number;
  engine: string;
  collation: string;
  comment: string;
}

interface ExportTask {
  id: string;
  name: string;
  status: 'pending' | 'running' | 'completed' | 'failed';
  progress: number;
  startTime: string;
  endTime?: string;
  message?: string;
}

export function ExportEnhanced() {
  const [searchParams] = useSearchParams();
  const navigate = useNavigate();
  
  const connId = searchParams.get('connId') || '';
  const database = searchParams.get('database') || '';
  
  const [tables, setTables] = useState<TableInfo[]>([]);
  const [selectedTables, setSelectedTables] = useState<string[]>([]);
  const [loading, setLoading] = useState(true);
  const [exporting, setExporting] = useState(false);
  const [exportProgress, setExportProgress] = useState(0);
  const [exportFormat, setExportFormat] = useState('sql');
  const [includeStructure, setIncludeStructure] = useState(true);
  const [includeData, setIncludeData] = useState(true);
  const [whereClause, setWhereClause] = useState('');
  const [compression, setCompression] = useState('none');
  const [scheduledTasks, setScheduledTasks] = useState<ExportTask[]>([]);
  
  useEffect(() => {
    if (connId && database) {
      loadTables();
      loadScheduledTasks();
    }
  }, [connId, database]);

  const loadTables = async () => {
    try {
      setLoading(true);
      const response = await dbApi.tables({
        connId,
        database
      });
      
      if (response.success) {
        setTables(response.data);
        // 默认选择所有表
        setSelectedTables(response.data.map(table => table.name));
      }
    } catch (error) {
      toast({
        title: '加载表列表失败',
        description: error instanceof Error ? error.message : '未知错误',
        variant: 'destructive'
      });
    } finally {
      setLoading(false);
    }
  };

  const loadScheduledTasks = async () => {
    try {
      const response = await fetch('/api.php?action=db_export_scheduled_tasks&connId=' + connId);
      const data = await response.json();
      
      if (data.success) {
        setScheduledTasks(data.data);
      }
    } catch (error) {
      console.error('加载定时任务失败:', error);
    }
  };

  const handleTableToggle = (tableName: string) => {
    setSelectedTables(prev => 
      prev.includes(tableName)
        ? prev.filter(name => name !== tableName)
        : [...prev, tableName]
    );
  };

  const handleSelectAll = (checked: boolean) => {
    if (checked) {
      setSelectedTables(tables.map(table => table.name));
    } else {
      setSelectedTables([]);
    }
  };

  const handleExport = async () => {
    if (selectedTables.length === 0) {
      toast({
        title: '请选择要导出的表',
        variant: 'destructive'
      });
      return;
    }

    try {
      setExporting(true);
      setExportProgress(0);
      
      const formData = new FormData();
      formData.append('action', 'db_export_enhanced');
      formData.append('connId', connId);
      formData.append('database', database);
      formData.append('tables', JSON.stringify(selectedTables));
      formData.append('format', exportFormat);
      formData.append('compression', compression);
      formData.append('includeStructure', includeStructure.toString());
      formData.append('includeData', includeData.toString());
      if (whereClause) {
        formData.append('whereClause', whereClause);
      }

      const response = await fetch('/api.php', {
        method: 'POST',
        body: formData
      });

      if (response.ok) {
        const blob = await response.blob();
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `backup_${new Date().toISOString().slice(0, 19).replace(/:/g, '-')}.${exportFormat}`;
        document.body.appendChild(a);
        a.click();
        window.URL.revokeObjectURL(url);
        document.body.removeChild(a);
        
        toast({
          title: '导出成功',
          description: `已导出 ${selectedTables.length} 个表`
        });
        
        loadScheduledTasks();
      } else {
        throw new Error('导出失败');
      }
    } catch (error) {
      toast({
        title: '导出失败',
        description: error instanceof Error ? error.message : '未知错误',
        variant: 'destructive'
      });
    } finally {
      setExporting(false);
      setExportProgress(0);
    }
  };

  const handleScheduleExport = async () => {
    if (selectedTables.length === 0) {
      toast({
        title: '请选择要导出的表',
        variant: 'destructive'
      });
      return;
    }

    try {
      const response = await fetch('/api.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: new URLSearchParams({
          action: 'db_export_schedule',
          connId,
          database,
          tables: JSON.stringify(selectedTables),
          format: exportFormat,
          compression,
          includeStructure: includeStructure.toString(),
          includeData: includeData.toString(),
          whereClause
        })
      });

      const data = await response.json();
      
      if (data.success) {
        toast({
          title: '定时任务创建成功',
          description: `任务将在指定时间执行`
        });
        loadScheduledTasks();
      } else {
        throw new Error(data.error || '创建定时任务失败');
      }
    } catch (error) {
      toast({
        title: '创建定时任务失败',
        description: error instanceof Error ? error.message : '未知错误',
        variant: 'destructive'
      });
    }
  };

  const formatFileSize = (bytes: number) => {
    if (bytes === 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
  };

  const getStatusBadge = (status: string) => {
    switch (status) {
      case 'pending':
        return <Badge variant="secondary">等待中</Badge>;
      case 'running':
        return <Badge variant="default">运行中</Badge>;
      case 'completed':
        return <Badge variant="default">已完成</Badge>;
      case 'failed':
        return <Badge variant="destructive">失败</Badge>;
      default:
        return <Badge variant="outline">未知</Badge>;
    }
  };

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold">数据库导出增强</h1>
          <p className="text-gray-600">{database}</p>
        </div>
        <div className="flex gap-2">
          <Button variant="outline" onClick={() => navigate('/db/browser')}>
            返回
          </Button>
        </div>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>导出设置</CardTitle>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="text-sm font-medium">导出格式</label>
              <select
                className="w-full px-3 py-2 border border-gray-300 rounded-md"
                value={exportFormat}
                onChange={(e) => setExportFormat(e.target.value)}
              >
                <option value="sql">SQL</option>
                <option value="json">JSON</option>
                <option value="csv">CSV</option>
                <option value="xml">XML</option>
              </select>
            </div>
            <div>
              <label className="text-sm font-medium">压缩格式</label>
              <select
                className="w-full px-3 py-2 border border-gray-300 rounded-md"
                value={compression}
                onChange={(e) => setCompression(e.target.value)}
              >
                <option value="none">无压缩</option>
                <option value="gzip">Gzip</option>
                <option value="zip">ZIP</option>
              </select>
            </div>
          </div>

          <div className="space-y-3">
            <label className="flex items-center space-x-2">
              <Checkbox
                checked={includeStructure}
                onCheckedChange={(checked) => setIncludeStructure(checked as boolean)}
              />
              <span>包含表结构</span>
            </label>
            <label className="flex items-center space-x-2">
              <Checkbox
                checked={includeData}
                onCheckedChange={(checked) => setIncludeData(checked as boolean)}
              />
              <span>包含表数据</span>
            </label>
          </div>

          <div>
            <label className="text-sm font-medium">WHERE条件（可选）</label>
            <Input
              value={whereClause}
              onChange={(e) => setWhereClause(e.target.value)}
              placeholder="例如: status = 'active' AND created_at > '2024-01-01'"
            />
          </div>

          <div className="flex gap-2">
            <Button onClick={handleExport} disabled={exporting || selectedTables.length === 0}>
              {exporting ? '导出中...' : '立即导出'}
            </Button>
            <Button variant="outline" onClick={handleScheduleExport} disabled={selectedTables.length === 0}>
              定时导出
            </Button>
          </div>

          {exporting && (
            <div className="space-y-2">
              <div className="flex justify-between text-sm">
                <span>导出进度</span>
                <span>{exportProgress}%</span>
              </div>
              <Progress value={exportProgress} />
            </div>
          )}
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>选择表</CardTitle>
          <div className="flex items-center justify-between">
            <label className="flex items-center space-x-2">
              <Checkbox
                checked={selectedTables.length === tables.length}
                onCheckedChange={handleSelectAll}
              />
              <span>全选</span>
            </label>
            <span className="text-sm text-gray-600">
              已选择 {selectedTables.length} / {tables.length} 个表
            </span>
          </div>
        </CardHeader>
        <CardContent>
          {loading ? (
            <div className="flex justify-center py-8">
              <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-gray-900"></div>
            </div>
          ) : (
            <div className="space-y-2 max-h-96 overflow-y-auto">
              {tables.map((table) => (
                <div key={table.name} className="flex items-center justify-between border rounded-lg p-3">
                  <div className="flex items-center space-x-3">
                    <Checkbox
                      checked={selectedTables.includes(table.name)}
                      onCheckedChange={() => handleTableToggle(table.name)}
                    />
                    <div>
                      <div className="font-medium">{table.name}</div>
                      <div className="text-sm text-gray-600">
                        {table.rows.toLocaleString()} 行 • {formatFileSize(table.size)}
                      </div>
                    </div>
                  </div>
                  <div className="text-right text-sm text-gray-500">
                    <div>{table.engine}</div>
                    <div>{table.comment}</div>
                  </div>
                </div>
              ))}
            </div>
          )}
        </CardContent>
      </Card>

      {scheduledTasks.length > 0 && (
        <Card>
          <CardHeader>
            <CardTitle>定时导出任务</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="space-y-3">
              {scheduledTasks.map((task) => (
                <div key={task.id} className="border rounded-lg p-4">
                  <div className="flex items-center justify-between mb-2">
                    <h3 className="font-medium">{task.name}</h3>
                    {getStatusBadge(task.status)}
                  </div>
                  <div className="grid grid-cols-2 gap-4 text-sm text-gray-600">
                    <div>
                      <span className="font-medium">开始时间:</span> {new Date(task.startTime).toLocaleString()}
                    </div>
                    {task.endTime && (
                      <div>
                        <span className="font-medium">结束时间:</span> {new Date(task.endTime).toLocaleString()}
                      </div>
                    )}
                  </div>
                  {task.message && (
                    <div className="mt-2 text-sm text-red-600">{task.message}</div>
                  )}
                  {task.status === 'running' && (
                    <div className="mt-2">
                      <Progress value={task.progress} />
                      <div className="text-xs text-gray-500 mt-1">{task.progress}%</div>
                    </div>
                  )}
                </div>
              ))}
            </div>
          </CardContent>
        </Card>
      )}
    </div>
  );
}