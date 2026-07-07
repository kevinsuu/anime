import { useRoute } from 'vue-router'

export interface NavItem {
  label: string
  to: string
  /** Lucide icon name, used by the mobile bottom nav. */
  icon: string
  /** Route requires auth — unauthenticated users are sent to /login instead. */
  protected: boolean
}

// Single source of truth for the app's primary navigation. Both the desktop
// header and the mobile bottom nav render from this list, so adding or
// reordering a destination only needs one edit here.
export const NAV_ITEMS: NavItem[] = [
  { label: '新番總覽', to: '/', icon: 'i-lucide-home', protected: false },
  { label: '資料庫', to: '/catalog', icon: 'i-lucide-search', protected: false },
  { label: '我的清單', to: '/list', icon: 'i-lucide-library', protected: true },
  { label: '設定', to: '/settings', icon: 'i-lucide-settings', protected: true },
]

export function useNav() {
  const route = useRoute()
  const { isAuthed } = useSession()

  function isActive(path: string): boolean {
    // /list has sub-routes; '/' is the redirect landing for /seasonal.
    if (path === '/list') return route.path.startsWith('/list')
    if (path === '/') return route.path === '/' || route.path === '/seasonal'
    return route.path === path
  }

  // Protected destinations bounce guests to /login until they authenticate.
  function targetFor(item: NavItem): string {
    return item.protected && !isAuthed.value ? '/login' : item.to
  }

  return { navItems: NAV_ITEMS, isActive, targetFor }
}
