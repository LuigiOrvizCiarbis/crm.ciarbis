import { NAVIGATION_ITEMS, type NavigationItem, type NavigationKey } from "@/data/navigation"
import type { UserRole } from "@/store/useAuthStore"

export const SECTION_PERMISSION_PREFIX = "sections."

export function sectionPermission(key: NavigationKey): string {
  return `${SECTION_PERMISSION_PREFIX}${key}`
}

export function canAccessSection(
  key: NavigationKey,
  permissions: string[] | null | undefined,
  role: UserRole | null | undefined,
): boolean {
  return role?.is_owner === true || permissions?.includes(sectionPermission(key)) === true
}

export function accessibleNavigationItems(
  permissions: string[] | null | undefined,
  role: UserRole | null | undefined,
): readonly NavigationItem[] {
  return NAVIGATION_ITEMS.filter((item) => canAccessSection(item.key, permissions, role))
}

export function sectionForPath(pathname: string): NavigationItem | undefined {
  return NAVIGATION_ITEMS.find((item) => pathname === item.href || pathname.startsWith(`${item.href}/`))
}

export function firstAccessibleSection(
  permissions: string[] | null | undefined,
  role: UserRole | null | undefined,
): NavigationItem | undefined {
  const items = accessibleNavigationItems(permissions, role)
  return items.find((item) => item.key === "chats") ?? items[0]
}
