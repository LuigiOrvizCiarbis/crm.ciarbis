"use client"

import { useState, useEffect, Suspense } from "react"
import { useRouter, useSearchParams } from "next/navigation"
import Link from "next/link"
import { Mail, Loader2, RefreshCw, Edit3, MessageSquare, Inbox, FolderOpen, Clock } from "lucide-react"

import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import {
  Card,
  CardContent,
  CardDescription,
  CardFooter,
  CardHeader,
  CardTitle,
} from "@/components/ui/card"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "@/components/ui/dialog"
import { useAuthStore } from "@/store/useAuthStore"
import { signOut } from "@/lib/account-session"

function maskEmail(email: string): string {
  const [local, domain] = email.split("@")
  if (local.length <= 2) {
    return `${local[0]}***@${domain}`
  }
  return `${local[0]}${local[1]}${"*".repeat(Math.min(local.length - 2, 6))}@${domain}`
}

function VerifyEmailContent() {
  const router = useRouter()
  const searchParams = useSearchParams()
  const user = useAuthStore((state) => state.user)
  
  const [isResending, setIsResending] = useState(false)
  const [resendSuccess, setResendSuccess] = useState(false)
  const [resendError, setResendError] = useState("")
  const [countdown, setCountdown] = useState(0)
  
  // Change email dialog
  const [isChangingEmail, setIsChangingEmail] = useState(false)
  const [newEmail, setNewEmail] = useState("")
  const [password, setPassword] = useState("")
  const [changeError, setChangeError] = useState("")
  const [isDialogOpen, setIsDialogOpen] = useState(false)

  // Countdown timer for resend
  useEffect(() => {
    if (countdown > 0) {
      const timer = setTimeout(() => setCountdown(countdown - 1), 1000)
      return () => clearTimeout(timer)
    }
  }, [countdown])

  // Check if coming from registration
  const fromRegister = searchParams.get("registered") === "true"

  const handleResendEmail = async () => {
    if (countdown > 0 || !user?.email) return
    
    setIsResending(true)
    setResendError("")
    setResendSuccess(false)

    try {
      const res = await fetch("/api/auth/resend-verification", {
        method: "POST",
        headers: { 
          "Content-Type": "application/json",
        },
        body: JSON.stringify({ email: user.email }),
      })

      const data = await res.json()

      if (!res.ok) {
        throw new Error(data.message || "Error al reenviar email")
      }

      setResendSuccess(true)
      setCountdown(60) // 60 seconds cooldown
    } catch (err: any) {
      setResendError(err.message || "Error al conectar con el servidor")
    } finally {
      setIsResending(false)
    }
  }

  const handleChangeEmail = async (e: React.FormEvent) => {
    e.preventDefault()
    setChangeError("")
    setIsChangingEmail(true)

    try {
      const res = await fetch("/api/auth/change-email", {
        method: "POST",
        headers: { 
          "Content-Type": "application/json",
        },
        body: JSON.stringify({ email: newEmail, password }),
      })

      const data = await res.json()

      if (!res.ok) {
        throw new Error(data.message || "Error al cambiar email")
      }

      // Update user in store
      useAuthStore.getState().setAuth({ ...user!, email: newEmail }, false)
      setIsDialogOpen(false)
      setNewEmail("")
      setPassword("")
      setResendSuccess(true)
    } catch (err: any) {
      setChangeError(err.message || "Error al conectar con el servidor")
    } finally {
      setIsChangingEmail(false)
    }
  }

  if (!user) {
    router.replace("/login")
    return null
  }

  return (
    <Card>
      <CardHeader className="text-center space-y-4">
        <div className="mx-auto w-16 h-16 bg-primary/10 rounded-full flex items-center justify-center">
          <Mail className="w-8 h-8 text-primary" aria-hidden="true" />
        </div>
        <div>
          <CardTitle className="text-2xl font-bold">
            {fromRegister ? "¡Cuenta creada!" : "Verificá tu email"}
          </CardTitle>
          <CardDescription className="mt-2">
            {fromRegister 
              ? "Te hemos enviado un email de verificación"
              : "Necesitás verificar tu email para continuar"
            }
          </CardDescription>
        </div>
      </CardHeader>
      
      <CardContent className="space-y-4">
        <div className="bg-muted/50 rounded-lg p-4 text-center">
          <p className="text-sm text-muted-foreground mb-1">
            Enviamos un link de verificación a:
          </p>
          <p className="font-medium text-foreground">
            {maskEmail(user.email)}
          </p>
        </div>

        <div className="text-sm text-muted-foreground space-y-2">
          <p className="flex items-start gap-2"><Inbox className="size-4 shrink-0 text-muted-foreground" aria-hidden="true" /> Revisá tu bandeja de entrada</p>
          <p className="flex items-start gap-2"><FolderOpen className="size-4 shrink-0 text-muted-foreground" aria-hidden="true" /> Si no lo encontrás, revisá la carpeta de spam</p>
          <p className="flex items-start gap-2"><Clock className="size-4 shrink-0 text-muted-foreground" aria-hidden="true" /> El link expira en 24 horas</p>
        </div>

        {resendSuccess && (
          <div
            role="status"
            className="p-3 text-sm text-success bg-success/10 border border-success/30 rounded-lg text-center"
          >
            ¡Email reenviado exitosamente!
          </div>
        )}

        {resendError && (
          <div
            id="resend-error"
            role="alert"
            className="p-3 text-sm text-destructive bg-destructive/10 border border-destructive/30 rounded-lg text-center"
          >
            {resendError}
          </div>
        )}
      </CardContent>
      
      <CardFooter className="flex flex-col gap-3">
        <Button 
          onClick={handleResendEmail} 
          variant="outline" 
          className="w-full"
          disabled={isResending || countdown > 0}
        >
          {isResending ? (
            <>
              <Loader2 className="w-4 h-4 animate-spin" aria-hidden="true" />
              Enviando...
            </>
          ) : countdown > 0 ? (
            <>
              <RefreshCw className="w-4 h-4" aria-hidden="true" />
              Reenviar en {countdown}s
            </>
          ) : (
            <>
              <RefreshCw className="w-4 h-4" aria-hidden="true" />
              Reenviar email de verificación
            </>
          )}
        </Button>

        <Dialog open={isDialogOpen} onOpenChange={setIsDialogOpen}>
          <DialogTrigger asChild>
            <Button variant="ghost" className="w-full text-muted-foreground">
              <Edit3 className="w-4 h-4" aria-hidden="true" />
              ¿Email incorrecto? Cámbialo aquí
            </Button>
          </DialogTrigger>
          <DialogContent>
            <form onSubmit={handleChangeEmail}>
              <DialogHeader>
                <DialogTitle>Cambiar email</DialogTitle>
                <DialogDescription>
                  Ingresá tu nuevo email y tu contraseña para confirmar
                </DialogDescription>
              </DialogHeader>
              
              <div className="space-y-4 py-4">
                {changeError && (
                  <div
                    id="change-email-error"
                    role="alert"
                    className="p-3 text-sm text-destructive bg-destructive/10 border border-destructive/30 rounded-lg"
                  >
                    {changeError}
                  </div>
                )}
                
                <div className="space-y-2">
                  <Label htmlFor="newEmail">Nuevo email</Label>
                  <Input
                    id="newEmail"
                    type="email"
                    placeholder="nuevo@email.com"
                    value={newEmail}
                    onChange={(e) => setNewEmail(e.target.value)}
                    required
                    disabled={isChangingEmail}
                    aria-invalid={changeError ? true : undefined}
                    aria-describedby={changeError ? "change-email-error" : undefined}
                  />
                </div>
                
                <div className="space-y-2">
                  <Label htmlFor="password">Tu contraseña</Label>
                  <Input
                    id="password"
                    type="password"
                    placeholder="••••••••"
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                    required
                    disabled={isChangingEmail}
                    aria-invalid={changeError ? true : undefined}
                    aria-describedby={changeError ? "change-email-error" : undefined}
                  />
                </div>
              </div>
              
              <DialogFooter>
                <Button type="button" variant="ghost" onClick={() => setIsDialogOpen(false)}>
                  Cancelar
                </Button>
                <Button type="submit" disabled={isChangingEmail}>
                  {isChangingEmail ? (
                    <>
                      <Loader2 className="w-4 h-4 animate-spin" aria-hidden="true" />
                      Cambiando...
                    </>
                  ) : (
                    "Cambiar email"
                  )}
                </Button>
              </DialogFooter>
            </form>
          </DialogContent>
        </Dialog>

        <div className="w-full border-t pt-3">
          <Button 
            variant="ghost" 
            className="w-full text-muted-foreground hover:text-destructive"
            onClick={() => void signOut()}
          >
            Cerrar sesión
          </Button>
        </div>
      </CardFooter>
    </Card>
  )
}

export default function VerifyEmailPage() {
  return (
    <Suspense fallback={
      <Card>
        <CardContent className="p-8 flex items-center justify-center" role="status">
          <Loader2 className="w-6 h-6 animate-spin text-primary" aria-hidden="true" />
          <span className="sr-only">Cargando...</span>
        </CardContent>
      </Card>
    }>
      <VerifyEmailContent />
    </Suspense>
  )
}
