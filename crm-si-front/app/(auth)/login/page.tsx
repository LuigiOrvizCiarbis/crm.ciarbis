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
import { firstAccessibleSection } from "@/lib/section-access"

export default function LoginPage() {
  const router = useRouter()
  const searchParams = useSearchParams()
  const redirectTo = searchParams.get("redirect")
  const { setAuth, setLoading, isLoading } = useAuthStore()
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
      setAuth(data.user, data.token, rememberMe, emailVerified, data.role ?? null, data.permissions ?? [])

      if (redirectTo && redirectTo.startsWith("/")) {
        router.push(redirectTo)
      } else if (emailVerified) {
        router.push(firstAccessibleSection(data.permissions ?? [], data.role ?? null)?.href ?? "/sin-acceso")
      } else {
        router.push("/verify-email")
      }
    } catch (err: any) {
      setError(err.message || t("auth.error"))
    } finally {
      setLoading(false)
    }
  }

  return (
    <Card className="w-full max-w-full overflow-hidden border-border/50 shadow-xl">
      <CardHeader className="text-center space-y-4">
        <div className="mx-auto w-12 h-12 bg-primary/10 rounded-xl flex items-center justify-center">
          <MessageSquare className="w-6 h-6 text-primary" />
        </div>
        <div>
          <CardTitle className="text-2xl font-bold">{t("auth.title")}</CardTitle>
          <CardDescription className="mt-2">
            {t("auth.subtitle")}
          </CardDescription>
        </div>
        <div className="flex justify-center">
          <LanguageSwitcher />
        </div>
      </CardHeader>

      <form onSubmit={handleSubmit}>
        <CardContent className="space-y-4">
          {error && (
            <div className="p-3 text-sm text-destructive bg-destructive/10 border border-destructive/20 rounded-lg">
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
                className="pr-10"
              />
              <button
                type="button"
                onClick={() => setShowPassword(!showPassword)}
                className="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground transition-colors"
                tabIndex={-1}
              >
                {showPassword ? (
                  <EyeOff className="w-4 h-4" />
                ) : (
                  <Eye className="w-4 h-4" />
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
                <Loader2 className="w-4 h-4 animate-spin" />
                {t("auth.submitting")}
              </>
            ) : (
              t("auth.submit")
            )}
          </Button>

          <p className="text-sm text-center text-muted-foreground">
            {t("auth.noAccount")}{" "}
            <Link
              href="/register"
              className="text-primary hover:text-primary/80 font-medium transition-colors"
            >
              {t("auth.register")}
            </Link>
          </p>
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
