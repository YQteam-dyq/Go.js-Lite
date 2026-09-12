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

interface IndexInfo {
  name: string;
  columns: string[];
  type: string;
}

export function TableStructureManager() {
  const [searchParams] = useSearchParams();
  const navigate = useNavigate();
  
  const connId = searchParams.get('connId') || '';
  const database = searchParams.get('database') || '';
  const table = searchParams.get('table') || '';
  
  const [columns, setColumns] = useState<TableColumn[]>([]);
  const [indexes, setIndexes] = useState<IndexInfo[]>([]);
  const [loading, setLoading] = useState(true);
  const [isCreateTableModalOpen, setIsCreateTableModalOpen] = useState(false);
  const [isAddColumnModalOpen, setIsAddColumnModalOpen] = useState(false);
  const [isEditColumnModalOpen, setIsEditColumnModalOpen] = useState(false);
  const [isCreateIndexModalOpen, setIsCreateIndexModalOpen] = useState(false);
  const [isDropIndexModalOpen, setIsDropIndexModalOpen] = useState(false);
  const [editingColumn, setEditingColumn] = useState<TableColumn | null>(null);
  const [newColumn, setNewColumn] = useState({
    name: '',
    type: 'VARCHAR(255)',
    nullable: 'YES',
    default: '',
    extra: '',
    after: ''
  });
  const [newTable, setNewTable] = useState({
    name: '',
    columns: [] as Array<{
      name: string;
      type: string;
      nullable: string;
      default: string;
      extra: string;
    }>,
    engine: 'InnoDB',
    charset: 'utf8mb4'
  });
  const [newIndex, setNewIndex] = useState({
    name: '',
    columns: [] as string[],
    type: 'INDEX'
  });
  const [selectedColumns, setSelectedColumns] = useState<string[]>([]);
  const [indexToDrop, setIndexToDrop] = useState('');

  useEffect(() => {
    if (connId && database && table) {
      loadTableStructure();
      loadTableIndexes();
    }
  }, [connId, database, table]);

  const loadTableStructure = async () => {
    try {
      setLoading(true);
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
    } finally {
      setLoading(false);
    }
  };

  const loadTableIndexes = async () => {
    try {
      const response = await fetch(`/api.php?action=db_indexes&connId=${connId}&database=${database}&table=${table}`);
      const data = await response.json();
      
      if (data.success) {
        setIndexes(data.data);
      }
    } catch (error) {
      toast({
        title: '加载索引失败',
        description: error instanceof Error ? error.message : '未知错误',
        variant: 'destructive'
      });
    }
  };

  const handleCreateTable = async () => {
    try {
      const response = await dbApi.createTable({
        connId,
        database,
        tableName: newTable.name,
        columns: newTable.columns,
        engine: newTable.engine,
        charset: newTable.charset
      });
      
      if (response.success) {
        toast({
          title: '创建表成功',
          description: `表 ${newTable.name} 创建成功`
        });
        setIsCreateTableModalOpen(false);
        setNewTable({
          name: '',
          columns: [],
          engine: 'InnoDB',
          charset: 'utf8mb4'
        });
        navigate(`/db/structure?connId=${connId}&database=${database}&table=${newTable.name}`);
      }
    } catch (error) {
      toast({
        title: '创建表失败',
        description: error instanceof Error ? error.message : '未知错误',
        variant: 'destructive'
      });
    }
  };

  const handleAddColumn = async () => {
    try {
      const response = await dbApi.alterTable({
        connId,
        database,
        tableName: table,
        action: 'ADD',
        column: newColumn
      });
      
      if (response.success) {
        toast({
          title: '添加列成功',
          description: `列 ${newColumn.name} 添加成功`
        });
        setIsAddColumnModalOpen(false);
        setNewColumn({
          name: '',
          type: 'VARCHAR(255)',
          nullable: 'YES',
          default: '',
          extra: '',
          after: ''
        });
        loadTableStructure();
      }
    } catch (error) {
      toast({
        title: '添加列失败',
        description: error instanceof Error ? error.message : '未知错误',
        variant: 'destructive'
      });
    }
  };

  const handleModifyColumn = async () => {
    if (!editingColumn) return;
    
    try {
      const response = await dbApi.alterTable({
        connId,
        database,
        tableName: table,
        action: 'MODIFY',
        column: editingColumn
      });
      
      if (response.success) {
        toast({
          title: '修改列成功',
          description: `列 ${editingColumn.name} 修改成功`
        });
        setIsEditColumnModalOpen(false);
        setEditingColumn(null);
        loadTableStructure();
      }
    } catch (error) {
      toast({
        title: '修改列失败',
        description: error instanceof Error ? error.message : '未知错误',
        variant: 'destructive'
      });
    }
  };

  const handleDropColumn = async (columnName: string) => {
    if (!confirm(`确定要删除列 ${columnName} 吗？此操作不可恢复！`)) {
      return;
    }

    try {
      const response = await dbApi.alterTable({
        connId,
        database,
        tableName: table,
        action: 'DROP',
        column: { name: columnName }
      });
      
      if (response.success) {
        toast({
          title: '删除列成功',
          description: `列 ${columnName} 删除成功`
        });
        loadTableStructure();
      }
    } catch (error) {
      toast({
        title: '删除列失败',
        description: error instanceof Error ? error.message : '未知错误',
        variant: 'destructive'
      });
    }
  };

  const handleCreateIndex = async () => {
    try {
      const response = await dbApi.createIndex({
        connId,
        database,
        tableName: table,
        indexName: newIndex.name,
        columns: newIndex.columns,
        indexType: newIndex.type
      });
      
      if (response.success) {
        toast({
          title: '创建索引成功',
          description: `索引 ${newIndex.name} 创建成功`
        });
        setIsCreateIndexModalOpen(false);
        setNewIndex({
          name: '',
          columns: [],
          type: 'INDEX'
        });
        setSelectedColumns([]);
        loadTableIndexes();
      }
    } catch (error) {
      toast({
        title: '创建索引失败',
        description: error instanceof Error ? error.message : '未知错误',
        variant: 'destructive'
      });
    }
  };

  const handleDropIndex = async () => {
    if (!indexToDrop) return;
    
    if (!confirm(`确定要删除索引 ${indexToDrop} 吗？此操作不可恢复！`)) {
      return;
    }

    try {
      const response = await dbApi.dropIndex({
        connId,
        database,
        tableName: table,
        indexName: indexToDrop
      });
      
      if (response.success) {
        toast({
          title: '删除索引成功',
          description: `索引 ${indexToDrop} 删除成功`
        });
        setIsDropIndexModalOpen(false);
        setIndexToDrop('');
        loadTableIndexes();
      }
    } catch (error) {
      toast({
        title: '删除索引失败',
        description: error instanceof Error ? error.message : '未知错误',
        variant: 'destructive'
      });
    }
  };

  const handleColumnChange = (field: keyof typeof newColumn, value: string) => {
    setNewColumn(prev => ({ ...prev, [field]: value }));
  };

  const handleEditColumn = (column: TableColumn) => {
    setEditingColumn(column);
    setIsEditColumnModalOpen(true);
  };

  const toggleColumnSelection = (columnName: string) => {
    setSelectedColumns(prev => 
      prev.includes(columnName)
        ? prev.filter(col => col !== columnName)
        : [...prev, columnName]
    );
  };

  const renderColumnBadge = (column: TableColumn) => {
    const badges = [];
    
    if (column.key === 'PRI') {
      badges.push(<Badge key="primary" variant="primary">主键</Badge>);
    }
    if (column.key === 'UNI') {
      badges.push(<Badge key="unique" variant="outline">唯一</Badge>);
    }
    if (column.key === 'MUL') {
      badges.push(<Badge key="index" variant="secondary">索引</Badge>);
    }
    if (column.extra === 'auto_increment') {
      badges.push(<Badge key="auto" variant="secondary">自增</Badge>);
    }
    if (column.nullable === 'NO') {
      badges.push(<Badge key="notnull" variant="outline">非空</Badge>);
    }
    
    return badges;
  };

  const columnTypes = [
    'VARCHAR(255)', 'VARCHAR(500)', 'TEXT', 'INT', 'BIGINT', 
    'DECIMAL(10,2)', 'DATETIME', 'DATE', 'TIME', 'BOOLEAN',
    'TINYINT', 'SMALLINT', 'MEDIUMINT', 'FLOAT', 'DOUBLE'
  ];

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold">表结构管理</h1>
          <p className="text-gray-600">{database}.{table}</p>
        </div>
        <div className="flex gap-2">
          <Button onClick={() => setIsCreateTableModalOpen(true)}>
            创建新表
          </Button>
          <Button variant="outline" onClick={() => navigate('/db/browser')}>
            返回
          </Button>
        </div>
      </div>

      <Tabs defaultValue="columns" className="space-y-4">
        <TabsList>
          <TabsTrigger value="columns">列管理</TabsTrigger>
          <TabsTrigger value="indexes">索引管理</TabsTrigger>
        </TabsList>

        <TabsContent value="columns">
          <Card>
            <CardHeader>
              <CardTitle>列管理</CardTitle>
              <div className="flex gap-2">
                <Button onClick={() => setIsAddColumnModalOpen(true)}>
                  添加列
                </Button>
              </div>
            </CardHeader>
            <CardContent>
              {loading ? (
                <div className="flex justify-center py-8">
                  <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-gray-900"></div>
                </div>
              ) : (
                <div className="overflow-x-auto">
                  <table className="w-full border-collapse border border-gray-300">
                    <thead>
                      <tr className="bg-gray-50">
                        <th className="border border-gray-300 px-4 py-2 text-left">列名</th>
                        <th className="border border-gray-300 px-4 py-2 text-left">数据类型</th>
                        <th className="border border-gray-300 px-4 py-2 text-left">默认值</th>
                        <th className="border border-gray-300 px-4 py-2 text-left">属性</th>
                        <th className="border border-gray-300 px-4 py-2 text-left">操作</th>
                      </tr>
                    </thead>
                    <tbody>
                      {columns.map((column, index) => (
                        <tr key={index}>
                          <td className="border border-gray-300 px-4 py-2 font-medium">
                            {column.name}
                          </td>
                          <td className="border border-gray-300 px-4 py-2">
                            {column.type}
                          </td>
                          <td className="border border-gray-300 px-4 py-2">
                            {column.default || '-'}
                          </td>
                          <td className="border border-gray-300 px-4 py-2">
                            <div className="flex gap-1 flex-wrap">
                              {renderColumnBadge(column)}
                            </div>
                          </td>
                          <td className="border border-gray-300 px-4 py-2">
                            <div className="flex gap-1">
                              <Button 
                                size="sm" 
                                variant="outline"
                                onClick={() => handleEditColumn(column)}
                              >
                                编辑
                              </Button>
                              <Button 
                                size="sm" 
                                variant="destructive"
                                onClick={() => handleDropColumn(column.name)}
                              >
                                删除
                              </Button>
                            </div>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="indexes">
          <Card>
            <CardHeader>
              <CardTitle>索引管理</CardTitle>
              <div className="flex gap-2">
                <Button onClick={() => setIsCreateIndexModalOpen(true)}>
                  创建索引
                </Button>
                <Button variant="outline" onClick={() => setIsDropIndexModalOpen(true)}>
                  删除索引
                </Button>
              </div>
            </CardHeader>
            <CardContent>
              {loading ? (
                <div className="flex justify-center py-8">
                  <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-gray-900"></div>
                </div>
              ) : (
                <div className="space-y-4">
                  {indexes.length === 0 ? (
                    <p className="text-gray-500 text-center py-4">该表没有索引</p>
                  ) : (
                    indexes.map((index, indexIndex) => (
                      <div key={indexIndex} className="border rounded-lg p-4">
                        <div className="flex items-center justify-between mb-2">
                          <h3 className="font-medium">{index.name}</h3>
                          <Badge variant="outline">{index.type}</Badge>
                        </div>
                        <p className="text-sm text-gray-600">
                          列: {index.columns.join(', ')}
                        </p>
                      </div>
                    ))
                  )}
                </div>
              )}
            </CardContent>
          </Card>
        </TabsContent>
      </Tabs>

      <Modal open={isAddColumnModalOpen} onOpenChange={setIsAddColumnModalOpen}>
        <ModalContent className="max-w-2xl">
          <ModalHeader>
            <ModalTitle>添加列</ModalTitle>
          </ModalHeader>
          <div className="space-y-4">
            <div>
              <label className="text-sm font-medium">列名</label>
              <Input
                value={newColumn.name}
                onChange={(e) => handleColumnChange('name', e.target.value)}
                placeholder="输入列名"
              />
            </div>
            <div>
              <label className="text-sm font-medium">数据类型</label>
              <select
                className="w-full px-3 py-2 border border-gray-300 rounded-md"
                value={newColumn.type}
                onChange={(e) => handleColumnChange('type', e.target.value)}
              >
                {columnTypes.map(type => (
                  <option key={type} value={type}>{type}</option>
                ))}
              </select>
            </div>
            <div>
              <label className="text-sm font-medium">是否可为空</label>
              <select
                className="w-full px-3 py-2 border border-gray-300 rounded-md"
                value={newColumn.nullable}
                onChange={(e) => handleColumnChange('nullable', e.target.value)}
              >
                <option value="YES">是</option>
                <option value="NO">否</option>
              </select>
            </div>
            <div>
              <label className="text-sm font-medium">默认值</label>
              <Input
                value={newColumn.default}
                onChange={(e) => handleColumnChange('default', e.target.value)}
                placeholder="输入默认值"
              />
            </div>
            <div>
              <label className="text-sm font-medium">额外属性</label>
              <select
                className="w-full px-3 py-2 border border-gray-300 rounded-md"
                value={newColumn.extra}
                onChange={(e) => handleColumnChange('extra', e.target.value)}
              >
                <option value="">无</option>
                <option value="auto_increment">自增</option>
              </select>
            </div>
            <div>
              <label className="text-sm font-medium">插入到列后（可选）</label>
              <select
                className="w-full px-3 py-2 border border-gray-300 rounded-md"
                value={newColumn.after}
                onChange={(e) => handleColumnChange('after', e.target.value)}
              >
                <option value="">第一列</option>
                {columns.map(col => (
                  <option key={col.name} value={col.name}>{col.name}</option>
                ))}
              </select>
            </div>
          </div>
          <div className="flex justify-end gap-2 mt-4">
            <Button variant="outline" onClick={() => setIsAddColumnModalOpen(false)}>
              取消
            </Button>
            <Button onClick={handleAddColumn}>
              添加
            </Button>
          </div>
        </ModalContent>
      </Modal>

      <Modal open={isEditColumnModalOpen} onOpenChange={setIsEditColumnModalOpen}>
        <ModalContent className="max-w-2xl">
          <ModalHeader>
            <ModalTitle>编辑列</ModalTitle>
          </ModalHeader>
          {editingColumn && (
            <div className="space-y-4">
              <div>
                <label className="text-sm font-medium">列名</label>
                <Input
                  value={editingColumn.name}
                  onChange={(e) => setEditingColumn(prev => prev ? { ...prev, name: e.target.value } : null)}
                  placeholder="输入列名"
                />
              </div>
              <div>
                <label className="text-sm font-medium">数据类型</label>
                <select
                  className="w-full px-3 py-2 border border-gray-300 rounded-md"
                  value={editingColumn.type}
                  onChange={(e) => setEditingColumn(prev => prev ? { ...prev, type: e.target.value } : null)}
                >
                  {columnTypes.map(type => (
                    <option key={type} value={type}>{type}</option>
                  ))}
                </select>
              </div>
              <div>
                <label className="text-sm font-medium">是否可为空</label>
                <select
                  className="w-full px-3 py-2 border border-gray-300 rounded-md"
                  value={editingColumn.nullable}
                  onChange={(e) => setEditingColumn(prev => prev ? { ...prev, nullable: e.target.value } : null)}
                >
                  <option value="YES">是</option>
                  <option value="NO">否</option>
                </select>
              </div>
              <div>
                <label className="text-sm font-medium">默认值</label>
                <Input
                  value={editingColumn.default || ''}
                  onChange={(e) => setEditingColumn(prev => prev ? { ...prev, default: e.target.value } : null)}
                  placeholder="输入默认值"
                />
              </div>
            </div>
          )}
          <div className="flex justify-end gap-2 mt-4">
            <Button variant="outline" onClick={() => setIsEditColumnModalOpen(false)}>
              取消
            </Button>
            <Button onClick={handleModifyColumn}>
              更新
            </Button>
          </div>
        </ModalContent>
      </Modal>

      <Modal open={isCreateIndexModalOpen} onOpenChange={setIsCreateIndexModalOpen}>
        <ModalContent className="max-w-2xl">
          <ModalHeader>
            <ModalTitle>创建索引</ModalTitle>
          </ModalHeader>
          <div className="space-y-4">
            <div>
              <label className="text-sm font-medium">索引名</label>
              <Input
                value={newIndex.name}
                onChange={(e) => setNewIndex(prev => ({ ...prev, name: e.target.value }))}
                placeholder="输入索引名"
              />
            </div>
            <div>
              <label className="text-sm font-medium">索引类型</label>
              <select
                className="w-full px-3 py-2 border border-gray-300 rounded-md"
                value={newIndex.type}
                onChange={(e) => setNewIndex(prev => ({ ...prev, type: e.target.value }))}
              >
                <option value="INDEX">普通索引</option>
                <option value="UNIQUE">唯一索引</option>
                <option value="FULLTEXT">全文索引</option>
              </select>
            </div>
            <div>
              <label className="text-sm font-medium">选择列</label>
              <div className="border rounded-md p-2 max-h-40 overflow-y-auto">
                {columns.map((column) => (
                  <label key={column.name} className="flex items-center space-x-2 py-1">
                    <input
                      type="checkbox"
                      checked={selectedColumns.includes(column.name)}
                      onChange={() => toggleColumnSelection(column.name)}
                      className="rounded"
                    />
                    <span>{column.name}</span>
                  </label>
                ))}
              </div>
            </div>
          </div>
          <div className="flex justify-end gap-2 mt-4">
            <Button variant="outline" onClick={() => setIsCreateIndexModalOpen(false)}>
              取消
            </Button>
            <Button onClick={handleCreateIndex}>
              创建
            </Button>
          </div>
        </ModalContent>
      </Modal>

      <Modal open={isDropIndexModalOpen} onOpenChange={setIsDropIndexModalOpen}>
        <ModalContent className="max-w-md">
          <ModalHeader>
            <ModalTitle>删除索引</ModalTitle>
          </ModalHeader>
          <div className="space-y-4">
            <div>
              <label className="text-sm font-medium">选择要删除的索引</label>
              <select
                className="w-full px-3 py-2 border border-gray-300 rounded-md"
                value={indexToDrop}
                onChange={(e) => setIndexToDrop(e.target.value)}
              >
                <option value="">请选择索引</option>
                {indexes.map((index) => (
                  <option key={index.name} value={index.name}>{index.name}</option>
                ))}
              </select>
            </div>
          </div>
          <div className="flex justify-end gap-2 mt-4">
            <Button variant="outline" onClick={() => setIsDropIndexModalOpen(false)}>
              取消
            </Button>
            <Button variant="destructive" onClick={handleDropIndex}>
              删除
            </Button>
          </div>
        </ModalContent>
      </Modal>

      <Modal open={isCreateTableModalOpen} onOpenChange={setIsCreateTableModalOpen}>
        <ModalContent className="max-w-3xl max-h-[80vh] overflow-y-auto">
          <ModalHeader>
            <ModalTitle>创建新表</ModalTitle>
          </ModalHeader>
          <div className="space-y-4">
            <div>
              <label className="text-sm font-medium">表名</label>
              <Input
                value={newTable.name}
                onChange={(e) => setNewTable(prev => ({ ...prev, name: e.target.value }))}
                placeholder="输入表名"
              />
            </div>
            <div className="grid grid-cols-2 gap-4">
              <div>
                <label className="text-sm font-medium">存储引擎</label>
                <select
                  className="w-full px-3 py-2 border border-gray-300 rounded-md"
                  value={newTable.engine}
                  onChange={(e) => setNewTable(prev => ({ ...prev, engine: e.target.value }))}
                >
                  <option value="InnoDB">InnoDB</option>
                  <option value="MyISAM">MyISAM</option>
                  <option value="MEMORY">MEMORY</option>
                </select>
              </div>
              <div>
                <label className="text-sm font-medium">字符集</label>
                <select
                  className="w-full px-3 py-2 border border-gray-300 rounded-md"
                  value={newTable.charset}
                  onChange={(e) => setNewTable(prev => ({ ...prev, charset: e.target.value }))}
                >
                  <option value="utf8mb4">utf8mb4</option>
                  <option value="utf8">utf8</option>
                  <option value="latin1">latin1</option>
                </select>
              </div>
            </div>
            <div>
              <div className="flex items-center justify-between mb-2">
                <label className="text-sm font-medium">列定义</label>
                <Button 
                  size="sm" 
                  onClick={() => setNewTable(prev => ({
                    ...prev,
                    columns: [...prev.columns, {
                      name: '',
                      type: 'VARCHAR(255)',
                      nullable: 'YES',
                      default: '',
                      extra: ''
                    }]
                  }))}
                >
                  添加列
                </Button>
              </div>
              <div className="space-y-2">
                {newTable.columns.map((column, index) => (
                  <div key={index} className="border rounded-lg p-3 space-y-2">
                    <div className="grid grid-cols-3 gap-2">
                      <Input
                        value={column.name}
                        onChange={(e) => {
                          const newColumns = [...newTable.columns];
                          newColumns[index].name = e.target.value;
                          setNewTable(prev => ({ ...prev, columns: newColumns }));
                        }}
                        placeholder="列名"
                      />
                      <select
                        className="px-3 py-2 border border-gray-300 rounded-md"
                        value={column.type}
                        onChange={(e) => {
                          const newColumns = [...newTable.columns];
                          newColumns[index].type = e.target.value;
                          setNewTable(prev => ({ ...prev, columns: newColumns }));
                        }}
                      >
                        {columnTypes.map(type => (
                          <option key={type} value={type}>{type}</option>
                        ))}
                      </select>
                      <select
                        className="px-3 py-2 border border-gray-300 rounded-md"
                        value={column.nullable}
                        onChange={(e) => {
                          const newColumns = [...newTable.columns];
                          newColumns[index].nullable = e.target.value;
                          setNewTable(prev => ({ ...prev, columns: newColumns }));
                        }}
                      >
                        <option value="YES">可为空</option>
                        <option value="NO">非空</option>
                      </select>
                    </div>
                    <div className="flex gap-2">
                      <Input
                        value={column.default}
                        onChange={(e) => {
                          const newColumns = [...newTable.columns];
                          newColumns[index].default = e.target.value;
                          setNewTable(prev => ({ ...prev, columns: newColumns }));
                        }}
                        placeholder="默认值"
                      />
                      <Button
                        size="sm"
                        variant="destructive"
                        onClick={() => {
                          const newColumns = newTable.columns.filter((_, i) => i !== index);
                          setNewTable(prev => ({ ...prev, columns: newColumns }));
                        }}
                      >
                        删除
                      </Button>
                    </div>
                  </div>
                ))}
              </div>
            </div>
          </div>
          <div className="flex justify-end gap-2 mt-4">
            <Button variant="outline" onClick={() => setIsCreateTableModalOpen(false)}>
              取消
            </Button>
            <Button onClick={handleCreateTable}>
              创建表
            </Button>
          </div>
        </ModalContent>
      </Modal>
    </div>
  );
}