"use client"

import { useState } from "react"
import { useRouter, useSearchParams } from "next/navigation"
import Link from "next/link"
import { Eye, EyeOff, Loader2, MessageSquare } from "lucide-react"
import { useTranslation } from "@/hooks/useTranslation"

import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Checkbox } from "@/components/ui/checkbox"
import {
  Card,
  CardContent,
  CardDescription,
  CardFooter,
  CardHeader,
  CardTitle,
} from "@/components/ui/card"
import { useAuthStore } from "@/store/useAuthStore"
import { LanguageSwitcher } from "@/components/LanguageSwitcher"
import { clearWorkspaceId, setWorkspaceId } from "@/lib/api/auth-token"
import { accountHomePath } from "@/lib/account-session"

export default function LoginPage() {
  const router = useRouter()
  const searchParams = useSearchParams()
  const redirectTo = searchParams.get("redirect")
  const { setAuth, setLoading, isLoading } = useAuthStore()
  const isAuthenticated = useAuthStore((state) => state.isAuthenticated)
  const activeUser = useAuthStore((state) => state.user)
  const activeEmailVerified = useAuthStore((state) => state.emailVerified)
  const activePermissions = useAuthStore((state) => state.permissions)
  const activeRole = useAuthStore((state) => state.role)
  // Sin sesión activa, ?add=1 se ignora y es un login normal.
  const isAddingAccount = searchParams.get("add") === "1" && isAuthenticated
  const { t } = useTranslation()

  const [email, setEmail] = useState("")
  const [password, setPassword] = useState("")
  const [rememberMe, setRememberMe] = useState(false)
  const [showPassword, setShowPassword] = useState(false)
  const [error, setError] = useState("")

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    setError("")
    setLoading(true)

    try {
      const res = await fetch("/api/auth/login", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ email, password }),
      })

      const data = await res.json()

      if (!res.ok) {
        throw new Error(data.message || t("auth.error"))
      }

      const emailVerified = data.email_verified || !!data.user?.email_verified_at
      // El workspace pertenece a la sesión: nunca reutilizar el de una cuenta
      // anterior que haya quedado en este navegador.
      clearWorkspaceId()
      if (data.user?.tenant_id) setWorkspaceId(data.user.tenant_id)
      setAuth(data.user, rememberMe, emailVerified, data.role ?? null, data.permissions ?? [])

      const destination = redirectTo && redirectTo.startsWith("/")
        ? redirectTo
        : accountHomePath({ emailVerified, permissions: data.permissions ?? [], role: data.role ?? null })
      // Al agregar una cuenta, la anterior sigue viva en memoria (Pusher, stores):
      // la recarga completa arranca todo con la sesión nueva.
      if (isAddingAccount) {
        window.location.assign(destination)
      } else {
        router.push(destination)
      }
    } catch (err: any) {
      setError(err.message || t("auth.error"))
    } finally {
      setLoading(false)
    }
  }

  return (
    <Card className="w-full max-w-full overflow-hidden">
      <CardHeader className="text-center space-y-4">
        <div className="mx-auto w-12 h-12 bg-primary/10 rounded-xl flex items-center justify-center">
          <MessageSquare className="w-6 h-6 text-primary" aria-hidden="true" />
        </div>
        <div>
          <CardTitle className="text-2xl font-bold">
            {isAddingAccount ? t("auth.addAccountTitle") : t("auth.title")}
          </CardTitle>
          <CardDescription className="mt-2">
            {isAddingAccount
              ? t("auth.addAccountSubtitle", { email: activeUser?.email ?? "" })
              : t("auth.subtitle")}
          </CardDescription>
        </div>
        <div className="flex justify-center">
          <LanguageSwitcher />
        </div>
      </CardHeader>

      <form onSubmit={handleSubmit}>
        <CardContent className="space-y-4">
          {error && (
            <div
              id="login-error"
              role="alert"
              className="p-3 text-sm text-destructive bg-destructive/10 border border-destructive/20 rounded-lg"
            >
              {error}
            </div>
          )}

          <div className="space-y-2">
            <Label htmlFor="email">{t("auth.email")}</Label>
            <Input
              id="email"
              type="email"
              placeholder="tu@email.com"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              required
              autoComplete="email"
              disabled={isLoading}
              aria-invalid={error ? true : undefined}
              aria-describedby={error ? "login-error" : undefined}
            />
          </div>

          <div className="space-y-2">
            <Label htmlFor="password">{t("auth.password")}</Label>
            <div className="relative">
              <Input
                id="password"
                type={showPassword ? "text" : "password"}
                placeholder="••••••••"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                required
                autoComplete="current-password"
                disabled={isLoading}
                aria-invalid={error ? true : undefined}
                aria-describedby={error ? "login-error" : undefined}
                className="pr-10"
              />
              <button
                type="button"
                onClick={() => setShowPassword(!showPassword)}
                className="absolute right-1 top-1/2 -translate-y-1/2 flex h-8 w-8 items-center justify-center rounded-md text-muted-foreground hover:text-foreground transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                aria-label={showPassword ? t("auth.hidePassword") : t("auth.showPassword")}
                aria-pressed={showPassword}
                aria-controls="password"
              >
                {showPassword ? (
                  <EyeOff className="w-4 h-4" aria-hidden="true" />
                ) : (
                  <Eye className="w-4 h-4" aria-hidden="true" />
                )}
              </button>
            </div>
          </div>

          <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div className="flex items-center space-x-2">
              <Checkbox
                id="remember"
                checked={rememberMe}
                onCheckedChange={(checked) => setRememberMe(checked as boolean)}
                disabled={isLoading}
              />
              <Label htmlFor="remember" className="text-sm font-normal cursor-pointer">
                {t("auth.rememberMe")}
              </Label>
            </div>
            <Link
              href="/forgot-password"
              className="text-sm text-primary hover:text-primary/80 transition-colors"
            >
              {t("auth.forgotPassword")}
            </Link>
          </div>
        </CardContent>

        <CardFooter className="flex flex-col gap-4">
          <Button type="submit" className="w-full" disabled={isLoading}>
            {isLoading ? (
              <>
                <Loader2 className="w-4 h-4 animate-spin" aria-hidden="true" />
                {t("auth.submitting")}
              </>
            ) : (
              t("auth.submit")
            )}
          </Button>

          {isAddingAccount ? (
            <Link
              href={accountHomePath({ emailVerified: activeEmailVerified, permissions: activePermissions, role: activeRole })}
              className="text-sm text-center text-primary hover:text-primary/80 font-medium transition-colors"
            >
              {t("auth.cancelAddAccount")}
            </Link>
          ) : (
            <p className="text-sm text-center text-muted-foreground">
              {t("auth.noAccount")}{" "}
              <Link
                href="/register"
                className="text-primary hover:text-primary/80 font-medium transition-colors"
              >
                {t("auth.register")}
              </Link>
            </p>
          )}
        </CardFooter>
      </form>

      <p className="text-xs text-center text-muted-foreground mt-4">
        <Link href="/privacy-policy" className="hover:text-foreground transition-colors underline">
          {t("auth.privacyPolicy")}
        </Link>
      </p>
    </Card>
  )
}
