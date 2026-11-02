"use client"

import { SidebarLayout } from "@/components/SidebarLayout"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Button } from "@/components/ui/button"
import { Badge } from "@/components/Badges"
import { useToast } from "@/components/Toast"
import { AdminHealthDashboard } from "@/components/AdminHealthDashboard"
import { PaymentModal } from "@/components/PaymentModal"
import {
  Shield,
  Users,
  MessageSquare,
  TrendingUp,
  Database,
  Zap,
  AlertTriangle,
  CheckCircle,
  Clock,
  DollarSign,
  Settings,
  Activity,
  Server,
  Wifi,
  HardDrive,
  Cpu,
  BarChart3,
} from "lucide-react"
import { useState } from "react"

export default function AdminPage() {
  const { addToast } = useToast()
  const [paymentModalOpen, setPaymentModalOpen] = useState(false)

  const handleUpgrade = () => {
    setPaymentModalOpen(true)
  }

  const handleSystemAction = (action: string) => {
    addToast({
      type: "success",
      title: "Acción ejecutada",
      description: `${action} completado correctamente`,
    })
  }

  const systemStats = [
    {
      title: "Usuarios activos",
      value: "1,247",
      change: "+12%",
      icon: Users,
      color: "text-blue-500",
    },
    {
      title: "Mensajes procesados",
      value: "45,231",
      change: "+8%",
      icon: MessageSquare,
      color: "text-green-500",
    },
    {
      title: "Conversiones",
      value: "892",
      change: "+23%",
      icon: TrendingUp,
      color: "text-purple-500",
    },
    {
      title: "Ingresos MRR",
      value: "$12,450",
      change: "+15%",
      icon: DollarSign,
      color: "text-orange-500",
    },
  ]

  const systemHealth = [
    {
      service: "WhatsApp API",
      status: "operational",
      uptime: "99.9%",
      lastCheck: "Hace 2 min",
    },
    {
      service: "Instagram API",
      status: "operational",
      uptime: "99.8%",
      lastCheck: "Hace 1 min",
    },
    {
      service: "Database",
      status: "operational",
      uptime: "100%",
      lastCheck: "Hace 30 seg",
    },
    {
      service: "AI Processing",
      status: "degraded",
      uptime: "97.2%",
      lastCheck: "Hace 5 min",
    },
    {
      service: "Email Service",
      status: "maintenance",
      uptime: "95.1%",
      lastCheck: "Hace 10 min",
    },
  ]

  const getStatusIcon = (status: string) => {
    switch (status) {
      case "operational":
        return <CheckCircle className="w-4 h-4 text-success" />
      case "degraded":
        return <AlertTriangle className="w-4 h-4 text-warning" />
      case "maintenance":
        return <Clock className="w-4 h-4 text-info" />
      default:
        return <AlertTriangle className="w-4 h-4 text-destructive" />
    }
  }

  const getStatusBadge = (status: string) => {
    switch (status) {
      case "operational":
        return <Badge variant="success">Operativo</Badge>
      case "degraded":
        return <Badge variant="warning">Degradado</Badge>
      case "maintenance":
        return <Badge variant="info">Mantenimiento</Badge>
      default:
        return <Badge variant="error">Error</Badge>
    }
  }

  return (
    <SidebarLayout>
      <div className="space-y-6 p-6">
        {/* Header */}
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-3xl font-bold flex items-center gap-2">
              <Shield className="w-8 h-8 text-primary" />
              Panel de administración
            </h1>
            <p className="text-muted-foreground">Monitoreo y gestión del sistema</p>
          </div>
          <div className="flex gap-2">
            <Button variant="outline" onClick={() => handleSystemAction("Backup creado")}>
              <Database className="w-4 h-4 mr-2" />
              Crear backup
            </Button>
            <Button onClick={handleUpgrade}>
              <Zap className="w-4 h-4 mr-2" />
              Mejorar plan
            </Button>
          </div>
        </div>

        {/* System Stats */}
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
          {systemStats.map((stat, index) => {
            const Icon = stat.icon
            return (
              <Card key={index}>
                <CardContent className="p-6">
                  <div className="flex items-center justify-between">
                    <div>
                      <p className="text-sm font-medium text-muted-foreground">{stat.title}</p>
                      <div className="flex items-center gap-2 mt-2">
                        <p className="text-2xl font-bold">{stat.value}</p>
                        <Badge variant="success" className="text-xs">
                          {stat.change}
                        </Badge>
                      </div>
                    </div>
                    <Icon className={`w-8 h-8 ${stat.color}`} />
                  </div>
                </CardContent>
              </Card>
            )
          })}
        </div>

        {/* Health Dashboard */}
        <AdminHealthDashboard />

        {/* System Services Status */}
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <Activity className="w-5 h-5" />
              Estado de servicios
            </CardTitle>
          </CardHeader>
          <CardContent>
            <div className="space-y-4">
              {systemHealth.map((service, index) => (
                <div key={index} className="flex items-center justify-between p-4 border rounded-lg">
                  <div className="flex items-center gap-3">
                    {getStatusIcon(service.status)}
                    <div>
                      <h4 className="font-medium">{service.service}</h4>
                      <p className="text-sm text-muted-foreground">
                        Uptime: {service.uptime} • {service.lastCheck}
                      </p>
                    </div>
                  </div>
                  <div className="flex items-center gap-2">
                    {getStatusBadge(service.status)}
                    <Button variant="ghost" size="sm" aria-label={`Configurar ${service.service}`}>
                      <Settings className="w-4 h-4" aria-hidden="true" />
                    </Button>
                  </div>
                </div>
              ))}
            </div>
          </CardContent>
        </Card>

        {/* System Resources */}
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
          <Card>
            <CardContent className="p-6">
              <div className="flex items-center justify-between">
                <div>
                  <p className="text-sm font-medium text-muted-foreground">Uso de CPU</p>
                  <p className="text-2xl font-bold">45%</p>
                  <div className="w-full bg-muted rounded-full h-2 mt-2">
                    <div className="bg-blue-500 h-2 rounded-full" style={{ width: "45%" }} />
                  </div>
                </div>
                <Cpu className="w-8 h-8 text-blue-500" />
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardContent className="p-6">
              <div className="flex items-center justify-between">
                <div>
                  <p className="text-sm font-medium text-muted-foreground">Memoria</p>
                  <p className="text-2xl font-bold">68%</p>
                  <div className="w-full bg-muted rounded-full h-2 mt-2">
                    <div className="bg-green-500 h-2 rounded-full" style={{ width: "68%" }} />
                  </div>
                </div>
                <HardDrive className="w-8 h-8 text-green-500" />
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardContent className="p-6">
              <div className="flex items-center justify-between">
                <div>
                  <p className="text-sm font-medium text-muted-foreground">Almacenamiento</p>
                  <p className="text-2xl font-bold">23%</p>
                  <div className="w-full bg-muted rounded-full h-2 mt-2">
                    <div className="bg-purple-500 h-2 rounded-full" style={{ width: "23%" }} />
                  </div>
                </div>
                <Server className="w-8 h-8 text-purple-500" />
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardContent className="p-6">
              <div className="flex items-center justify-between">
                <div>
                  <p className="text-sm font-medium text-muted-foreground">Red</p>
                  <p className="text-2xl font-bold">12MB/s</p>
                  <div className="w-full bg-muted rounded-full h-2 mt-2">
                    <div className="bg-orange-500 h-2 rounded-full" style={{ width: "60%" }} />
                  </div>
                </div>
                <Wifi className="w-8 h-8 text-orange-500" />
              </div>
            </CardContent>
          </Card>
        </div>

        {/* Quick Actions */}
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <Zap className="w-5 h-5" />
              Acciones rápidas
            </CardTitle>
          </CardHeader>
          <CardContent>
            <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
              <Button
                variant="outline"
                className="h-20 flex-col gap-2 bg-transparent"
                onClick={() => handleSystemAction("Caché limpiada")}
              >
                <Database className="w-6 h-6" />
                Limpiar caché
              </Button>
              <Button
                variant="outline"
                className="h-20 flex-col gap-2 bg-transparent"
                onClick={() => handleSystemAction("Logs exportados")}
              >
                <BarChart3 className="w-6 h-6" />
                Exportar logs
              </Button>
              <Button
                variant="outline"
                className="h-20 flex-col gap-2 bg-transparent"
                onClick={() => handleSystemAction("Sistema reiniciado")}
              >
                <Activity className="w-6 h-6" />
                Reiniciar sistema
              </Button>
              <Button
                variant="outline"
                className="h-20 flex-col gap-2 bg-transparent"
                onClick={() => handleSystemAction("Mantenimiento programado")}
              >
                <Settings className="w-6 h-6" />
                Mantenimiento
              </Button>
            </div>
          </CardContent>
        </Card>
      </div>

      {/* Payment Modal */}
      <PaymentModal open={paymentModalOpen} onOpenChange={setPaymentModalOpen} />
    </SidebarLayout>
  )
}
