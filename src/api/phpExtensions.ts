import { apiFetch } from './client'

export interface PhpExtension {
  name: string
  version: string | null
  zend: boolean
  favorite: boolean
}

export interface PhpExtensionsResponse {
  count: number
  zend_count: number
  favorites: string[]
  extensions: PhpExtension[]
}

export interface FavoriteResponse {
  name: string
  favorite: boolean
  favorites: string[]
}

export const phpExtensionsApi = {
  list: () => apiFetch<PhpExtensionsResponse>('/php/extensions'),
  favorite: (name: string, favorite?: boolean) =>
    apiFetch<FavoriteResponse>('/php/extensions/favorite', {
      method: 'POST',
      body: { name, favorite },
    }),
}
