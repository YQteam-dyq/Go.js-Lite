import { apiFetch, getCsrfToken, ApiError } from './client'
import { mockApi } from './mock'
import type {
  FileEntry,
  FileContent,
  FileOperationRequest,
  UploadProgress,
  UploadResult,
  UploadChunkRequest,
  UploadChunkResponse,
  SearchResult,
} from '@shared/types'

const USE_MOCK = false

export const filesApi = {
  list(path: string, sort = 'name', order: 'asc' | 'desc' = 'asc') {
    if (USE_MOCK) {
      return mockApi.listFiles(path).then((files) => {
        let sorted = [...files]
        if (sort === 'name') {
          sorted.sort((a, b) => {
            const dirCompare = (b.type === 'dir' ? 1 : 0) - (a.type === 'dir' ? 1 : 0)
            if (dirCompare !== 0) return dirCompare
            return order === 'asc'
              ? a.name.localeCompare(b.name)
              : b.name.localeCompare(a.name)
          })
        } else if (sort === 'size') {
          sorted.sort((a, b) =>
            order === 'asc' ? a.size - b.size : b.size - a.size,
          )
        } else if (sort === 'mtime') {
          sorted.sort((a, b) =>
            order === 'asc' ? a.mtime - b.mtime : b.mtime - a.mtime,
          )
        }
        return { files: sorted, path }
      })
    }
    return apiFetch<{ files: FileEntry[]; path: string }>('/files', {
      params: { path, sort, order },
    })
  },

  getContent(path: string) {
    if (USE_MOCK) return mockApi.getFileContent(path)
    return apiFetch<FileContent>('/file-content', {
      params: { path },
    })
  },

  saveContent(path: string, content: string) {
    if (USE_MOCK) return mockApi.saveFile() as unknown as Promise<void>
    return apiFetch<void>('/file-content', {
      method: 'PUT',
      params: { path },
      body: { content },
    })
  },

  createFile(dir: string, name: string): Promise<FileEntry> {
    if (USE_MOCK) return mockApi.createFile(dir, name)
    return apiFetch<FileEntry>('/files', {
      method: 'POST',
      body: { action: 'create_file', path: (dir === '/' ? '' : dir) + '/' + name },
    })
  },

  createDir(dir: string, name: string): Promise<FileEntry> {
    if (USE_MOCK) return mockApi.createDir(dir, name)
    return apiFetch<FileEntry>('/files', {
      method: 'POST',
      body: { action: 'create_dir', path: (dir === '/' ? '' : dir) + '/' + name },
    })
  },

  deleteFile(path: string): Promise<{ success: boolean; trashed?: boolean }> {
    if (USE_MOCK) return mockApi.deleteFile(path).then(() => ({ success: true, trashed: true }))
    return apiFetch<{ success: boolean; trashed?: boolean }>('/files', {
      method: 'POST',
      body: { action: 'delete', path },
    })
  },

  deleteFiles(paths: string[]): Promise<{ trashed: boolean }> {
    if (USE_MOCK) return mockApi.deleteFiles(paths).then(() => ({ trashed: true }))
    return Promise.all(paths.map((p) => this.deleteFile(p))).then((results) => ({
      trashed: results.some((r) => r.trashed),
    }))
  },

  renameFile(path: string, newName: string): Promise<FileEntry> {
    if (USE_MOCK) return mockApi.renameFile(path, newName)
    return apiFetch<FileEntry>('/files', {
      method: 'POST',
      body: { action: 'rename', path, target: newName },
    })
  },

  uploadFile(
    dir: string,
    file: File,
    onProgress?: (progress: UploadProgress) => void,
    signal?: AbortSignal,
  ): Promise<UploadResult> {
    if (USE_MOCK) {
      return mockApi
        .uploadFile(dir, { name: file.name, size: file.size })
        .then(
          () =>
            ({
              success: true,
              files: [{ name: file.name, size: file.size }],
            }) as UploadResult,
        )
    }

    return new Promise<UploadResult>((resolve, reject) => {
      const xhr = new XMLHttpRequest()
      const formData = new FormData()
      formData.append('target', dir)
      formData.append('files', file, file.name)

      xhr.open('POST', '/api/upload')
      xhr.withCredentials = true

      const csrf = getCsrfToken()
      if (csrf) {
        xhr.setRequestHeader('X-CSRF-Token', csrf)
      }

      xhr.upload.onprogress = (e: ProgressEvent) => {
        if (e.lengthComputable && onProgress) {
          onProgress({
            loaded: e.loaded,
            total: e.total,
            percentage: e.total > 0 ? Math.round((e.loaded / e.total) * 100) : 0,
          })
        }
      }

      xhr.onload = () => {
        let data: {
          ok?: boolean
          data?: UploadResult
          error?: { code: string; message: string }
        } | null = null
        try {
          data = JSON.parse(xhr.responseText)
        } catch {
          data = null
        }

        if (xhr.status === 401) {
          window.dispatchEvent(new CustomEvent('auth:expired'))
          reject(new ApiError('unauthorized', '登录已过期', xhr.status))
          return
        }
        if (xhr.status === 404) {
          if (data?.error?.code === 'not_found') {
            window.dispatchEvent(new CustomEvent('access:denied'))
          }
          reject(
            new ApiError(
              data?.error?.code || 'not_found',
              data?.error?.message || 'Not Found',
              xhr.status,
            ),
          )
          return
        }
        if (xhr.status === 403) {
          reject(
            new ApiError(
              data?.error?.code || 'forbidden',
              data?.error?.message || '权限不足',
              xhr.status,
            ),
          )
          return
        }
        if (xhr.status === 429) {
          reject(new ApiError('rate_limited', '请求过于频繁，请稍后再试', xhr.status))
          return
        }
        if (xhr.status >= 500) {
          reject(
            new ApiError(
              'server_error',
              data?.error?.message || '服务器错误',
              xhr.status,
            ),
          )
          return
        }
        if (xhr.status >= 400 || !data || !data.ok) {
          reject(
            new ApiError(
              data?.error?.code || 'upload_failed',
              data?.error?.message || '上传失败',
              xhr.status,
            ),
          )
          return
        }
        resolve(data.data as UploadResult)
      }

      xhr.onerror = () => {
        reject(new ApiError('network_error', '网络错误，上传失败', 0))
      }
      xhr.onabort = () => {
        reject(new ApiError('aborted', '上传已取消', 0))
      }
      xhr.ontimeout = () => {
        reject(new ApiError('timeout', '上传超时', 0))
      }

      if (signal) {
        if (signal.aborted) {
          xhr.abort()
          return
        }
        signal.addEventListener('abort', () => xhr.abort())
      }

      xhr.send(formData)
    })
  },

  operation(data: FileOperationRequest) {
    if (USE_MOCK) return mockApi.fileOperation(data)
    return apiFetch<void>('/files', {
      method: 'POST',
      body: data,
    })
  },

  async download(path: string): Promise<void> {
    const blob = await apiFetch<Blob>('/download', {
      params: { path },
      responseType: 'blob',
    })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = path.split('/').filter(Boolean).pop() || 'download'
    document.body.appendChild(a)
    a.click()
    document.body.removeChild(a)
    URL.revokeObjectURL(url)
  },

  copy(path: string, target: string): Promise<void> {
    return apiFetch<void>('/file-copy', {
      method: 'POST',
      body: { path, target },
    })
  },

  chmod(path: string, mode: string): Promise<void> {
    return apiFetch<void>('/file-chmod', {
      method: 'POST',
      body: { path, perms: mode },
    })
  },

  search(path: string, q: string): Promise<SearchResult> {
    if (USE_MOCK) {
      return mockApi.listFiles(path).then((files) => {
        const lower = q.toLowerCase()
        const matched = files.filter((f) => f.name.toLowerCase().includes(lower))
        return { files: matched, total: matched.length }
      })
    }
    return apiFetch<SearchResult>('/file-search', {
      params: { path, q },
    })
  },

  uploadChunk(params: UploadChunkRequest): Promise<UploadChunkResponse> {
    if (USE_MOCK) {
      const received = params.chunkIndex + 1
      return Promise.resolve({
        success: true,
        merged: received >= params.totalChunks,
        progress: received + '/' + params.totalChunks,
        received,
        totalChunks: params.totalChunks,
      })
    }
    return apiFetch<UploadChunkResponse>('/upload-chunk', {
      method: 'POST',
      body: params as unknown as Record<string, unknown>,
    })
  },

  zip(paths: string[], target: string) {
    return apiFetch<{ success: boolean; target: string }>('/file-zip', {
      method: 'POST',
      body: { paths, target },
    })
  },

  unzip(path: string, target: string) {
    return apiFetch<{ success: boolean; extracted: number }>('/file-unzip', {
      method: 'POST',
      body: { path, target },
    })
  },

  targz(paths: string[], target: string) {
    return apiFetch<{ success: boolean; target: string }>('/file-targz', {
      method: 'POST',
      body: { paths, target },
    })
  },

  untargz(path: string, target: string) {
    return apiFetch<{ success: boolean; extracted: number }>('/file-untargz', {
      method: 'POST',
      body: { path, target },
    })
  },
}
