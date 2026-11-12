"use client"

import { Plus } from "lucide-react"

import { Button } from "@/components/ui/button"
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuGroup,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu"
import { useTranslation } from "@/hooks/useTranslation"
import { QUESTION_BLOCK_TYPES, TEXT_BLOCK_TYPES } from "@/lib/flow-builder/limits"
import { keyFromLabel } from "@/lib/flow-builder/slug"
import type { FlowBlock } from "@/lib/flow-builder/types"

import { BlockTypeIcon } from "./BlockTypeIcon"

interface AddBlockMenuProps {
  /** Claves de todas las preguntas del formulario, para no repetir la del bloque nuevo. */
  existingKeys: string[]
  onAdd: (block: FlowBlock) => void
}

type Translate = (key: string) => string

function defaultBlock(type: FlowBlock["type"], existingKeys: string[], t: Translate): FlowBlock {
  const label = t(`flows.builder.defaults.${type}`)
  const key = keyFromLabel(label, existingKeys)

  switch (type) {
    case "heading":
    case "subheading":
    case "body":
    case "caption":
      return { type, text: label }
    case "short_text":
      return { type, key, label, input_type: "text", required: false, helper_text: null, prefill: null, maps_to: null }
    case "long_text":
      return { type, key, label, required: false, helper_text: null, prefill: null, maps_to: null }
    case "dropdown":
    case "single_choice":
    case "multiple_choice": {
      const first = t("flows.builder.defaults.option1")
      const second = t("flows.builder.defaults.option2")
      return {
        type,
        key,
        label,
        required: false,
        options: [
          { id: first, title: first },
          { id: second, title: second },
        ],
        maps_to: null,
      }
    }
    case "date":
      return { type, key, label, required: false, helper_text: null, maps_to: null }
    case "opt_in":
      return { type, key, label, required: false, maps_to: null }
  }
}

export function AddBlockMenu({ existingKeys, onAdd }: AddBlockMenuProps) {
  const { t } = useTranslation()

  const renderItem = (type: FlowBlock["type"]) => (
    <DropdownMenuItem key={type} onSelect={() => onAdd(defaultBlock(type, existingKeys, t))}>
      <BlockTypeIcon type={type} className="size-4 text-muted-foreground" />
      {t(`flows.builder.blockTypes.${type}`)}
    </DropdownMenuItem>
  )

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button variant="outline" size="sm" className="w-full border-dashed">
          <Plus className="size-4" />
          {t("flows.builder.addBlock")}
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="start" className="w-56">
        <DropdownMenuGroup>
          <DropdownMenuLabel className="text-xs text-muted-foreground">{t("flows.builder.blockGroups.text")}</DropdownMenuLabel>
          {TEXT_BLOCK_TYPES.map(renderItem)}
        </DropdownMenuGroup>
        <DropdownMenuSeparator />
        <DropdownMenuGroup>
          <DropdownMenuLabel className="text-xs text-muted-foreground">
            {t("flows.builder.blockGroups.questions")}
          </DropdownMenuLabel>
          {QUESTION_BLOCK_TYPES.map(renderItem)}
        </DropdownMenuGroup>
      </DropdownMenuContent>
    </DropdownMenu>
  )
}
