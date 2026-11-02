import { SidebarLayout } from "@/components/SidebarLayout"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { UserCheck, Users, Settings } from "lucide-react"

export default function AsignacionIAPage() {
  return (
    <SidebarLayout>
      <div className="p-6 space-y-6">
        <div className="flex items-center gap-3">
          <UserCheck className="w-6 h-6 text-teal-500" />
          <div>
            <h1 className="text-2xl font-bold">Asignación IA</h1>
            <p className="text-muted-foreground">Asignación inteligente de leads a vendedores</p>
          </div>
        </div>

        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
          <Card>
            <CardHeader>
              <CardTitle>Configuración de asignación</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="space-y-3">
                <div className="flex items-center justify-between">
                  <span>Asignación por especialización</span>
                  <Badge variant="default">Activo</Badge>
                </div>
                <div className="flex items-center justify-between">
                  <span>Balance de carga de trabajo</span>
                  <Badge variant="default">Activo</Badge>
                </div>
                <div className="flex items-center justify-between">
                  <span>Horarios de disponibilidad</span>
                  <Badge variant="secondary">Configurar</Badge>
                </div>
              </div>
              <Button className="w-full gap-2">
                <Settings className="w-4 h-4" />
                Configurar reglas
              </Button>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>Rendimiento del equipo</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="space-y-4">
                <div className="flex items-center justify-between">
                  <div className="flex items-center gap-2">
                    <Users className="w-4 h-4" />
                    <span>María González</span>
                  </div>
                  <div className="text-right">
                    <div className="font-semibold">15 leads</div>
                    <div className="text-sm text-muted-foreground">85% conversión</div>
                  </div>
                </div>
                <div className="flex items-center justify-between">
                  <div className="flex items-center gap-2">
                    <Users className="w-4 h-4" />
                    <span>Carlos Ruiz</span>
                  </div>
                  <div className="text-right">
                    <div className="font-semibold">12 leads</div>
                    <div className="text-sm text-muted-foreground">78% conversión</div>
                  </div>
                </div>
              </div>
            </CardContent>
          </Card>
        </div>
      </div>
    </SidebarLayout>
  )
}
