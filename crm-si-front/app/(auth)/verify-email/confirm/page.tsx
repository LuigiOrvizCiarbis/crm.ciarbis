'use client'

import { useEffect, useState, useRef } from 'react'
import { useSearchParams, useRouter } from 'next/navigation'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Loader2, CheckCircle, XCircle, AlertCircle } from 'lucide-react'
import Link from 'next/link'
import { useAuthStore } from '@/store/useAuthStore'

export default function VerifyEmailConfirmPage() {
  const searchParams = useSearchParams()
  const router = useRouter()
  const { setEmailVerified } = useAuthStore()
  
  const [status, setStatus] = useState<'loading' | 'success' | 'already' | 'error'>('loading')
  const [errorMessage, setErrorMessage] = useState('')
  const hasVerified = useRef(false)

  useEffect(() => {
    // Prevenir doble ejecución en React Strict Mode
    if (hasVerified.current) return
    hasVerified.current = true

    const verifyEmail = async () => {
      const id = searchParams.get('id')
      const hash = searchParams.get('hash')
      const expires = searchParams.get('expires')
      const signature = searchParams.get('signature')

      if (!id || !hash || !expires || !signature) {
        setStatus('error')
        setErrorMessage('Enlace de verificación inválido. Faltan parámetros.')
        return
      }

      try {
        const response = await fetch(`/api/auth/verify-email?id=${id}&hash=${hash}&expires=${expires}&signature=${signature}`)
        const data = await response.json()
        

        if (response.ok) {
          // El backend devuelve { success: true } para verificación exitosa
          // y { already: true } si ya estaba verificado
          if (data.success) {
            setStatus('success')
            setEmailVerified(true)
          } else if (data.already) {
            setStatus('already')
            setEmailVerified(true)
          } else {
            // Fallback: si response.ok pero no hay flags, asumir éxito
            setStatus('success')
            setEmailVerified(true)
          }
        } else {
          setStatus('error')
          setErrorMessage(data.message || 'Error al verificar el email')
        }
      } catch {
        setStatus('error')
        setErrorMessage('Error de conexión. Intentá de nuevo.')
      }
    }

    verifyEmail()
  }, [searchParams, setEmailVerified])

  return (
    <Card className="w-full max-w-md">
        {/* Región viva persistente: el resultado llega async y se anuncia al cambiar */}
        <CardHeader className="text-center" aria-live="polite" aria-atomic="true">
          {status === 'loading' && (
            <>
              <div className="mx-auto mb-4">
                <Loader2 className="h-12 w-12 animate-spin text-primary" aria-hidden="true" />
              </div>
              <CardTitle>Verificando tu email...</CardTitle>
              <CardDescription>
                Esperá mientras confirmamos tu dirección de correo.
              </CardDescription>
            </>
          )}

          {status === 'success' && (
            <>
              <div className="mx-auto mb-4">
                <CheckCircle className="h-12 w-12 text-success" aria-hidden="true" />
              </div>
              <CardTitle className="text-success">¡Email verificado!</CardTitle>
              <CardDescription>
                Tu cuenta fue verificada correctamente. Ya podés acceder a todas las funcionalidades.
              </CardDescription>
            </>
          )}

          {status === 'already' && (
            <>
              <div className="mx-auto mb-4">
                <AlertCircle className="h-12 w-12 text-info" aria-hidden="true" />
              </div>
              <CardTitle className="text-info">Email ya verificado</CardTitle>
              <CardDescription>
                Tu dirección de correo ya había sido verificada anteriormente.
              </CardDescription>
            </>
          )}

          {status === 'error' && (
            <>
              <div className="mx-auto mb-4">
                <XCircle className="h-12 w-12 text-destructive" aria-hidden="true" />
              </div>
              <CardTitle className="text-destructive">Error de verificación</CardTitle>
              <CardDescription className="text-destructive" role="alert">
                {errorMessage}
              </CardDescription>
            </>
          )}
        </CardHeader>

        <CardContent className="space-y-4">
          {(status === 'success' || status === 'already') && (
            <Button className="w-full" onClick={() => router.push('/login')}>
              Iniciar sesión
            </Button>
          )}

          {status === 'error' && (
            <div className="space-y-2">
              <Button className="w-full" variant="outline" asChild>
                <Link href="/verify-email">
                  Solicitar nuevo enlace
                </Link>
              </Button>
              <Button className="w-full" variant="ghost" asChild>
                <Link href="/login">
                  Volver al login
                </Link>
              </Button>
            </div>
          )}
        </CardContent>
    </Card>
  )
}
