import type { Metadata } from "next"

export const metadata: Metadata = {
  title: "Iniciar sesión",
  description: "Accedé a tu cuenta de BIMS",
}

export default function AuthLayout({
  children,
}: {
  children: React.ReactNode
}) {
  return (
    <div className="min-h-screen overflow-x-hidden flex items-center justify-center bg-background p-4">
      <div className="w-full max-w-md min-w-0">
        {children}
      </div>
    </div>
  )
}
