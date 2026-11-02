"use client"

import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Badge } from "@/components/ui/badge"
import { Bot, MessageSquare, Users, TrendingUp, Clock, Star } from "lucide-react"

export function BotAnalytics() {
  const stats = [
    {
      title: "Conversaciones de hoy",
      value: "47",
      change: "+12%",
      icon: MessageSquare,
      color: "text-blue-500",
    },
    {
      title: "Leads calificados",
      value: "23",
      change: "+8%",
      icon: Users,
      color: "text-green-500",
    },
    {
      title: "Tasa de conversión",
      value: "48.9%",
      change: "+5.2%",
      icon: TrendingUp,
      color: "text-purple-500",
    },
    {
      title: "Tiempo de respuesta",
      value: "1.2s",
      change: "-0.3s",
      icon: Clock,
      color: "text-orange-500",
    },
  ]

  const recentLeads = [
    { name: "María González", score: 85, time: "2 min", status: "hot" },
    { name: "Carlos Ruiz", score: 72, time: "5 min", status: "warm" },
    { name: "Ana López", score: 45, time: "12 min", status: "cold" },
    { name: "Pedro Martín", score: 91, time: "18 min", status: "hot" },
  ]

  const getStatusColor = (status: string) => {
    switch (status) {
      case "hot":
        return "bg-red-500"
      case "warm":
        return "bg-yellow-500"
      case "cold":
        return "bg-blue-500"
      default:
        return "bg-gray-500"
    }
  }

  return (
    <div className="space-y-6">
      {/* Stats Grid */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
        {stats.map((stat, index) => (
          <Card key={index}>
            <CardContent className="p-4">
              <div className="flex items-center justify-between">
                <div>
                  <p className="text-sm text-muted-foreground">{stat.title}</p>
                  <p className="text-2xl font-bold">{stat.value}</p>
                  <p className="text-xs text-success">{stat.change}</p>
                </div>
                <stat.icon className={`h-8 w-8 ${stat.color}`} />
              </div>
            </CardContent>
          </Card>
        ))}
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {/* Recent Qualified Leads */}
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <Star className="h-5 w-5 text-yellow-500" />
              Leads Recientes Calificados
            </CardTitle>
          </CardHeader>
          <CardContent>
            <div className="space-y-3">
              {recentLeads.map((lead, index) => (
                <div key={index} className="flex items-center justify-between p-3 rounded-lg border">
                  <div className="flex items-center gap-3">
                    <div className={`w-3 h-3 rounded-full ${getStatusColor(lead.status)}`}></div>
                    <div>
                      <p className="font-medium">{lead.name}</p>
                      <p className="text-sm text-muted-foreground">Hace {lead.time}</p>
                    </div>
                  </div>
                  <Badge variant="outline">{lead.score}% score</Badge>
                </div>
              ))}
            </div>
          </CardContent>
        </Card>

        {/* Bot Performance */}
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <Bot className="h-5 w-5 text-blue-500" />
              Rendimiento del Bot
            </CardTitle>
          </CardHeader>
          <CardContent>
            <div className="space-y-4">
              <div className="space-y-2">
                <div className="flex justify-between text-sm">
                  <span>Precisión de respuestas</span>
                  <span className="font-medium">94.2%</span>
                </div>
                <div className="w-full bg-muted rounded-full h-2">
                  <div className="bg-green-500 h-2 rounded-full" style={{ width: "94.2%" }}></div>
                </div>
              </div>

              <div className="space-y-2">
                <div className="flex justify-between text-sm">
                  <span>Satisfacción del usuario</span>
                  <span className="font-medium">4.7/5</span>
                </div>
                <div className="w-full bg-muted rounded-full h-2">
                  <div className="bg-blue-500 h-2 rounded-full" style={{ width: "94%" }}></div>
                </div>
              </div>

              <div className="space-y-2">
                <div className="flex justify-between text-sm">
                  <span>Leads calificados</span>
                  <span className="font-medium">48.9%</span>
                </div>
                <div className="w-full bg-muted rounded-full h-2">
                  <div className="bg-purple-500 h-2 rounded-full" style={{ width: "48.9%" }}></div>
                </div>
              </div>
            </div>
          </CardContent>
        </Card>
      </div>
    </div>
  )
}
