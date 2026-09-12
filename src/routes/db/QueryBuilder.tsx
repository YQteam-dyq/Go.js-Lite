import React, { useState, useEffect } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Badge } from '@/components/ui/Badge';
import { Modal, ModalContent, ModalHeader, ModalTitle, ModalTrigger } from '@/components/ui/Modal';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/Tabs';
import { dbApi } from '@/api/db';
import { toast } from '@/components/ui/Toast';

interface TableColumn {
  name: string;
  type: string;
  nullable: string;
  key: string;
  default: string | null;
  extra: string;
}

interface QueryCondition {
  field: string;
  operator: string;
  value: any;
}

interface QueryOrder {
  field: string;
  direction: 'ASC' | 'DESC';
}

export function QueryBuilder() {
  const [searchParams] = useSearchParams();
  const navigate = useNavigate();
  
  const connId = searchParams.get('connId') || '';
  const database = searchParams.get('database') || '';
  const table = searchParams.get('table') || '';
  
  const [columns, setColumns] = useState<TableColumn[]>([]);
  const [selectedColumns, setSelectedColumns] = useState<string[]>(['*']);
  const [conditions, setConditions] = useState<QueryCondition[]>([]);
  const [groupBy, setGroupBy] = useState<string[]>([]);
  const [orderBy, setOrderBy] = useState<QueryOrder[]>([]);
  const [limit, setLimit] = useState(100);
  const [offset, setOffset] = useState(0);
  const [results, setResults] = useState<any[]>([]);
  const [sql, setSql] = useState('');
  const [loading, setLoading] = useState(false);
  const [isPreviewModalOpen, setIsPreviewModalOpen] = useState(false);
  const [isExecuteModalOpen, setIsExecuteModalOpen] = useState(false);
  
  useEffect(() => {
    if (connId && database && table) {
      loadTableStructure();
    }
  }, [connId, database, table]);

  const loadTableStructure = async () => {
    try {
      const response = await dbApi.structure({
        connId,
        database,
        table
      });
      
      if (response.success) {
        setColumns(response.data);
      }
    } catch (error) {
      toast({
        title: '加载表结构失败',
        description: error instanceof Error ? error.message : '未知错误',
        variant: 'destructive'
      });
    }
  };

  const buildQuery = () => {
    let query = 'SELECT ';
    
    if (selectedColumns.length === 0) {
      query += '*';
    } else {
      const columnList = selectedColumns.map(col => {
        if (col === '*') return '*';
        return `\`${col.replace(/`/g, '``')}\``;
      });
      query += columnList.join(', ');
    }
    
    query += ` FROM \`${table.replace(/`/g, '``')}\``;
    
    if (conditions.length > 0) {
      const whereParts = conditions.map(condition => {
        const field = `\`${condition.field.replace(/`/g, '``')}\``;
        let value = condition.value;
        
        if (typeof value === 'string') {
          value = `'${value.replace(/'/g, "''")}'`;
        }
        
        switch (condition.operator) {
          case 'eq': return `${field} = ${value}`;
          case 'ne': return `${field} != ${value}`;
          case 'gt': return `${field} > ${value}`;
          case 'gte': return `${field} >= ${value}`;
          case 'lt': return `${field} < ${value}`;
          case 'lte': return `${field} <= ${value}`;
          case 'like': return `${field} LIKE ${value}`;
          case 'in': 
            if (Array.isArray(value)) {
              const values = value.map(v => typeof v === 'string' ? `'${v.replace(/'/g, "''")}'` : v);
              return `${field} IN (${values.join(', ')})`;
            }
            return `${field} IN (${value})`;
          case 'null': return `${field} IS NULL`;
          case 'notnull': return `${field} IS NOT NULL`;
          default: return `${field} = ${value}`;
        }
      });
      query += ' WHERE ' + whereParts.join(' AND ');
    }
    
    if (groupBy.length > 0) {
      const groupParts = groupBy.map(field => `\`${field.replace(/`/g, '``')}\``);
      query += ' GROUP BY ' + groupParts.join(', ');
    }
    
    if (orderBy.length > 0) {
      const orderParts = orderBy.map(order => {
        const field = `\`${order.field.replace(/`/g, '``')}\``;
        const direction = order.direction === 'DESC' ? 'DESC' : 'ASC';
        return `${field} ${direction}`;
      });
      query += ' ORDER BY ' + orderParts.join(', ');
    }
    
    if (limit > 0) {
      query += ` LIMIT ${limit}`;
      if (offset > 0) {
        query += ` OFFSET ${offset}`;
      }
    }
    
    return query;
  };

  const executeQuery = async () => {
    try {
      setLoading(true);
      const query = buildQuery();
      setSql(query);
      
      const response = await fetch('/api.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: new URLSearchParams({
          action: 'db_sql',
          connId,
          database,
          sql: query
        })
      });
      
      const data = await response.json();
      
      if (data.success) {
        setResults(data.results[0]?.rows || []);
        toast({
          title: '查询成功',
          description: `返回 ${data.results[0]?.rows?.length || 0} 条记录`
        });
      } else {
        toast({
          title: '查询失败',
          description: data.error || '未知错误',
          variant: 'destructive'
        });
      }
    } catch (error) {
      toast({
        title: '查询失败',
        description: error instanceof Error ? error.message : '未知错误',
        variant: 'destructive'
      });
    } finally {
      setLoading(false);
    }
  };

  const previewQuery = async () => {
    try {
      setLoading(true);
      const query = buildQuery();
      setSql(query);
      
      const response = await fetch('/api.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: new URLSearchParams({
          action: 'db_query_builder_preview',
          connId,
          database,
          table,
          columns: selectedColumns,
          conditions: JSON.stringify(conditions),
          groupBy: JSON.stringify(groupBy),
          orderBy: JSON.stringify(orderBy)
        })
      });
      
      const data = await response.json();
      
      if (data.success) {
        setResults(data.data);
        setIsPreviewModalOpen(true);
      } else {
        toast({
          title: '预览失败',
          description: data.error || '未知错误',
          variant: 'destructive'
        });
      }
    } catch (error) {
      toast({
        title: '预览失败',
        description: error instanceof Error ? error.message : '未知错误',
        variant: 'destructive'
      });
    } finally {
      setLoading(false);
    }
  };

  const addCondition = () => {
    setConditions([...conditions, {
      field: columns[0]?.name || '',
      operator: 'eq',
      value: ''
    }]);
  };

  const removeCondition = (index: number) => {
    setConditions(conditions.filter((_, i) => i !== index));
  };

  const updateCondition = (index: number, field: string, value: any) => {
    const newConditions = [...conditions];
    newConditions[index] = { ...newConditions[index], [field]: value };
    setConditions(newConditions);
  };

  const addGroupBy = () => {
    if (groupBy.length < columns.length) {
      setGroupBy([...groupBy, columns[groupBy.length]?.name || '']);
    }
  };

  const removeGroupBy = (index: number) => {
    setGroupBy(groupBy.filter((_, i) => i !== index));
  };

  const addOrderBy = () => {
    if (orderBy.length < columns.length) {
      setOrderBy([...orderBy, {
        field: columns[orderBy.length]?.name || '',
        direction: 'ASC'
      }]);
    }
  };

  const removeOrderBy = (index: number) => {
    setOrderBy(orderBy.filter((_, i) => i !== index));
  };

  const updateOrderBy = (index: number, field: string, value: any) => {
    const newOrderBy = [...orderBy];
    newOrderBy[index] = { ...newOrderBy[index], [field]: value };
    setOrderBy(newOrderBy);
  };

  const toggleColumn = (column: string) => {
    if (column === '*') {
      setSelectedColumns(['*']);
    } else {
      setSelectedColumns(prev => 
        prev.includes(column)
          ? prev.filter(col => col !== column)
          : [...prev.filter(col => col !== '*'), column]
      );
    }
  };

  const clearAll = () => {
    setSelectedColumns(['*']);
    setConditions([]);
    setGroupBy([]);
    setOrderBy([]);
    setLimit(100);
    setOffset(0);
    setResults([]);
    setSql('');
  };

  const operators = [
    { value: 'eq', label: '=' },
    { value: 'ne', label: '!=' },
    { value: 'gt', label: '>' },
    { value: 'gte', label: '>=' },
    { value: 'lt', label: '<' },
    { value: 'lte', label: '<=' },
    { value: 'like', label: 'LIKE' },
    { value: 'in', label: 'IN' },
    { value: 'null', label: 'IS NULL' },
    { value: 'notnull', label: 'IS NOT NULL' }
  ];

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold">查询构建器</h1>
          <p className="text-gray-600">{database}.{table}</p>
        </div>
        <div className="flex gap-2">
          <Button variant="outline" onClick={() => setIsPreviewModalOpen(true)}>
            预览SQL
          </Button>
          <Button variant="outline" onClick={() => setIsExecuteModalOpen(true)}>
            执行查询
          </Button>
          <Button variant="outline" onClick={clearAll}>
            清空
          </Button>
          <Button variant="outline" onClick={() => navigate('/db/browser')}>
            返回
          </Button>
        </div>
      </div>

      <Tabs defaultValue="columns" className="space-y-4">
        <TabsList>
          <TabsTrigger value="columns">列选择</TabsTrigger>
          <TabsTrigger value="conditions">条件</TabsTrigger>
          <TabsTrigger value="grouping">分组</TabsTrigger>
          <TabsTrigger value="ordering">排序</TabsTrigger>
          <TabsTrigger value="limit">限制</TabsTrigger>
        </TabsList>

        <TabsContent value="columns">
          <Card>
            <CardHeader>
              <CardTitle>选择列</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="border rounded-lg p-4">
                <div className="flex flex-wrap gap-2">
                  <button
                    onClick={() => toggleColumn('*')}
                    className={`px-3 py-1 rounded-md text-sm ${
                      selectedColumns.includes('*')
                        ? 'bg-blue-500 text-white'
                        : 'bg-gray-200 hover:bg-gray-300'
                    }`}
                  >
                    *
                  </button>
                  {columns.map((column) => (
                    <button
                      key={column.name}
                      onClick={() => toggleColumn(column.name)}
                      className={`px-3 py-1 rounded-md text-sm ${
                        selectedColumns.includes(column.name)
                          ? 'bg-blue-500 text-white'
                          : 'bg-gray-200 hover:bg-gray-300'
                      }`}
                    >
                      {column.name}
                    </button>
                  ))}
                </div>
                <div className="mt-2 text-sm text-gray-600">
                  已选择 {selectedColumns.length} 列
                </div>
              </div>
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="conditions">
          <Card>
            <CardHeader>
              <CardTitle>查询条件</CardTitle>
              <Button onClick={addCondition}>添加条件</Button>
            </CardHeader>
            <CardContent>
              {conditions.length === 0 ? (
                <p className="text-gray-500 text-center py-4">暂无条件</p>
              ) : (
                <div className="space-y-3">
                  {conditions.map((condition, index) => (
                    <div key={index} className="border rounded-lg p-3">
                      <div className="grid grid-cols-3 gap-2">
                        <select
                          className="px-3 py-2 border border-gray-300 rounded-md"
                          value={condition.field}
                          onChange={(e) => updateCondition(index, 'field', e.target.value)}
                        >
                          {columns.map(col => (
                            <option key={col.name} value={col.name}>{col.name}</option>
                          ))}
                        </select>
                        <select
                          className="px-3 py-2 border border-gray-300 rounded-md"
                          value={condition.operator}
                          onChange={(e) => updateCondition(index, 'operator', e.target.value)}
                        >
                          {operators.map(op => (
                            <option key={op.value} value={op.value}>{op.label}</option>
                          ))}
                        </select>
                        {condition.operator !== 'null' && condition.operator !== 'notnull' ? (
                          <Input
                            value={condition.value}
                            onChange={(e) => updateCondition(index, 'value', e.target.value)}
                            placeholder="值"
                          />
                        ) : (
                          <div className="flex items-center justify-center text-gray-500">
                            -
                          </div>
                        )}
                      </div>
                      <Button
                        size="sm"
                        variant="destructive"
                        className="mt-2"
                        onClick={() => removeCondition(index)}
                      >
                        删除
                      </Button>
                    </div>
                  ))}
                </div>
              )}
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="grouping">
          <Card>
            <CardHeader>
              <CardTitle>分组</CardTitle>
              <Button onClick={addGroupBy} disabled={groupBy.length >= columns.length}>
                添加分组
              </Button>
            </CardHeader>
            <CardContent>
              {groupBy.length === 0 ? (
                <p className="text-gray-500 text-center py-4">暂无分组</p>
              ) : (
                <div className="space-y-2">
                  {groupBy.map((field, index) => (
                    <div key={index} className="flex items-center justify-between border rounded-lg p-2">
                      <span>{field}</span>
                      <Button
                        size="sm"
                        variant="destructive"
                        onClick={() => removeGroupBy(index)}
                      >
                        删除
                      </Button>
                    </div>
                  ))}
                </div>
              )}
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="ordering">
          <Card>
            <CardHeader>
              <CardTitle>排序</CardTitle>
              <Button onClick={addOrderBy} disabled={orderBy.length >= columns.length}>
                添加排序
              </Button>
            </CardHeader>
            <CardContent>
              {orderBy.length === 0 ? (
                <p className="text-gray-500 text-center py-4">暂无排序</p>
              ) : (
                <div className="space-y-3">
                  {orderBy.map((order, index) => (
                    <div key={index} className="border rounded-lg p-3">
                      <div className="grid grid-cols-2 gap-2">
                        <select
                          className="px-3 py-2 border border-gray-300 rounded-md"
                          value={order.field}
                          onChange={(e) => updateOrderBy(index, 'field', e.target.value)}
                        >
                          {columns.map(col => (
                            <option key={col.name} value={col.name}>{col.name}</option>
                          ))}
                        </select>
                        <select
                          className="px-3 py-2 border border-gray-300 rounded-md"
                          value={order.direction}
                          onChange={(e) => updateOrderBy(index, 'direction', e.target.value)}
                        >
                          <option value="ASC">升序</option>
                          <option value="DESC">降序</option>
                        </select>
                      </div>
                      <Button
                        size="sm"
                        variant="destructive"
                        className="mt-2"
                        onClick={() => removeOrderBy(index)}
                      >
                        删除
                      </Button>
                    </div>
                  ))}
                </div>
              )}
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="limit">
          <Card>
            <CardHeader>
              <CardTitle>限制</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="text-sm font-medium">返回记录数</label>
                  <Input
                    type="number"
                    value={limit}
                    onChange={(e) => setLimit(parseInt(e.target.value) || 100)}
                    min="1"
                    max="10000"
                  />
                </div>
                <div>
                  <label className="text-sm font-medium">偏移量</label>
                  <Input
                    type="number"
                    value={offset}
                    onChange={(e) => setOffset(parseInt(e.target.value) || 0)}
                    min="0"
                  />
                </div>
              </div>
            </CardContent>
          </Card>
        </TabsContent>
      </Tabs>

      {results.length > 0 && (
        <Card>
          <CardHeader>
            <CardTitle>查询结果</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="overflow-x-auto">
              <table className="w-full border-collapse border border-gray-300">
                <thead>
                  <tr className="bg-gray-50">
                    {selectedColumns.map(col => (
                      <th key={col} className="border border-gray-300 px-4 py-2 text-left">
                        {col === '*' ? '*' : col}
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {results.map((row, index) => (
                    <tr key={index}>
                      {selectedColumns.map(col => (
                        <td key={col} className="border border-gray-300 px-4 py-2">
                          {row[col] !== null && row[col] !== undefined ? row[col] : 'NULL'}
                        </td>
                      ))}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </CardContent>
        </Card>
      )}

      <Modal open={isPreviewModalOpen} onOpenChange={setIsPreviewModalOpen}>
        <ModalContent className="max-w-3xl max-h-[80vh] overflow-y-auto">
          <ModalHeader>
            <ModalTitle>预览SQL</ModalTitle>
          </ModalHeader>
          <div className="space-y-4">
            <div>
              <label className="text-sm font-medium">生成的SQL</label>
              <div className="border rounded-md p-3 bg-gray-50 font-mono text-sm">
                {sql}
              </div>
            </div>
            <div className="flex justify-end gap-2">
              <Button variant="outline" onClick={() => setIsPreviewModalOpen(false)}>
                关闭
              </Button>
              <Button onClick={previewQuery}>
                预览结果
              </Button>
            </div>
          </div>
        </ModalContent>
      </Modal>

      <Modal open={isExecuteModalOpen} onOpenChange={setIsExecuteModalOpen}>
        <ModalContent className="max-w-3xl">
          <ModalHeader>
            <ModalTitle>执行查询</ModalTitle>
          </ModalHeader>
          <div className="space-y-4">
            <div>
              <label className="text-sm font-medium">确认执行以下SQL查询</label>
              <div className="border rounded-md p-3 bg-gray-50 font-mono text-sm">
                {sql}
              </div>
            </div>
            <div className="flex justify-end gap-2">
              <Button variant="outline" onClick={() => setIsExecuteModalOpen(false)}>
                取消
              </Button>
              <Button onClick={executeQuery} disabled={loading}>
                {loading ? '执行中...' : '执行查询'}
              </Button>
            </div>
          </div>
        </ModalContent>
      </Modal>
    </div>
  );
}