import { apiFetch } from './client'
import { mockApi } from './mock'
import type {
  DbConnection,
  DbConnectionInput,
  DbTable,
  DbColumn,
  SqlExecResponse,
} from '@shared/types'

const USE_MOCK = false

export type DbRow = Record<string, unknown>

export interface DbPagination {
  page: number
  limit: number
  total: number
  totalPages: number
}

export interface DbTableDataResponse {
  success: boolean
  data: DbRow[]
  pagination: DbPagination
}

export interface DbInsertResponse {
  success: boolean
  insertId: number | string | null
  sql?: string
}

export interface DbMutationResponse {
  success: boolean
  affectedRows: number
  sql?: string
}

export interface DbQueryResult {
  success: boolean
  data: DbRow[]
  sql: string
  count: number
}

export interface DbStatementResponse {
  success: boolean
  sql: string
}

export interface DbTableDataInput {
  connId: string
  database: string
  table: string
  page?: number
  limit?: number
  sortField?: string
  sortOrder?: 'ASC' | 'DESC'
}

export interface DbRowKeyInput {
  connId: string
  database: string
  table: string
  primaryKey: string
  primaryKeyValue: string | number
}

export interface DbColumnDefinition {
  name: string
  type: string
  length?: string
  unsigned?: boolean
  values?: string[]
  nullable?: string | boolean
  default?: string | number | null
  auto_increment?: boolean
  primary_key?: boolean
  comment?: string
  after?: string
}

export interface DbQueryCondition {
  field: string
  operator: string
  value?: string | number | boolean | null | Array<string | number>
}

export interface DbQueryOrder {
  field: string
  direction: 'ASC' | 'DESC'
}

export interface DbQueryInput {
  connId: string
  database: string
  table: string
  columns?: string[]
  conditions?: DbQueryCondition[]
  groupBy?: string[]
  orderBy?: DbQueryOrder[]
  limit?: number
  offset?: number
}

export interface DbExportEnhancedInput {
  connId: string
  database: string
  tables: string[]
  format: 'sql' | 'json' | 'csv' | 'xml'
  compression?: 'none' | 'gzip' | 'zip'
  includeStructure?: boolean
  includeData?: boolean
  whereClause?: string
}

