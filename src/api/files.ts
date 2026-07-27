import { apiFetch } from './client'
import { mockApi } from './mock'
import type {
  FileEntry,
  FileContent,
  FileOperationRequest,
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

  deleteFile(path: string): Promise<void> {
    if (USE_MOCK) return mockApi.deleteFile(path)
    return apiFetch<void>('/files', {
      method: 'POST',
      body: { action: 'delete', path },
    })
  },

  deleteFiles(paths: string[]): Promise<void> {
    if (USE_MOCK) return mockApi.deleteFiles(paths)
    return Promise.all(paths.map((p) => this.deleteFile(p))).then(() => undefined)
  },

  renameFile(path: string, newName: string): Promise<FileEntry> {
    if (USE_MOCK) return mockApi.renameFile(path, newName)
    return apiFetch<FileEntry>('/files', {
      method: 'POST',
      body: { action: 'rename', path, target: newName },
    })
  },

  uploadFile(dir: string, file: { name: string; size: number }): Promise<FileEntry> {
    if (USE_MOCK) return mockApi.uploadFile(dir, file)
    return apiFetch<FileEntry>('/upload', {
      method: 'POST',
      body: { path: dir, name: file.name, size: file.size },
    })
  },

  simulateUploadProgress(
    onProgress: (percent: number) => void,
    totalDuration?: number,
  ): Promise<void> {
    return mockApi.simulateUploadProgress(onProgress, totalDuration)
  },

  operation(data: FileOperationRequest) {
    if (USE_MOCK) return mockApi.fileOperation(data)
    return apiFetch<void>('/files', {
      method: 'POST',
      body: data,
    })
  },

  download(path: string, asZip = false) {
    return apiFetch<Blob>('/download', {
      params: { path, zip: asZip ? 1 : 0 },
      responseType: 'blob',
    })
  },

  uploadChunk(
    path: string,
    filename: string,
    chunk: number,
    totalChunks: number,
    size: number,
    blob: Blob,
  ) {
    const formData = new FormData()
    formData.append('path', path)
    formData.append('filename', filename)
    formData.append('chunk', String(chunk))
    formData.append('totalChunks', String(totalChunks))
    formData.append('size', String(size))
    formData.append('file', blob)

    return apiFetch<{ completed: boolean; receivedChunks: number[] }>('/upload-chunk', {
      method: 'POST',
      body: formData,
      json: false,
    })
  },
}
