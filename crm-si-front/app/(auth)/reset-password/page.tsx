"use client"

import { useState, Suspense } from "react"
import { useRouter, useSearchParams } from "next/navigation"
import Link from "next/link"
import { ArrowLeft, CheckCircle, Eye, EyeOff, Loader2, MessageSquare } from "lucide-react"

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

function ResetPasswordForm() {
  const router = useRouter()
  const searchParams = useSearchParams()
  const token = searchParams.get("token")
  const email = searchParams.get("email")
  
  const [password, setPassword] = useState("")
  const [confirmPassword, setConfirmPassword] = useState("")
  const [showPassword, setShowPassword] = useState(false)
  const [showConfirmPassword, setShowConfirmPassword] = useState(false)
  const [isLoading, setIsLoading] = useState(false)
  const [isSuccess, setIsSuccess] = useState(false)
  const [error, setError] = useState("")
  // Campos a los que se refiere el error actual (validación local o errores del backend)
  const [errorFields, setErrorFields] = useState<string[]>([])

  const fieldErrorProps = (field: string) =>
    error && errorFields.includes(field)
      ? { "aria-invalid": true as const, "aria-describedby": "reset-password-error" }
      : {}

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    setError("")
    setErrorFields([])

    if (password !== confirmPassword) {
      setError("Las contraseñas no coinciden")
      setErrorFields(["confirmPassword"])
      return
    }

    if (password.length < 8) {
      setError("La contraseña debe tener al menos 8 caracteres")
      setErrorFields(["password"])
      return
    }

    setIsLoading(true)

    try {
      const res = await fetch("/api/auth/reset-password", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          token,
          email,
          password,
          password_confirmation: confirmPassword,
        }),
      })

      const data = await res.json()

      if (!res.ok) {
        // Laravel devuelve `errors` por campo en 422: marcamos esos inputs como inválidos
        setErrorFields(
          Object.keys(data.errors ?? {}).map((key) =>
            key === "password_confirmation" ? "confirmPassword" : key
          )
        )
        throw new Error(data.message || "Error al restablecer la contraseña")
      }

      setIsSuccess(true)
    } catch (err: any) {
      setError(err.message || "Error al conectar con el servidor")
    } finally {
      setIsLoading(false)
    }
  }

  if (!token || !email) {
    return (
      <Card>
        <CardHeader className="text-center space-y-4">
          <div className="mx-auto w-12 h-12 bg-destructive/10 rounded-xl flex items-center justify-center">
            <MessageSquare className="w-6 h-6 text-destructive" aria-hidden="true" />
          </div>
          <div>
            <CardTitle className="text-2xl font-bold">Enlace inválido</CardTitle>
            <CardDescription className="mt-2">
              El enlace para restablecer tu contraseña es inválido o ha expirado.
            </CardDescription>
          </div>
        </CardHeader>
        <CardFooter className="flex flex-col gap-4">
          <Link href="/forgot-password" className="w-full">
            <Button className="w-full">Solicitar nuevo enlace</Button>
          </Link>
          <Link href="/login" className="w-full">
            <Button variant="ghost" className="w-full">
              <ArrowLeft className="w-4 h-4" aria-hidden="true" />
              Volver al login
            </Button>
          </Link>
        </CardFooter>
      </Card>
    )
  }

  if (isSuccess) {
    return (
      <Card>
        <CardHeader className="text-center space-y-4">
          <div className="mx-auto w-12 h-12 bg-success/10 rounded-xl flex items-center justify-center">
            <CheckCircle className="w-6 h-6 text-success" aria-hidden="true" />
          </div>
          <div role="status">
            <CardTitle className="text-2xl font-bold">¡Contraseña restablecida!</CardTitle>
            <CardDescription className="mt-2">
              Tu contraseña ha sido actualizada correctamente.
            </CardDescription>
          </div>
        </CardHeader>
        <CardFooter>
          <Link href="/login" className="w-full">
            <Button className="w-full">Iniciar sesión</Button>
          </Link>
        </CardFooter>
      </Card>
    )
  }

  return (
    <Card>
      <CardHeader className="text-center space-y-4">
        <div className="mx-auto w-12 h-12 bg-primary/10 rounded-xl flex items-center justify-center">
          <MessageSquare className="w-6 h-6 text-primary" aria-hidden="true" />
        </div>
        <div>
          <CardTitle className="text-2xl font-bold">Nueva contraseña</CardTitle>
          <CardDescription className="mt-2">
            Ingresá tu nueva contraseña para {email}
          </CardDescription>
        </div>
      </CardHeader>
      
      <form onSubmit={handleSubmit}>
        <CardContent className="space-y-4">
          {error && (
            <div
              id="reset-password-error"
              role="alert"
              className="p-3 text-sm text-destructive bg-destructive/10 border border-destructive/20 rounded-lg"
            >
              {error}
            </div>
          )}
          
          <div className="space-y-2">
            <Label htmlFor="password">Nueva contraseña</Label>
            <div className="relative">
              <Input
                id="password"
                type={showPassword ? "text" : "password"}
                placeholder="Mínimo 8 caracteres"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                required
                autoComplete="new-password"
                disabled={isLoading}
                {...fieldErrorProps("password")}
                className="pr-10"
              />
              <button
                type="button"
                onClick={() => setShowPassword(!showPassword)}
                className="absolute right-1 top-1/2 -translate-y-1/2 flex h-8 w-8 items-center justify-center rounded-md text-muted-foreground hover:text-foreground transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                aria-label={showPassword ? "Ocultar contraseña" : "Mostrar contraseña"}
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
          
          <div className="space-y-2">
            <Label htmlFor="confirmPassword">Confirmar contraseña</Label>
            <div className="relative">
              <Input
                id="confirmPassword"
                type={showConfirmPassword ? "text" : "password"}
                placeholder="Repetí tu contraseña"
                value={confirmPassword}
                onChange={(e) => setConfirmPassword(e.target.value)}
                required
                autoComplete="new-password"
                disabled={isLoading}
                {...fieldErrorProps("confirmPassword")}
                className="pr-10"
              />
              <button
                type="button"
                onClick={() => setShowConfirmPassword(!showConfirmPassword)}
                className="absolute right-1 top-1/2 -translate-y-1/2 flex h-8 w-8 items-center justify-center rounded-md text-muted-foreground hover:text-foreground transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                aria-label={showConfirmPassword ? "Ocultar contraseña" : "Mostrar contraseña"}
                aria-pressed={showConfirmPassword}
                aria-controls="confirmPassword"
              >
                {showConfirmPassword ? (
                  <EyeOff className="w-4 h-4" aria-hidden="true" />
                ) : (
                  <Eye className="w-4 h-4" aria-hidden="true" />
                )}
              </button>
            </div>
          </div>
        </CardContent>
        
        <CardFooter className="flex flex-col gap-4">
          <Button type="submit" className="w-full" disabled={isLoading}>
            {isLoading ? (
              <>
                <Loader2 className="w-4 h-4 animate-spin" aria-hidden="true" />
                Guardando...
              </>
            ) : (
              "Restablecer contraseña"
            )}
          </Button>
          
          <Link href="/login" className="w-full">
            <Button variant="ghost" className="w-full">
              <ArrowLeft className="w-4 h-4" aria-hidden="true" />
              Volver al login
            </Button>
          </Link>
        </CardFooter>
      </form>
    </Card>
  )
}

export default function ResetPasswordPage() {
  return (
    <Suspense fallback={
      <Card>
        <CardContent className="p-8 flex items-center justify-center" role="status">
          <Loader2 className="w-6 h-6 animate-spin text-primary" aria-hidden="true" />
          <span className="sr-only">Cargando...</span>
        </CardContent>
      </Card>
    }>
      <ResetPasswordForm />
    </Suspense>
  )
}
