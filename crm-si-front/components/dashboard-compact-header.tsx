"use client"

import { useEffect, useState } from "react"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { useIsOwner, usePermission } from "@/hooks/usePermission"
import { useTranslation } from "@/hooks/useTranslation"
import { cn } from "@/lib/utils"
import { getChannels } from "@/lib/api/channels"
import type { DashboardPeriodo } from "@/lib/api/dashboard"
import { getPipelines, type Pipeline } from "@/lib/api/pipeline"
import { fetchUsers, type SystemUser } from "@/lib/api/users"
import type { Channel } from "@/data/types"
import { useBranchesStore } from "@/store/useBranchesStore"

/** Valor de los selects que significa "sin filtro"; el backend lo ignora igual que un vacío. */
export const ALL = "todos"
/** El embudo principal, sin conocer su id todavía: el backend lo resuelve. */
export const DEFAULT_PIPELINE = "default"

interface DashboardCompactHeaderProps {
  periodo: DashboardPeriodo
  onPeriodoChange: (value: DashboardPeriodo) => void
  canal: string
  onCanalChange: (value: string) => void
  owner: string
  onOwnerChange: (value: string) => void
  pipeline: string
  onPipelineChange: (value: string) => void
  sucursal: string
  onSucursalChange: (value: string) => void
}

const triggerClass = "h-9 w-full text-sm sm:w-auto sm:min-w-[9.5rem]"

export function DashboardCompactHeader({ periodo, onPeriodoChange, canal, onCanalChange, owner, onOwnerChange, pipeline, onPipelineChange, sucursal, onSucursalChange }: DashboardCompactHeaderProps) {
  const { t } = useTranslation()
  const canViewTeam = usePermission("analytics.view_team")
  const isOwner = useIsOwner()
  const canViewAllBranchesPermission = usePermission("branches.view_all")
  // Quien está atado a una sucursal ya ve sólo la suya: el filtro es para quien ve todas.
  const canViewAllBranches = isOwner || canViewAllBranchesPermission
  const { branches, loaded: branchesLoaded, fetch: fetchBranches } = useBranchesStore()
  const [channels, setChannels] = useState<Channel[]>([])
  const [team, setTeam] = useState<SystemUser[]>([])
  const [pipelines, setPipelines] = useState<Pipeline[]>([])

  // Los filtros son opcionales: si la lista no carga, el control no aparece y el Panel sigue con
  // "todos". El backend sólo acepta IDs numéricos, así que no hay opciones fijas posibles.
  useEffect(() => {
    let cancelled = false
    getChannels().then((items) => { if (!cancelled) setChannels(items) }).catch(() => {})
    return () => { cancelled = true }
  }, [])

  useEffect(() => {
    let cancelled = false
    getPipelines().then((items) => { if (!cancelled) setPipelines(items) }).catch(() => {})
    return () => { cancelled = true }
  }, [])

  useEffect(() => {
    if (!canViewTeam) return
    let cancelled = false
    fetchUsers().then((items) => { if (!cancelled) setTeam(items) }).catch(() => {})
    return () => { cancelled = true }
  }, [canViewTeam])

  useEffect(() => {
    if (canViewAllBranches && !branchesLoaded) fetchBranches()
  }, [canViewAllBranches, branchesLoaded, fetchBranches])

  const showBranch = canViewAllBranches && branches.length > 1
  const showPipeline = pipelines.length > 1
  const showChannel = channels.length > 1
  const showOwner = canViewTeam && team.length > 1
  const filterCount = 1 + Number(showBranch) + Number(showPipeline) + Number(showChannel) + Number(showOwner)
  // En el teléfono van de a dos por fila; con una cantidad impar, el primero ocupa la fila entera.
  const first = showBranch ? "branch" : showPipeline ? "pipeline" : "periodo"
  const spanIfFirst = (key: typeof first) => filterCount > 1 && filterCount % 2 === 1 && first === key && "col-span-2"
  // "default" no es una opción del menú: se muestra como el embudo principal.
  const pipelineValue = pipeline === DEFAULT_PIPELINE ? String(pipelines.find((item) => item.is_default)?.id ?? "") : pipeline

  return (
    <header className="border-b border-border bg-background">
      <div className="mx-auto flex w-full max-w-[1400px] flex-col gap-4 px-4 py-5 md:px-6 lg:flex-row lg:items-end lg:justify-between lg:px-8">
        <h1 className="text-2xl font-semibold tracking-tight">{t("dashboard.title")}</h1>

        {/* De lo más amplio a lo más fino: sucursal, embudo, período, canal, vendedor. */}
        <div className="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap sm:items-center">
          {showBranch && (
            <Select value={sucursal} onValueChange={onSucursalChange}>
              <SelectTrigger className={cn(triggerClass, spanIfFirst("branch"))} aria-label={t("dashboard.filters.branch")}>
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value={ALL}>{t("dashboard.filters.allBranches")}</SelectItem>
                {branches.map((item) => (
                  <SelectItem key={item.id} value={String(item.id)}>{item.name}</SelectItem>
                ))}
              </SelectContent>
            </Select>
          )}

          {showPipeline && (
            <Select value={pipelineValue} onValueChange={onPipelineChange}>
              <SelectTrigger className={cn(triggerClass, spanIfFirst("pipeline"))} aria-label={t("dashboard.filters.pipeline")}>
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value={ALL}>{t("dashboard.filters.allPipelines")}</SelectItem>
                {pipelines.map((item) => (
                  <SelectItem key={item.id} value={String(item.id)}>{item.name}</SelectItem>
                ))}
              </SelectContent>
            </Select>
          )}

          <Select value={periodo} onValueChange={(value) => onPeriodoChange(value as DashboardPeriodo)}>
            <SelectTrigger
              className={cn(triggerClass, spanIfFirst("periodo"))}
              aria-label={t("dashboard.filters.period")}
            >
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="hoy">{t("dashboard.filters.today")}</SelectItem>
              <SelectItem value="esta-semana">{t("dashboard.filters.thisWeek")}</SelectItem>
              <SelectItem value="este-mes">{t("dashboard.filters.thisMonth")}</SelectItem>
              <SelectItem value="este-trimestre">{t("dashboard.filters.thisQuarter")}</SelectItem>
              <SelectItem value="este-ano">{t("dashboard.filters.thisYear")}</SelectItem>
            </SelectContent>
          </Select>

          {showChannel && (
            <Select value={canal} onValueChange={onCanalChange}>
              <SelectTrigger className={triggerClass} aria-label={t("dashboard.filters.channel")}>
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value={ALL}>{t("dashboard.filters.allChannels")}</SelectItem>
                {channels.map((channel) => (
                  <SelectItem key={channel.id} value={String(channel.id)}>{channel.name}</SelectItem>
                ))}
              </SelectContent>
            </Select>
          )}

          {showOwner && (
            <Select value={owner} onValueChange={onOwnerChange}>
              <SelectTrigger className={triggerClass} aria-label={t("dashboard.filters.salesperson")}>
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value={ALL}>{t("dashboard.filters.allTeam")}</SelectItem>
                {team.map((user) => (
                  <SelectItem key={user.id} value={String(user.id)}>{user.name}</SelectItem>
                ))}
              </SelectContent>
            </Select>
          )}
        </div>
      </div>
    </header>
  )
}
