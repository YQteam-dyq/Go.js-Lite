import { apiFetch } from './client'
import type {
  BackupDestination,
  BackupDestinationS3,
  BackupDestinationFtp,
  BackupDestinationSftp,
} from '@shared/types'

export interface BackupDestinationsListResponse {
  destinations: BackupDestination[]
}

export interface BackupDestinationResponse {
  destination: BackupDestination
}

export type BackupDestinationTestResult = {
  ok: boolean
  error?: string
  error_key?: string
  bucket_writable?: boolean
  prefix?: string
}

export interface S3CreateInput {
  type: 's3'
  name: string
  access_key: string
  secret_key: string
  endpoint?: string
  region?: string
  bucket: string
  path_prefix?: string
  sse?: boolean
}

export interface FtpCreateInput {
  type: 'ftp'
  name: string
  host: string
  port?: number
  username: string
  password: string
  path_prefix?: string
  use_tls?: boolean
}

export interface SftpCreateInput {
  type: 'sftp'
  name: string
  host: string
  port?: number
  username: string
  password?: string
  private_key?: string
  path_prefix?: string
}

export type BackupDestinationCreateInput = S3CreateInput | FtpCreateInput | SftpCreateInput

export type BackupDestinationUpdateInput =
  | (Partial<Omit<BackupDestinationS3, 'id' | 'type' | 'created_at' | 'access_key_enc' | 'secret_key_enc'>> & {
      type: 's3'
      access_key?: string
      secret_key?: string
    })
  | (Partial<Omit<BackupDestinationFtp, 'id' | 'type' | 'created_at' | 'password_enc'>> & {
      type: 'ftp'
      password?: string
    })
  | (Partial<Omit<BackupDestinationSftp, 'id' | 'type' | 'created_at' | 'password_enc' | 'private_key_enc'>> & {
      type: 'sftp'
      password?: string
      private_key?: string
    })

export const backupDestinationsApi = {
  list() {
    return apiFetch<BackupDestinationsListResponse>('/backup/destinations')
  },

  create(data: BackupDestinationCreateInput) {
    return apiFetch<BackupDestinationResponse>('/backup/destinations', {
      method: 'POST',
      body: data,
    })
  },

  update(id: string, patch: BackupDestinationUpdateInput) {
    return apiFetch<BackupDestinationResponse>(`/backup/destinations/${encodeURIComponent(id)}`, {
      method: 'PUT',
      body: patch,
    })
  },

  remove(id: string) {
    return apiFetch<{ success: boolean }>(`/backup/destinations/${encodeURIComponent(id)}`, {
      method: 'DELETE',
    })
  },

  test(destData: BackupDestinationCreateInput & { id?: string }) {
    return apiFetch<BackupDestinationTestResult>('/backup/destinations/test', {
      method: 'POST',
      body: destData,
    })
  },
}
