import type React from "react"
import { cn } from "@/lib/utils"
import { CheckCircle, Clock, AlertTriangle, XCircle, User } from "lucide-react"

export interface BadgeProps {
  variant: "success" | "warning" | "error" | "info" | "default"
  size?: "sm" | "md" | "lg"
  children: React.ReactNode
  className?: string
  icon?: boolean
}

export function Badge({ variant, size = "md", children, className, icon = false }: BadgeProps) {
  const baseClasses = "inline-flex items-center gap-1 font-medium rounded-full border"

  const sizeClasses = {
    sm: "px-2 py-0.5 text-xs",
    md: "px-2.5 py-1 text-xs",
    lg: "px-3 py-1.5 text-sm",
  }

  const variantClasses = {
    success: "bg-success/10 text-success border-success/30",
    warning: "bg-warning/10 text-warning border-warning/30",
    error: "bg-destructive/10 text-destructive border-destructive/30",
    info: "bg-info/10 text-info border-info/30",
    default: "bg-muted text-muted-foreground border-border",
  }

  const icons = {
    success: CheckCircle,
    warning: AlertTriangle,
    error: XCircle,
    info: Clock,
    default: User,
  }

  const Icon = icons[variant]

  return (
    <span className={cn(baseClasses, sizeClasses[size], variantClasses[variant], className)}>
      {icon && <Icon className="w-3 h-3" />}
      {children}
    </span>
  )
}

export function StatusBadge({
  status,
  className,
}: { status: "nuevo" | "en_curso" | "ganado" | "perdido" | "pagado" | "pendiente" | "vencido"; className?: string }) {
  const statusConfig = {
    nuevo: { variant: "info" as const, label: "Nuevo", icon: true },
    en_curso: { variant: "warning" as const, label: "En curso", icon: true },
    ganado: { variant: "success" as const, label: "Ganado", icon: true },
    perdido: { variant: "error" as const, label: "Perdido", icon: true },
    pagado: { variant: "success" as const, label: "Pagado", icon: true },
    pendiente: { variant: "warning" as const, label: "Pendiente", icon: true },
    vencido: { variant: "error" as const, label: "Vencido", icon: true },
  }

  const config = statusConfig[status]

  return (
    <Badge variant={config.variant} icon={config.icon} className={className}>
      {config.label}
    </Badge>
  )
}

export function LeadScoreBadge({ score, className, ...props }: { score: number; className?: string; title?: string }) {
  const getVariant = (score: number) => {
    if (score >= 80) return "success"
    if (score >= 60) return "warning"
    if (score >= 40) return "info"
    return "error"
  }

  const getScoreStyles = (score: number) => {
    if (score >= 80) return "border-green-500/50 text-green-600 dark:text-green-400 bg-green-500/10"
    if (score >= 60) return "border-orange-500/50 text-orange-600 dark:text-orange-400 bg-orange-500/10"
    if (score >= 40) return "border-blue-500/50 text-blue-600 dark:text-blue-400 bg-blue-500/10"
    return "border-red-500/50 text-red-600 dark:text-red-400 bg-red-500/10"
  }

  return (
    <span
      className={cn(
        "inline-flex items-center justify-center w-8 h-8 rounded-full border-2 text-xs font-bold",
        getScoreStyles(score),
        className,
      )}
      {...props}
    >
      {score}
    </span>
  )
}

export function RiskBadge({ risk, className }: { risk: "bajo" | "medio" | "alto"; className?: string }) {
  const riskConfig = {
    bajo: { variant: "success" as const, label: "Bajo" },
    medio: { variant: "warning" as const, label: "Medio" },
    alto: { variant: "error" as const, label: "Alto" },
  }

  const config = riskConfig[risk]

  return (
    <Badge variant={config.variant} icon className={className}>
      {config.label}
    </Badge>
  )
}
