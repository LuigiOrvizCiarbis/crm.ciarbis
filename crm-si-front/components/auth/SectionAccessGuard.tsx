"use client"

import Link from "next/link"
import { LockKeyhole, LogOut, User } from "lucide-react"
import { Button } from "@/components/ui/button"
import { usePathname, useRouter } from "next/navigation"
import { useAuthStore } from "@/store/useAuthStore"
import { firstAccessibleSection, sectionForPath, canAccessSection } from "@/lib/section-access"

export function SectionAccessGuard({ children }: { children: React.ReactNode }) {
  const pathname = usePathname()
  const permissions = useAuthStore((state) => state.permissions)
  const role = useAuthStore((state) => state.role)
  const item = sectionForPath(pathname)

  if (!item || canAccessSection(item.key, permissions, role)) return <>{children}</>

  return <AccessDenied />
}

export function AccessDenied() {
  const router = useRouter()
  const permissions = useAuthStore((state) => state.permissions)
  const role = useAuthStore((state) => state.role)
  const logout = useAuthStore((state) => state.logout)
  const fallback = firstAccessibleSection(permissions, role)
  return (
    <main className="flex min-h-[60vh] items-center justify-center p-6">
      <div className="max-w-md space-y-5 text-center">
        <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-muted"><LockKeyhole className="h-6 w-6" /></div>
        <div><h1 className="text-xl font-semibold">Sin acceso</h1><p className="mt-2 text-sm text-muted-foreground">No tenés permiso para acceder a esta sección.</p></div>
        <div className="flex justify-center gap-2">
          {fallback ? <Link href={fallback.href}><Button>Ir a {fallback.key === "chats" ? "Chats" : "una sección permitida"}</Button></Link> : null}
          <Link href="/perfil"><Button variant="outline"><User className="mr-2 h-4 w-4" />Perfil</Button></Link>
          <Button variant="ghost" onClick={() => { logout(); router.replace("/login") }}><LogOut className="mr-2 h-4 w-4" />Salir</Button>
        </div>
      </div>
    </main>
  )
}
