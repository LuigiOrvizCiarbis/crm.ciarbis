"use client"

import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Badge } from "@/components/Badges"
import { Button } from "@/components/ui/button"
import { useToast } from "@/components/Toast"
import {
  Activity,
  AlertTriangle,
  CheckCircle,
  Clock,
  TrendingUp,
  TrendingDown,
  Zap,
  MessageSquare,
  RefreshCw,
} from "lucide-react"
import { useState } from "react"

interface HealthMetric {
  id: string
  name: string
  value: string
  status: "healthy" | "warning" | "critical"
  trend: "up" | "down" | "stable"
  lastUpdated: string
  description: string
}

export function AdminHealthDashboard() {
  const { addToast } = useToast()
  const [isRefreshing, setIsRefreshing] = useState(false)

  const healthMetrics: HealthMetric[] = [
    {
      id: "response_time",
      name: "Tiempo de respuesta",
      value: "245ms",
      status: "healthy",
      trend: "down",
      lastUpdated: "Hace 30 seg",
      description: "Tiempo promedio de respuesta de la API",
    },
    {
      id: "error_rate",
      name: "Tasa de error",
      value: "0.12%",
      status: "healthy",
      trend: "stable",
      lastUpdated: "Hace 1 min",
      description: "Porcentaje de requests con error",
    },
    {
      id: "active_connections",
      name: "Conexiones activas",
      value: "1,247",
      status: "healthy",
      trend: "up",
      lastUpdated: "Hace 15 seg",
      description: "Usuarios conectados actualmente",
    },
    {
      id: "queue_size",
      name: "Cola de mensajes",
      value: "23",
      status: "warning",
      trend: "up",
      lastUpdated: "Hace 45 seg",
      description: "Mensajes pendientes de procesar",
    },
    {
      id: "db_connections",
      name: "Pool de DB",
      value: "45/100",
      status: "healthy",
      trend: "stable",
      lastUpdated: "Hace 20 seg",
      description: "Conexiones de base de datos utilizadas",
    },
    {
      id: "memory_usage",
      name: "Uso de memoria",
      value: "68%",
      status: "warning",
      trend: "up",
      lastUpdated: "Hace 10 seg",
      description: "Memoria RAM utilizada por el sistema",
    },
    {
      id: "disk_space",
      name: "Espacio en disco",
      value: "23%",
      status: "healthy",
      trend: "stable",
      lastUpdated: "Hace 5 min",
      description: "Espacio de almacenamiento utilizado",
    },
    {
      id: "api_quota",
      name: "Cuota de API",
      value: "8.2K/10K",
      status: "critical",
      trend: "up",
      lastUpdated: "Hace 2 min",
      description: "Requests utilizados del límite diario",
    },
  ]

  const getStatusIcon = (status: string) => {
    switch (status) {
      case "healthy":
        return <CheckCircle className="w-4 h-4 text-success" />
      case "warning":
        return <AlertTriangle className="w-4 h-4 text-warning" />
      case "critical":
        return <AlertTriangle className="w-4 h-4 text-destructive" />
      default:
        return <Clock className="w-4 h-4 text-gray-500" />
    }
  }

  const getStatusBadge = (status: string) => {
    switch (status) {
      case "healthy":
        return <Badge variant="success">Saludable</Badge>
      case "warning":
        return <Badge variant="warning">Advertencia</Badge>
      case "critical":
        return <Badge variant="error">Crítico</Badge>
      default:
        return <Badge variant="info">Desconocido</Badge>
    }
  }

  const getTrendIcon = (trend: string) => {
    switch (trend) {
      case "up":
        return <TrendingUp className="w-3 h-3 text-success" />
      case "down":
        return <TrendingDown className="w-3 h-3 text-destructive" />
      default:
        return <div className="w-3 h-3 bg-gray-400 rounded-full" />
    }
  }

  const handleRefresh = async () => {
    setIsRefreshing(true)
    // Simulate API call
    await new Promise((resolve) => setTimeout(resolve, 2000))
    setIsRefreshing(false)

    addToast({
      type: "success",
      title: "Métricas actualizadas",
      description: "Datos del sistema actualizados correctamente",
    })
  }

  const criticalCount = healthMetrics.filter((m) => m.status === "critical").length
  const warningCount = healthMetrics.filter((m) => m.status === "warning").length
  const healthyCount = healthMetrics.filter((m) => m.status === "healthy").length

  return (
    <div className="space-y-6">
      {/* Health Overview */}
      <Card>
        <CardHeader>
          <div className="flex items-center justify-between">
            <CardTitle className="flex items-center gap-2">
              <Activity className="w-5 h-5" />
              Estado General del Sistema
            </CardTitle>
            <Button
              variant="outline"
              size="sm"
              onClick={handleRefresh}
              disabled={isRefreshing}
              className="gap-2 bg-transparent"
            >
              <RefreshCw className={`w-4 h-4 ${isRefreshing ? "animate-spin" : ""}`} />
              Actualizar
            </Button>
          </div>
        </CardHeader>
        <CardContent>
          <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div className="flex items-center gap-3 p-4 bg-success/10 rounded-lg">
              <CheckCircle className="w-8 h-8 text-success" />
              <div>
                <p className="text-2xl font-bold text-success">{healthyCount}</p>
                <p className="text-sm text-success">Servicios saludables</p>
              </div>
            </div>

            <div className="flex items-center gap-3 p-4 bg-warning/10 rounded-lg">
              <AlertTriangle className="w-8 h-8 text-warning" />
              <div>
                <p className="text-2xl font-bold text-warning">{warningCount}</p>
                <p className="text-sm text-warning">Con advertencias</p>
              </div>
            </div>

            <div className="flex items-center gap-3 p-4 bg-destructive/10 rounded-lg">
              <AlertTriangle className="w-8 h-8 text-destructive" />
              <div>
                <p className="text-2xl font-bold text-destructive">{criticalCount}</p>
                <p className="text-sm text-destructive">Estado crítico</p>
              </div>
            </div>
          </div>
        </CardContent>
      </Card>

      {/* Detailed Metrics */}
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <Zap className="w-5 h-5" />
            Métricas Detalladas
          </CardTitle>
        </CardHeader>
        <CardContent>
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
            {healthMetrics.map((metric) => (
              <div key={metric.id} className="p-4 border rounded-lg hover:shadow-md transition-shadow">
                <div className="flex items-center justify-between mb-2">
                  <div className="flex items-center gap-2">
                    {getStatusIcon(metric.status)}
                    <h4 className="font-medium text-sm">{metric.name}</h4>
                  </div>
                  {getTrendIcon(metric.trend)}
                </div>

                <div className="space-y-2">
                  <p className="text-2xl font-bold">{metric.value}</p>
                  <div className="flex items-center justify-between">{getStatusBadge(metric.status)}</div>
                  <p className="text-xs text-muted-foreground">{metric.description}</p>
                  <p className="text-xs text-muted-foreground">{metric.lastUpdated}</p>
                </div>
              </div>
            ))}
          </div>
        </CardContent>
      </Card>

      {/* Real-time Activity */}
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <MessageSquare className="w-5 h-5" />
            Actividad en Tiempo Real
          </CardTitle>
        </CardHeader>
        <CardContent>
          <div className="space-y-3">
            <div className="flex items-center gap-3 p-3 bg-muted/50 rounded-lg">
              <div className="w-2 h-2 bg-green-500 rounded-full animate-pulse" />
              <div className="flex-1">
                <p className="text-sm font-medium">Nuevo mensaje recibido</p>
                <p className="text-xs text-muted-foreground">WhatsApp • María González • Hace 5 seg</p>
              </div>
            </div>

            <div className="flex items-center gap-3 p-3 bg-muted/50 rounded-lg">
              <div className="w-2 h-2 bg-blue-500 rounded-full animate-pulse" />
              <div className="flex-1">
                <p className="text-sm font-medium">Usuario conectado</p>
                <p className="text-xs text-muted-foreground">Web Chat • Carlos Pérez • Hace 12 seg</p>
              </div>
            </div>

            <div className="flex items-center gap-3 p-3 bg-muted/50 rounded-lg">
              <div className="w-2 h-2 bg-purple-500 rounded-full animate-pulse" />
              <div className="flex-1">
                <p className="text-sm font-medium">Lead calificado</p>
                <p className="text-xs text-muted-foreground">Instagram • Ana Martín • Hace 23 seg</p>
              </div>
            </div>

            <div className="flex items-center gap-3 p-3 bg-muted/50 rounded-lg">
              <div className="w-2 h-2 bg-orange-500 rounded-full animate-pulse" />
              <div className="flex-1">
                <p className="text-sm font-medium">Backup completado</p>
                <p className="text-xs text-muted-foreground">Sistema • Base de datos • Hace 2 min</p>
              </div>
            </div>
          </div>
        </CardContent>
      </Card>
    </div>
  )
}
