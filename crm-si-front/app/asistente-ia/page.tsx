import { SidebarLayout } from "@/components/SidebarLayout"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Sparkles, MessageSquare, Send, Lightbulb } from "lucide-react"

export default function AsistenteIAPage() {
  return (
    <SidebarLayout>
      <div className="p-6 space-y-6">
        <div className="flex items-center gap-3">
          <Sparkles className="w-6 h-6 text-violet-500" />
          <div>
            <h1 className="text-2xl font-bold">Asistente IA</h1>
            <p className="text-muted-foreground">Tu asistente inteligente para ventas y atención al cliente</p>
          </div>
        </div>

        <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
          <div className="lg:col-span-2">
            <Card className="h-96">
              <CardHeader>
                <CardTitle className="flex items-center gap-2">
                  <MessageSquare className="w-5 h-5" />
                  Chat con el asistente
                </CardTitle>
              </CardHeader>
              <CardContent className="flex flex-col h-full">
                <div className="flex-1 space-y-4 mb-4">
                  <div className="bg-muted p-3 rounded-lg">
                    <p className="text-sm">¡Hola! Soy tu asistente IA. ¿En qué puedo ayudarte hoy?</p>
                  </div>
                </div>
                <div className="flex gap-2">
                  <Input placeholder="Escribí tu pregunta..." className="flex-1" aria-label="Pregunta para el asistente" />
                  <Button size="sm" aria-label="Enviar pregunta">
                    <Send className="w-4 h-4" aria-hidden="true" />
                  </Button>
                </div>
              </CardContent>
            </Card>
          </div>

          <div className="space-y-4">
            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2">
                  <Lightbulb className="w-5 h-5" />
                  Sugerencias rápidas
                </CardTitle>
              </CardHeader>
              <CardContent className="space-y-2">
                <Button variant="outline" size="sm" className="w-full justify-start bg-transparent">
                  Generar respuesta para lead
                </Button>
                <Button variant="outline" size="sm" className="w-full justify-start bg-transparent">
                  Analizar conversación
                </Button>
                <Button variant="outline" size="sm" className="w-full justify-start bg-transparent">
                  Sugerir próximos pasos
                </Button>
                <Button variant="outline" size="sm" className="w-full justify-start bg-transparent">
                  Crear propuesta
                </Button>
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle>Estadísticas de uso</CardTitle>
              </CardHeader>
              <CardContent className="space-y-3">
                <div className="flex justify-between">
                  <span className="text-sm">Consultas hoy</span>
                  <span className="font-semibold">47</span>
                </div>
                <div className="flex justify-between">
                  <span className="text-sm">Tiempo ahorrado</span>
                  <span className="font-semibold">2.3h</span>
                </div>
                <div className="flex justify-between">
                  <span className="text-sm">Precisión</span>
                  <span className="font-semibold">96%</span>
                </div>
              </CardContent>
            </Card>
          </div>
        </div>
      </div>
    </SidebarLayout>
  )
}