export const dbApi = {
  listConnections() {
    if (USE_MOCK) return mockApi.listDbConnections()
    return apiFetch<DbConnection[]>('/db/connections')
  },

  addConnection(data: DbConnectionInput) {
    if (USE_MOCK) return mockApi.addDbConnection(data as unknown as DbConnection)
    return apiFetch<DbConnection>('/db/connections', {
      method: 'POST',
      body: data,
    })
  },

  updateConnection(id: string, data: Partial<DbConnectionInput>) {
    if (USE_MOCK) return mockApi.updateDbConnection()
    return apiFetch<DbConnection>(`/db/connections/${id}`, {
      method: 'PUT',
      body: data,
    })
  },

  deleteConnection(id: string) {
    if (USE_MOCK) return mockApi.deleteDbConnection()
    return apiFetch<void>(`/db/connections/${id}`, {
      method: 'DELETE',
    })
  },

  listDatabases(connId: string) {
    if (USE_MOCK) return mockApi.listDatabases()
    return apiFetch<string[]>(`/db/databases`, {
      params: { connId },
    })
  },

  listTables(connId: string, database: string) {
    if (USE_MOCK) return mockApi.listTables()
    return apiFetch<DbTable[]>(`/db/tables`, {
      params: { connId, database },
    })
  },

  getStructure(connId: string, database: string, table: string) {
    if (USE_MOCK) return mockApi.getStructure()
    return apiFetch<DbColumn[]>(`/db/structure`, {
      params: { connId, database, table },
    })
  },

  execSql(connId: string, database: string, sql: string) {
    if (USE_MOCK) return mockApi.execSql(sql)
    return apiFetch<SqlExecResponse>('/db/sql', {
      method: 'POST',
      body: { connId, database, sql },
    })
  },

  exportDatabase(connId: string, database: string, tables?: string[], mode: 'structure_only' | 'structure_data' = 'structure_data') {
    if (USE_MOCK) return Promise.resolve(new Blob()) as unknown as Promise<Blob>
    const params: Record<string, string | string[] | undefined> = { connId, database, mode }
    if (tables && tables.length > 0) {
      params.tables = tables
    }
    return apiFetch<Blob>('/db/export', {
      method: 'POST',
      body: params,
      responseType: 'blob',
    })
  },

  importDatabase(connId: string, database: string, file: File, allowDangerous = false) {
    if (USE_MOCK) return Promise.resolve({ success: true }) as unknown as Promise<{ executed: number; failed: number; errors?: string[] }>
    const formData = new FormData()
    formData.append('connId', connId)
    formData.append('database', database)
    formData.append('file', file)
    if (allowDangerous) {
      formData.append('allowDangerous', 'true')
    }
    return apiFetch<{ executed: number; failed: number; errors?: string[] }>('/db/import', {
      method: 'POST',
      body: formData,
    })
  },

  tableData(input: DbTableDataInput) {
    if (USE_MOCK) {
      return Promise.resolve({
        success: true,
        data: [],
        pagination: { page: 1, limit: 50, total: 0, totalPages: 1 },
      }) as unknown as Promise<DbTableDataResponse>
    }
    return apiFetch<DbTableDataResponse>('/db/table/data', {
      params: {
        connId: input.connId,
        database: input.database,
        table: input.table,
        page: input.page,
        limit: input.limit,
        sortField: input.sortField ? input.sortField : undefined,
        sortOrder: input.sortOrder,
      },
    })
  },

  insertRow(input: { connId: string; database: string; table: string; data: DbRow }) {
    if (USE_MOCK) {
      return Promise.resolve({ success: true, insertId: null }) as unknown as Promise<DbInsertResponse>
    }
    return apiFetch<DbInsertResponse>('/db/table/insert', {
      method: 'POST',
      body: input,
    })
  },

  updateRow(input: DbRowKeyInput & { data: DbRow }) {
    if (USE_MOCK) {
      return Promise.resolve({ success: true, affectedRows: 0 }) as unknown as Promise<DbMutationResponse>
    }
    return apiFetch<DbMutationResponse>('/db/table/update', {
      method: 'POST',
      body: input,
    })
  },

  deleteRow(input: DbRowKeyInput) {
    if (USE_MOCK) {
      return Promise.resolve({ success: true, affectedRows: 0 }) as unknown as Promise<DbMutationResponse>
    }
    return apiFetch<DbMutationResponse>('/db/table/delete', {
      method: 'POST',
      body: input,
    })
  },

  createTable(input: {
    connId: string
    database: string
    tableName: string
    columns: DbColumnDefinition[]
    engine?: string
    charset?: string
    comment?: string
  }) {
    return apiFetch<DbStatementResponse>('/db/table/create', {
      method: 'POST',
      body: input,
    })
  },

  alterTable(input: {
    connId: string
    database: string
    tableName: string
    action: 'ADD' | 'DROP' | 'MODIFY'
    column: DbColumnDefinition
  }) {
    return apiFetch<DbStatementResponse>('/db/table/alter', {
      method: 'POST',
      body: input,
    })
  },

  createIndex(input: {
    connId: string
    database: string
    tableName: string
    indexName: string
    indexType: string
    columns: string[]
  }) {
    return apiFetch<DbStatementResponse>('/db/table/create-index', {
      method: 'POST',
      body: input,
    })
  },

  dropIndex(input: { connId: string; database: string; tableName: string; indexName: string }) {
    return apiFetch<DbStatementResponse>('/db/table/drop-index', {
      method: 'POST',
      body: input,
    })
  },

  queryBuilder(input: DbQueryInput) {
    return apiFetch<DbQueryResult>('/db/query/builder', {
      method: 'POST',
      body: input,
    })
  },

  queryPreview(input: DbQueryInput) {
    return apiFetch<DbQueryResult>('/db/query/preview', {
      method: 'POST',
      body: input,
    })
  },

  exportEnhanced(input: DbExportEnhancedInput) {
    if (USE_MOCK) {
      return Promise.resolve(new Blob()) as unknown as Promise<Blob>
    }
    return apiFetch<Blob>('/db/export/enhanced', {
      method: 'POST',
      body: input,
      responseType: 'blob',
    })
  },
}
