export function validateFileName(name: string): { valid: boolean; error?: string } {
  if (!name || !name.trim()) {
    return { valid: false, error: 'files.nameRequired' }
  }
  const trimmed = name.trim()
  if (/[\\/:*?"<>|]/.test(trimmed)) {
    return { valid: false, error: 'files.nameInvalid' }
  }
  if (trimmed === '.' || trimmed === '..') {
    return { valid: false, error: 'files.nameInvalid' }
  }
  return { valid: true }
}

export function formatFileSize(bytes: number): string {
  if (bytes === 0) return '0 B'
  const k = 1024
  const sizes = ['B', 'KB', 'MB', 'GB', 'TB']
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i]
}
