"use client"

import Link from "next/link"
import { useRouter } from "next/navigation"
import { Clock, LogOut } from "lucide-react"
import { useAuthStore } from "@/store/useAuthStore"
import { useTranslation } from "@/hooks/useTranslation"
import { Button } from "@/components/ui/button"
import {
  Card,
  CardContent,
  CardDescription,
  CardFooter,
  CardHeader,
  CardTitle,
} from "@/components/ui/card"

export default function TrialExpiredPage() {
  const router = useRouter()
  const { token, logout } = useAuthStore()
  const { t } = useTranslation()

  const handleLogout = async () => {
    try {
      await fetch("/api/auth/logout", {
        method: "POST",
        headers: { Authorization: `Bearer ${token}` },
      })
    } catch {
      // Ignorar errores de red, cerrar sesión de todos modos
    }

    logout()
    router.replace("/login")
  }

  return (
    <div className="min-h-screen overflow-x-hidden flex items-center justify-center bg-linear-to-br from-background via-background to-muted/30 p-4">
      <div className="relative z-10 w-full max-w-md min-w-0">
        <Card className="border-border/50 shadow-xl">
          <CardHeader className="text-center space-y-4">
            <div className="mx-auto w-16 h-16 bg-primary/10 rounded-full flex items-center justify-center">
              <Clock className="w-8 h-8 text-primary" />
            </div>
            <CardTitle>{t("trial.expired.title")}</CardTitle>
            <CardDescription>{t("trial.expired.description")}</CardDescription>
          </CardHeader>
          <CardContent />
          <CardFooter className="flex flex-col gap-2">
            <Link href="/pricing" className="w-full">
              <Button className="w-full">{t("trial.expired.viewPlans")}</Button>
            </Link>
            <Link href="/perfil#security" className="w-full">
              <Button variant="outline" className="w-full">
                {t("trial.expired.securitySettings")}
              </Button>
            </Link>
            <Button variant="ghost" className="w-full gap-2" onClick={handleLogout}>
              <LogOut className="w-4 h-4" />
              {t("trial.expired.logout")}
            </Button>
          </CardFooter>
        </Card>
      </div>
    </div>
  )
}
