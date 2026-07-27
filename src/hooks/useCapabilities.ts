import { useAuthStore } from '@/stores/authStore'
import { getDefaultCaps } from '@/stores/authStore'

export function useCapabilities() {
  const caps = useAuthStore((s) => s.capabilities)
  return caps || getDefaultCaps()
}
