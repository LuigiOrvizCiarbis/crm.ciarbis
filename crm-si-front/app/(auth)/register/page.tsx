"use client"

import { useRef, useState } from "react"
import { useRouter, useSearchParams } from "next/navigation"
import Link from "next/link"
import { Eye, EyeOff, Loader2, MessageSquare } from "lucide-react"

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
import { useTranslation } from "@/hooks/useTranslation"
import { LanguageSwitcher } from "@/components/LanguageSwitcher"
import { clearWorkspaceId, setWorkspaceId } from "@/lib/api/auth-token"

export default function RegisterPage() {
  const router = useRouter()
  const searchParams = useSearchParams()
  const { setAuth, setLoading, isLoading } = useAuthStore()
  const { t } = useTranslation()

  const invitationToken = searchParams.get("invitation_token")
  const invitedEmail = searchParams.get("email")

  const [name, setName] = useState("")
  const [email, setEmail] = useState(invitedEmail || "")
  const [password, setPassword] = useState("")
  const [confirmPassword, setConfirmPassword] = useState("")
  const [acceptTerms, setAcceptTerms] = useState(false)
  const [showPassword, setShowPassword] = useState(false)
  const [showConfirmPassword, setShowConfirmPassword] = useState(false)
  const [error, setError] = useState("")
  const isSubmittingRef = useRef(false)

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()

    if (isSubmittingRef.current || isLoading) {
      return
    }

    isSubmittingRef.current = true
    setError("")

    if (password !== confirmPassword) {
      setError(t("auth.passwordsDoNotMatch"))
      isSubmittingRef.current = false
      return
    }

    if (password.length < 8) {
      setError(t("auth.passwordTooShort"))
      isSubmittingRef.current = false
      return
    }

    if (!acceptTerms) {
      setError(t("auth.mustAcceptTerms"))
      isSubmittingRef.current = false
      return
    }

    setLoading(true)

    try {
      const res = await fetch("/api/auth/register", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ name, email, password, password_confirmation: confirmPassword, ...(invitationToken ? { invitation_token: invitationToken } : {}) }),
      })

      const data = await res.json()

      if (!res.ok) {
        throw new Error(data.message || t("auth.registerError"))
      }

      // Email no verificado al registrar
      clearWorkspaceId()
      if (data.user?.tenant_id) setWorkspaceId(data.user.tenant_id)
      setAuth(data.user, data.token, false, false, data.role ?? null, data.permissions ?? [])
      router.push("/verify-email?registered=true")
    } catch (err: any) {
      setError(err.message || t("auth.error"))
    } finally {
      isSubmittingRef.current = false
      setLoading(false)
    }
  }

  return (
    <Card className="border-border/50 shadow-xl">
      <CardHeader className="text-center space-y-4">
        <div className="mx-auto w-12 h-12 bg-primary/10 rounded-xl flex items-center justify-center">
          <MessageSquare className="w-6 h-6 text-primary" />
        </div>
        <div>
          <CardTitle className="text-2xl font-bold">{t("auth.registerTitle")}</CardTitle>
          <CardDescription className="mt-2">
            {t("auth.registerSubtitle")}
          </CardDescription>
        </div>
        <div className="flex justify-center">
          <LanguageSwitcher />
        </div>
      </CardHeader>

      <form onSubmit={handleSubmit}>
        <CardContent className="space-y-4">
          {invitationToken && (
            <div className="p-3 text-sm text-primary bg-primary/10 border border-primary/20 rounded-lg text-center">
              {t("team.joiningWorkspace")}
            </div>
          )}

          {error && (
            <div className="p-3 text-sm text-destructive bg-destructive/10 border border-destructive/20 rounded-lg">
              {error}
            </div>
          )}

          <div className="space-y-2">
            <Label htmlFor="name">{t("auth.fullName")}</Label>
            <Input
              id="name"
              type="text"
              placeholder={t("auth.fullNamePlaceholder")}
              value={name}
              onChange={(e) => setName(e.target.value)}
              required
              autoComplete="name"
              disabled={isLoading}
            />
          </div>

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
              disabled={isLoading || !!invitationToken}
              readOnly={!!invitationToken}
            />
          </div>

          <div className="space-y-2">
            <Label htmlFor="password">{t("auth.password")}</Label>
            <div className="relative">
              <Input
                id="password"
                type={showPassword ? "text" : "password"}
                placeholder={t("auth.passwordMinLength")}
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                required
                autoComplete="new-password"
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

          <div className="space-y-2">
            <Label htmlFor="confirmPassword">{t("auth.confirmPassword")}</Label>
            <div className="relative">
              <Input
                id="confirmPassword"
                type={showConfirmPassword ? "text" : "password"}
                placeholder={t("auth.confirmPasswordPlaceholder")}
                value={confirmPassword}
                onChange={(e) => setConfirmPassword(e.target.value)}
                required
                autoComplete="new-password"
                disabled={isLoading}
                className="pr-10"
              />
              <button
                type="button"
                onClick={() => setShowConfirmPassword(!showConfirmPassword)}
                className="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground transition-colors"
                tabIndex={-1}
              >
                {showConfirmPassword ? (
                  <EyeOff className="w-4 h-4" />
                ) : (
                  <Eye className="w-4 h-4" />
                )}
              </button>
            </div>
          </div>

          <div className="flex items-start space-x-2">
            <Checkbox
              id="terms"
              checked={acceptTerms}
              onCheckedChange={(checked) => setAcceptTerms(checked as boolean)}
              disabled={isLoading}
              className="mt-0.5"
            />
            <Label htmlFor="terms" className="text-sm font-normal cursor-pointer leading-relaxed">
              {t("auth.acceptTermsLabel")}{" "}
              <Link href="/terms" className="text-primary hover:text-primary/80">
                {t("auth.termsAndConditions")}
              </Link>{" "}
              {t("auth.andThe")}{" "}
              <Link href="/privacy-policy" className="text-primary hover:text-primary/80">
                {t("auth.privacyPolicy")}
              </Link>
            </Label>
          </div>
        </CardContent>

        <CardFooter className="flex flex-col gap-4">
          <Button type="submit" className="w-full" disabled={isLoading || !acceptTerms}>
            {isLoading ? (
              <>
                <Loader2 className="w-4 h-4 animate-spin" />
                {t("auth.registerSubmitting")}
              </>
            ) : (
              t("auth.registerSubmit")
            )}
          </Button>

          <p className="text-sm text-center text-muted-foreground">
            {t("auth.hasAccount")}{" "}
            <Link
              href="/login"
              className="text-primary hover:text-primary/80 font-medium transition-colors"
            >
              {t("auth.loginLink")}
            </Link>
          </p>
        </CardFooter>
      </form>
    </Card>
  )
}
