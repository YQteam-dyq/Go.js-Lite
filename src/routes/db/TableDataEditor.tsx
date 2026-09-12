import React, { useState, useEffect } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/Table';
import { Badge } from '@/components/ui/Badge';
import { Modal, ModalContent, ModalHeader, ModalTitle, ModalTrigger } from '@/components/ui/Modal';
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

interface TableRowData {
  [key: string]: any;
}

export function TableDataEditor() {
  const [searchParams] = useSearchParams();
  const navigate = useNavigate();
  
  const connId = searchParams.get('connId') || '';
  const database = searchParams.get('database') || '';
  const table = searchParams.get('table') || '';
  
  const [data, setData] = useState<TableRowData[]>([]);
  const [columns, setColumns] = useState<TableColumn[]>([]);
  const [loading, setLoading] = useState(true);
  const [pagination, setPagination] = useState({
    page: 1,
    limit: 50,
    total: 0,
    totalPages: 1
  });
  const [sortField, setSortField] = useState('');
  const [sortOrder, setSortOrder] = useState<'ASC' | 'DESC'>('ASC');
  const [isInsertModalOpen, setIsInsertModalOpen] = useState(false);
  const [isEditModalOpen, setIsEditModalOpen] = useState(false);
  const [editingRow, setEditingRow] = useState<TableRowData | null>(null);
  const [newRow, setNewRow] = useState<TableRowData>({});
  const [formErrors, setFormErrors] = useState<{[key: string]: string}>({});

  useEffect(() => {
    if (connId && database && table) {
      loadTableData();
      loadTableStructure();
    }
  }, [connId, database, table, pagination.page, pagination.limit, sortField, sortOrder]);

  const loadTableData = async () => {
    try {
      setLoading(true);
      const response = await dbApi.tableData({
        connId,
        database,
        table,
        page: pagination.page,
        limit: pagination.limit,
        sortField,
        sortOrder
      });
      
      if (response.success) {
        setData(response.data);
        setPagination(prev => ({
          ...prev,
          total: response.pagination.total,
          totalPages: response.pagination.totalPages
        }));
      }
    } catch (error) {
      toast({
        title: '加载数据失败',
        description: error instanceof Error ? error.message : '未知错误',
        variant: 'destructive'
      });
    } finally {
      setLoading(false);
    }
  };

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

  const handleInsertRow = async () => {
    try {
      const response = await dbApi.insertRow({
        connId,
        database,
        table,
        data: newRow
      });
      
      if (response.success) {
        toast({
          title: '插入成功',
          description: `已插入行，ID: ${response.insertId}`
        });
        setIsInsertModalOpen(false);
        setNewRow({});
        setFormErrors({});
        loadTableData();
      }
    } catch (error) {
      toast({
        title: '插入失败',
        description: error instanceof Error ? error.message : '未知错误',
        variant: 'destructive'
      });
    }
  };

  const handleUpdateRow = async () => {
    if (!editingRow) return;
    
    const primaryKey = columns.find(col => col.key === 'PRI');
    if (!primaryKey) {
      toast({
        title: '错误',
        description: '无法找到主键',
        variant: 'destructive'
      });
      return;
    }

    try {
      const response = await dbApi.updateRow({
        connId,
        database,
        table,
        primaryKey: primaryKey.name,
        primaryKeyValue: editingRow[primaryKey.name],
        data: editingRow
      });
      
      if (response.success) {
        toast({
          title: '更新成功',
          description: `已更新 ${response.affectedRows} 行`
        });
        setIsEditModalOpen(false);
        setEditingRow(null);
        loadTableData();
      }
    } catch (error) {
      toast({
        title: '更新失败',
        description: error instanceof Error ? error.message : '未知错误',
        variant: 'destructive'
      });
    }
  };

  const handleDeleteRow = async (row: TableRowData) => {
    const primaryKey = columns.find(col => col.key === 'PRI');
    if (!primaryKey) {
      toast({
        title: '错误',
        description: '无法找到主键',
        variant: 'destructive'
      });
      return;
    }

    if (!confirm(`确定要删除这条记录吗？`)) {
      return;
    }

    try {
      const response = await dbApi.deleteRow({
        connId,
        database,
        table,
        primaryKey: primaryKey.name,
        primaryKeyValue: row[primaryKey.name]
      });
      
      if (response.success) {
        toast({
          title: '删除成功',
          description: `已删除 ${response.affectedRows} 行`
        });
        loadTableData();
      }
    } catch (error) {
      toast({
        title: '删除失败',
        description: error instanceof Error ? error.message : '未知错误',
        variant: 'destructive'
      });
    }
  };

  const handleSort = (field: string) => {
    if (sortField === field) {
      setSortOrder(sortOrder === 'ASC' ? 'DESC' : 'ASC');
    } else {
      setSortField(field);
      setSortOrder('ASC');
    }
  };

  const handleEditRow = (row: TableRowData) => {
    setEditingRow({ ...row });
    setIsEditModalOpen(true);
  };

  const handleInputChange = (field: string, value: any, type: 'edit' | 'insert' = 'edit') => {
    if (type === 'edit') {
      setEditingRow(prev => ({ ...prev, [field]: value }));
    } else {
      setNewRow(prev => ({ ...prev, [field]: value }));
    }
  };

  const renderCellContent = (value: any, column: TableColumn) => {
    if (value === null || value === '') {
      return <span className="text-gray-400">NULL</span>;
    }
    
    if (column.extra === 'auto_increment') {
      return <Badge variant="secondary">自增</Badge>;
    }
    
    if (column.key === 'PRI') {
      return <Badge variant="primary">主键</Badge>;
    }
    
    if (column.key === 'UNI') {
      return <Badge variant="outline">唯一</Badge>;
    }
    
    if (column.key === 'MUL') {
      return <Badge variant="secondary">索引</Badge>;
    }
    
    return String(value);
  };

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold">表数据编辑器</h1>
          <p className="text-gray-600">{database}.{table}</p>
        </div>
        <div className="flex gap-2">
          <Button onClick={() => setIsInsertModalOpen(true)}>
            添加行
          </Button>
          <Button variant="outline" onClick={() => navigate('/db/browser')}>
            返回
          </Button>
        </div>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>数据浏览</CardTitle>
        </CardHeader>
        <CardContent>
          {loading ? (
            <div className="flex justify-center py-8">
              <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-gray-900"></div>
            </div>
          ) : (
            <>
              <div className="overflow-x-auto">
                <Table>
                  <TableHeader>
                    <TableRow>
                      {columns.map((column) => (
                        <TableHead 
                          key={column.name}
                          className="cursor-pointer hover:bg-gray-50"
                          onClick={() => handleSort(column.name)}
                        >
                          <div className="flex items-center gap-2">
                            {column.name}
                            {sortField === column.name && (
                              <span>{sortOrder === 'ASC' ? '↑' : '↓'}</span>
                            )}
                          </div>
                        </TableHead>
                      ))}
                      <TableHead className="w-32">操作</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {data.map((row, index) => (
                      <TableRow key={index}>
                        {columns.map((column) => (
                          <TableCell key={column.name}>
                            {renderCellContent(row[column.name], column)}
                          </TableCell>
                        ))}
                        <TableCell>
                          <div className="flex gap-1">
                            <Button 
                              size="sm" 
                              variant="outline"
                              onClick={() => handleEditRow(row)}
                            >
                              编辑
                            </Button>
                            <Button 
                              size="sm" 
                              variant="destructive"
                              onClick={() => handleDeleteRow(row)}
                            >
                              删除
                            </Button>
                          </div>
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </div>

              {data.length === 0 && (
                <div className="text-center py-8 text-gray-500">
                  表中没有数据
                </div>
              )}

              <div className="flex items-center justify-between mt-4">
                <div className="text-sm text-gray-600">
                  显示 {((pagination.page - 1) * pagination.limit) + 1} - {Math.min(pagination.page * pagination.limit, pagination.total)} 条，共 {pagination.total} 条
                </div>
                <div className="flex gap-2">
                  <Button 
                    variant="outline" 
                    disabled={pagination.page === 1}
                    onClick={() => setPagination(prev => ({ ...prev, page: prev.page - 1 }))}
                  >
                    上一页
                  </Button>
                  <Button 
                    variant="outline" 
                    disabled={pagination.page === pagination.totalPages}
                    onClick={() => setPagination(prev => ({ ...prev, page: prev.page + 1 }))}
                  >
                    下一页
                  </Button>
                </div>
              </div>
            </>
          )}
        </CardContent>
      </Card>

      <Modal open={isInsertModalOpen} onOpenChange={setIsInsertModalOpen}>
        <ModalContent className="max-w-2xl">
          <ModalHeader>
            <ModalTitle>添加新行</ModalTitle>
          </ModalHeader>
          <div className="space-y-4 max-h-96 overflow-y-auto">
            {columns.map((column) => (
              <div key={column.name}>
                <label className="text-sm font-medium">{column.name}</label>
                <Input
                  value={newRow[column.name] || ''}
                  onChange={(e) => handleInputChange(column.name, e.target.value, 'insert')}
                  placeholder={`输入 ${column.name} 的值`}
                />
                {formErrors[column.name] && (
                  <p className="text-red-500 text-sm">{formErrors[column.name]}</p>
                )}
              </div>
            ))}
          </div>
          <div className="flex justify-end gap-2 mt-4">
            <Button variant="outline" onClick={() => setIsInsertModalOpen(false)}>
              取消
            </Button>
            <Button onClick={handleInsertRow}>
              插入
            </Button>
          </div>
        </ModalContent>
      </Modal>

      <Modal open={isEditModalOpen} onOpenChange={setIsEditModalOpen}>
        <ModalContent className="max-w-2xl">
          <ModalHeader>
            <ModalTitle>编辑行</ModalTitle>
          </ModalHeader>
          <div className="space-y-4 max-h-96 overflow-y-auto">
            {columns.map((column) => (
              <div key={column.name}>
                <label className="text-sm font-medium">{column.name}</label>
                <Input
                  value={editingRow?.[column.name] || ''}
                  onChange={(e) => handleInputChange(column.name, e.target.value)}
                  placeholder={`输入 ${column.name} 的值`}
                />
                {formErrors[column.name] && (
                  <p className="text-red-500 text-sm">{formErrors[column.name]}</p>
                )}
              </div>
            ))}
          </div>
          <div className="flex justify-end gap-2 mt-4">
            <Button variant="outline" onClick={() => setIsEditModalOpen(false)}>
              取消
            </Button>
            <Button onClick={handleUpdateRow}>
              更新
            </Button>
          </div>
        </ModalContent>
      </Modal>
    </div>
  );
}