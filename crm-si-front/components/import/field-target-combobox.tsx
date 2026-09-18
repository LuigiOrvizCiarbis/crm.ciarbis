"use client"

import { useMemo, useState } from "react"
import { Check, ChevronsUpDown, PlusCircle } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { cn } from "@/lib/utils"

export type FieldTargetOption = { value: string; label: string }

const normalize = (value: string) => value.normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase().trim()

interface Props {
  value: string
  options: FieldTargetOption[]
  onChange: (value: string) => void
  className?: string
}

export function FieldTargetCombobox({ value, options, onChange, className }: Props) {
  const [open, setOpen] = useState(false)
  const [query, setQuery] = useState("")
  const selected = options.find((option) => option.value === value)
  const regular = options.filter((option) => option.value !== "ignore" && option.value !== "create")
  const filtered = useMemo(() => regular.filter((option) => normalize(option.label).includes(normalize(query))), [query, regular])
  const choose = (nextValue: string) => { onChange(nextValue); setQuery(""); setOpen(false) }

  return (
    <div className="relative">
      <Button type="button" variant="outline" role="combobox" aria-expanded={open} onClick={() => setOpen((current) => !current)} className={cn("h-8 w-52 justify-between font-normal", className)}>
        <span className={cn("truncate", !selected && "text-muted-foreground")}>{selected?.label || "Seleccionar destino"}</span>
        <ChevronsUpDown className="ml-2 h-3.5 w-3.5 shrink-0 opacity-50" />
      </Button>
      {open && <div className="absolute left-0 top-full z-[100] mt-1 w-64 rounded-md border bg-popover p-1 text-popover-foreground shadow-md">
        <Input autoFocus value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Buscar campo…" className="mb-1 h-8" />
        <div className="max-h-56 overflow-y-auto">
          <p className="px-2 py-1 text-xs font-medium text-muted-foreground">Campos</p>
          {filtered.length === 0 ? <p className="px-2 py-4 text-center text-sm text-muted-foreground">No se encontraron campos</p> : filtered.map((option) => <button key={option.value} type="button" onClick={() => choose(option.value)} className="flex w-full items-center gap-2 rounded-sm px-2 py-1.5 text-left text-sm hover:bg-accent"><Check className={cn("h-4 w-4", value === option.value ? "opacity-100" : "opacity-0")} /><span className="truncate">{option.label}</span></button>)}
          <p className="mt-1 px-2 py-1 text-xs font-medium text-muted-foreground">Acciones</p>
          <button type="button" onClick={() => choose("ignore")} className="flex w-full items-center gap-2 rounded-sm px-2 py-1.5 text-left text-sm hover:bg-accent"><Check className={cn("h-4 w-4", value === "ignore" ? "opacity-100" : "opacity-0")} />Ignorar</button>
          <button type="button" onClick={() => choose("create")} className="flex w-full items-center gap-2 rounded-sm px-2 py-1.5 text-left text-sm text-primary hover:bg-accent"><PlusCircle className="h-4 w-4" />Crear campo</button>
        </div>
      </div>}
    </div>
  )
}
