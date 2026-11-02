import { SidebarLayout } from "@/components/SidebarLayout"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { Workflow, Play, Pause, Settings, Plus, Zap } from "lucide-react"

export default function WorkflowsPage() {
  return (
    <SidebarLayout>
      <div className="p-6 space-y-6">
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-3">
            <Workflow className="w-6 h-6 text-indigo-500" />
            <div>
              <h1 className="text-2xl font-bold">Workflows</h1>
              <p className="text-muted-foreground">Automatizá procesos y acciones repetitivas</p>
            </div>
          </div>
          <Button className="gap-2">
            <Plus className="w-4 h-4" />
            Crear workflow
          </Button>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
          <Card>
            <CardHeader>
              <div className="flex items-center justify-between">
                <CardTitle className="text-lg">Asignación automática</CardTitle>
                <Badge variant="default" className="bg-success text-success-foreground">
                  Activo
                </Badge>
              </div>
            </CardHeader>
            <CardContent className="space-y-4">
              <p className="text-sm text-muted-foreground">
                Asigna automáticamente nuevos leads a vendedores según disponibilidad y especialización
              </p>
              <div className="flex items-center gap-2 text-sm">
                <Zap className="w-4 h-4 text-yellow-500" />
                <span>127 ejecuciones esta semana</span>
              </div>
              <div className="flex gap-2">
                <Button size="sm" variant="outline" aria-label="Pausar asignación automática">
                  <Pause className="w-4 h-4" aria-hidden="true" />
                </Button>
                <Button size="sm" variant="outline" aria-label="Configurar asignación automática">
                  <Settings className="w-4 h-4" aria-hidden="true" />
                </Button>
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <div className="flex items-center justify-between">
                <CardTitle className="text-lg">Seguimiento de leads</CardTitle>
                <Badge variant="secondary">Pausado</Badge>
              </div>
            </CardHeader>
            <CardContent className="space-y-4">
              <p className="text-sm text-muted-foreground">
                Envía mensajes de seguimiento automáticos después de 24h sin respuesta
              </p>
              <div className="flex items-center gap-2 text-sm">
                <Zap className="w-4 h-4 text-muted-foreground" />
                <span>0 ejecuciones hoy</span>
              </div>
              <div className="flex gap-2">
                <Button size="sm" variant="outline" aria-label="Activar seguimiento de leads">
                  <Play className="w-4 h-4" aria-hidden="true" />
                </Button>
                <Button size="sm" variant="outline" aria-label="Configurar seguimiento de leads">
                  <Settings className="w-4 h-4" aria-hidden="true" />
                </Button>
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <div className="flex items-center justify-between">
                <CardTitle className="text-lg">Calificación IA</CardTitle>
                <Badge variant="default" className="bg-success text-success-foreground">
                  Activo
                </Badge>
              </div>
            </CardHeader>
            <CardContent className="space-y-4">
              <p className="text-sm text-muted-foreground">
                Califica automáticamente leads según probabilidad de conversión usando IA
              </p>
              <div className="flex items-center gap-2 text-sm">
                <Zap className="w-4 h-4 text-yellow-500" />
                <span>89 ejecuciones esta semana</span>
              </div>
              <div className="flex gap-2">
                <Button size="sm" variant="outline" aria-label="Pausar Calificación IA">
                  <Pause className="w-4 h-4" aria-hidden="true" />
                </Button>
                <Button size="sm" variant="outline" aria-label="Configurar Calificación IA">
                  <Settings className="w-4 h-4" aria-hidden="true" />
                </Button>
              </div>
            </CardContent>
          </Card>
        </div>
      </div>
    </SidebarLayout>
  )
}
