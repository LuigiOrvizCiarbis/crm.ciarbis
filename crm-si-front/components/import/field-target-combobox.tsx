"use client"

import { useState } from "react"
import { Check, ChevronsUpDown, PlusCircle } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from "@/components/ui/command"
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover"
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
  const selected = options.find((option) => option.value === value)
  const regular = options.filter((option) => option.value !== "ignore" && option.value !== "create")

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button type="button" variant="outline" role="combobox" aria-expanded={open} className={cn("h-8 w-52 justify-between font-normal", className)}>
          <span className={cn("truncate", !selected && "text-muted-foreground")}>{selected?.label || "Seleccionar destino"}</span>
          <ChevronsUpDown className="ml-2 h-3.5 w-3.5 shrink-0 opacity-50" />
        </Button>
      </PopoverTrigger>
      <PopoverContent align="start" className="w-64 p-0">
        <Command filter={(optionValue, search) => normalize(optionValue).includes(normalize(search)) ? 1 : 0}>
          <CommandInput placeholder="Buscar campo…" />
          <CommandList>
            <CommandEmpty>No se encontraron campos</CommandEmpty>
            <CommandGroup heading="Campos">
              {regular.map((option) => (
                <CommandItem key={option.value} value={option.label} onSelect={() => { onChange(option.value); setOpen(false) }}>
                  <Check className={cn("mr-2 h-4 w-4", value === option.value ? "opacity-100" : "opacity-0")} />
                  <span className="truncate">{option.label}</span>
                </CommandItem>
              ))}
            </CommandGroup>
            <CommandGroup heading="Acciones">
              <CommandItem value="Ignorar" onSelect={() => { onChange("ignore"); setOpen(false) }}>
                <Check className={cn("mr-2 h-4 w-4", value === "ignore" ? "opacity-100" : "opacity-0")} />
                Ignorar
              </CommandItem>
              <CommandItem value="Crear campo" onSelect={() => { onChange("create"); setOpen(false) }}>
                <PlusCircle className="mr-2 h-4 w-4 text-primary" />
                Crear campo
              </CommandItem>
            </CommandGroup>
          </CommandList>
        </Command>
      </PopoverContent>
    </Popover>
  )
}
