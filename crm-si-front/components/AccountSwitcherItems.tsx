"use client"

import { useRouter } from "next/navigation"
import { UserPlus } from "lucide-react"
import { Avatar, AvatarFallback, AvatarImage } from "@/components/ui/avatar"
import { DropdownMenuItem, DropdownMenuLabel } from "@/components/ui/dropdown-menu"
import { useTranslation } from "@/hooks/useTranslation"
import { useAuthStore, type User } from "@/store/useAuthStore"
import { ADD_ACCOUNT_PATH, accountInitials, switchToAccount } from "@/lib/account-session"

/** Cuentas inactivas guardadas en el navegador, de la usada más recientemente a la más vieja. */
export function useSavedAccounts() {
  const savedAccounts = useAuthStore((state) => state.savedAccounts)
  return [...savedAccounts].sort((a, b) => b.lastUsedAt - a.lastUsedAt)
}

export function SavedAccountSummary({ user }: { user: User }) {
  return (
    <>
      <Avatar className="h-7 w-7">
        <AvatarImage src={user.avatar_url ?? undefined} alt={user.name ?? ""} />
        <AvatarFallback className="bg-primary/10 text-primary text-xs font-medium">
          {accountInitials(user)}
        </AvatarFallback>
      </Avatar>
      <span className="min-w-0 flex-1 text-left">
        <span className="block truncate text-sm font-medium">{user.name}</span>
        <span className="block truncate text-xs text-muted-foreground">{user.email}</span>
      </span>
    </>
  )
}

/** Entradas para cambiar o agregar cuentas, dentro de un DropdownMenuContent existente. */
export function AccountSwitcherItems() {
  const router = useRouter()
  const { t } = useTranslation()
  const savedAccounts = useSavedAccounts()

  return (
    <>
      {savedAccounts.length > 0 ? (
        <>
          <DropdownMenuLabel className="text-xs font-medium text-muted-foreground">
            {t("nav.otherAccounts")}
          </DropdownMenuLabel>
          {savedAccounts.map((account) => (
            <DropdownMenuItem
              key={account.user.id}
              onSelect={() => void switchToAccount(account.user.id)}
              className="gap-3 py-2"
            >
              <SavedAccountSummary user={account.user} />
            </DropdownMenuItem>
          ))}
        </>
      ) : null}
      <DropdownMenuItem onSelect={() => router.push(ADD_ACCOUNT_PATH)}>
        <UserPlus className="mr-2 h-4 w-4" />
        {t("nav.addAccount")}
      </DropdownMenuItem>
    </>
  )
}
