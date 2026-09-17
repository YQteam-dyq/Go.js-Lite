import type { FileEntry } from '@shared/types'

export interface FileListPayload {
  files: FileEntry[]
  path: string
}

export function parentPath(path: string): string {
  const trimmed = path.replace(/\/+$/, '')
  const slash = trimmed.lastIndexOf('/')
  return slash <= 0 ? '/' : trimmed.slice(0, slash)
}

export function joinPath(parent: string, name: string): string {
  if (parent === '' || parent === '/') return '/' + name
  return parent.replace(/\/+$/, '') + '/' + name
}

export function removeEntries(
  data: FileListPayload | undefined,
  paths: readonly string[],
): FileListPayload | undefined {
  if (!data || !Array.isArray(data.files) || paths.length === 0) return data
  const dropped = new Set(paths)
  const files = data.files.filter((entry) => !dropped.has(entry.path))
  if (files.length === data.files.length) return data
  return { ...data, files }
}

export function renameEntry(
  data: FileListPayload | undefined,
  path: string,
  name: string,
): FileListPayload | undefined {
  if (!data || !Array.isArray(data.files)) return data
  const nextPath = joinPath(parentPath(path), name)
  let changed = false
  const files = data.files.map((entry) => {
    if (entry.path !== path) return entry
    changed = true
    return { ...entry, name, path: nextPath }
  })
  return changed ? { ...data, files } : data
}

export function patchEntry(
  data: FileListPayload | undefined,
  path: string,
  patch: Partial<FileEntry>,
): FileListPayload | undefined {
  if (!data || !Array.isArray(data.files)) return data
  let changed = false
  const files = data.files.map((entry) => {
    if (entry.path !== path) return entry
    changed = true
    return { ...entry, ...patch }
  })
  return changed ? { ...data, files } : data
}
