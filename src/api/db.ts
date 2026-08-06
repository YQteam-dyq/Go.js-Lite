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
}
