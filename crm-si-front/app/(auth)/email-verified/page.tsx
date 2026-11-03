"use client"

import { Suspense } from "react"
import { useSearchParams } from "next/navigation"
import Link from "next/link"
import { CheckCircle, AlertCircle, Loader2 } from "lucide-react"

import { Button } from "@/components/ui/button"
import {
  Card,
  CardContent,
  CardDescription,
  CardFooter,
  CardHeader,
  CardTitle,
} from "@/components/ui/card"

function EmailVerifiedContent() {
  const searchParams = useSearchParams()
  const success = searchParams.get("success") === "true"
  const alreadyVerified = searchParams.get("already") === "true"

  if (alreadyVerified) {
    return (
      <Card>
        <CardHeader className="text-center space-y-4">
          <div className="mx-auto w-16 h-16 bg-info/10 rounded-full flex items-center justify-center">
            <CheckCircle className="w-8 h-8 text-info" aria-hidden="true" />
          </div>
          <div>
            <CardTitle className="text-2xl font-bold">Email ya verificado</CardTitle>
            <CardDescription className="mt-2">
              Tu email ya fue verificado anteriormente
            </CardDescription>
          </div>
        </CardHeader>
        
        <CardContent>
          <p className="text-sm text-muted-foreground text-center">
            Puedes continuar usando la aplicación normalmente.
          </p>
        </CardContent>
        
        <CardFooter>
          <Link href="/chats" className="w-full">
            <Button className="w-full">
              Ir a la aplicación
            </Button>
          </Link>
        </CardFooter>
      </Card>
    )
  }

  if (success) {
    return (
      <Card>
        <CardHeader className="text-center space-y-4">
          <div className="mx-auto w-16 h-16 bg-success/10 rounded-full flex items-center justify-center">
            <CheckCircle className="w-8 h-8 text-success" aria-hidden="true" />
          </div>
          <div>
            <CardTitle className="text-2xl font-bold">¡Email verificado!</CardTitle>
            <CardDescription className="mt-2">
              Tu cuenta ha sido verificada exitosamente
            </CardDescription>
          </div>
        </CardHeader>
        
        <CardContent>
          <p className="text-sm text-muted-foreground text-center">
            Ahora podés acceder a todas las funcionalidades de BIMS.
          </p>
        </CardContent>
        
        <CardFooter>
          <Link href="/chats" className="w-full">
            <Button className="w-full">
              Empezar a usar BIMS
            </Button>
          </Link>
        </CardFooter>
      </Card>
    )
  }

  // Error case
  return (
    <Card>
      <CardHeader className="text-center space-y-4">
        <div className="mx-auto w-16 h-16 bg-destructive/10 rounded-full flex items-center justify-center">
          <AlertCircle className="w-8 h-8 text-destructive" aria-hidden="true" />
        </div>
        <div>
          <CardTitle className="text-2xl font-bold">Error de verificación</CardTitle>
          <CardDescription className="mt-2">
            El link de verificación es inválido o ha expirado
          </CardDescription>
        </div>
      </CardHeader>
      
      <CardContent>
        <p className="text-sm text-muted-foreground text-center">
          Solicitá un nuevo email de verificación desde tu cuenta.
        </p>
      </CardContent>
      
      <CardFooter className="flex flex-col gap-3">
        <Link href="/verify-email" className="w-full">
          <Button className="w-full">
            Solicitar nuevo email
          </Button>
        </Link>
        <Link href="/login" className="w-full">
          <Button variant="ghost" className="w-full">
            Volver al login
          </Button>
        </Link>
      </CardFooter>
    </Card>
  )
}

export default function EmailVerifiedPage() {
  return (
    <Suspense fallback={
      <Card>
        <CardContent className="p-8 flex items-center justify-center" role="status">
          <Loader2 className="w-6 h-6 animate-spin text-primary" aria-hidden="true" />
          <span className="sr-only">Cargando...</span>
        </CardContent>
      </Card>
    }>
      <EmailVerifiedContent />
    </Suspense>
  )
}
